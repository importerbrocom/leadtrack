package com.agency.leadmanager.ui.settings

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.provider.Settings
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.agency.leadmanager.BuildConfig
import com.agency.leadmanager.ServiceLocator
import com.agency.leadmanager.data.ApiResult
import com.agency.leadmanager.data.prefs.StoredUser
import com.agency.leadmanager.sync.SyncScheduler
import com.agency.leadmanager.ui.appViewModel
import com.agency.leadmanager.ui.components.DetailRow
import com.agency.leadmanager.ui.components.SectionCard
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class SettingsUiState(
    val user: StoredUser? = null,
    val serverUrl: String = "",
    val callTrackingEnabled: Boolean = true,
    val pendingCalls: Int = 0,
    val message: String? = null,
    val signedOut: Boolean = false,
    val busy: Boolean = false,
)

class SettingsViewModel(private val locator: ServiceLocator) : ViewModel() {

    private val _state = MutableStateFlow(SettingsUiState())
    val state = _state.asStateFlow()

    init {
        viewModelScope.launch {
            _state.update {
                it.copy(
                    user = locator.session.user(),
                    serverUrl = locator.session.baseUrl(),
                    callTrackingEnabled = locator.session.callTrackingEnabled(),
                    pendingCalls = locator.callRepository.pendingCount(),
                )
            }
        }
    }

    fun setCallTracking(enabled: Boolean) {
        _state.update { it.copy(callTrackingEnabled = enabled) }

        viewModelScope.launch {
            locator.session.setCallTrackingEnabled(enabled)
            _state.update {
                it.copy(
                    message = if (enabled) {
                        "Call tracking on"
                    } else {
                        "Call tracking paused - calls will not be logged"
                    }
                )
            }
        }
    }

    fun syncNow(context: android.content.Context) {
        _state.update { it.copy(busy = true) }

        viewModelScope.launch {
            val sent = locator.callRepository.syncPending()
            locator.leadRepository.flushPendingLeads()
            locator.leadRepository.flushPendingStatusUpdates()

            SyncScheduler.catchUpNow(context)

            _state.update {
                it.copy(
                    busy = false,
                    pendingCalls = locator.callRepository.pendingCount(),
                    message = when {
                        sent < 0 -> "No connection. Will retry automatically."
                        sent == 0 -> "Everything is already up to date"
                        else -> "Sent $sent call(s) to the office"
                    },
                )
            }
        }
    }

    fun changePassword(current: String, new: String) {
        _state.update { it.copy(busy = true) }

        viewModelScope.launch {
            val result = locator.authRepository.changePassword(current, new)

            _state.update {
                it.copy(
                    busy = false,
                    message = when (result) {
                        is ApiResult.Success -> "Password changed"
                        is ApiResult.Failure -> result.message
                    }
                )
            }
        }
    }

    fun signOut() {
        _state.update { it.copy(busy = true) }

        viewModelScope.launch {
            locator.authRepository.logout()
            _state.update { it.copy(busy = false, signedOut = true) }
        }
    }

    fun consumeMessage() = _state.update { it.copy(message = null) }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SettingsScreen(
    onBack: () -> Unit,
    onSignedOut: () -> Unit,
    onOpenDiagnostics: () -> Unit = {},
) {
    val vm: SettingsViewModel = appViewModel { SettingsViewModel(it) }
    val state by vm.state.collectAsState()
    val context = LocalContext.current
    val snackbar = remember { SnackbarHostState() }

    var showPasswordDialog by remember { mutableStateOf(false) }
    var showSignOutDialog by remember { mutableStateOf(false) }

    val callLogGranted = ContextCompat.checkSelfPermission(
        context, Manifest.permission.READ_CALL_LOG
    ) == PackageManager.PERMISSION_GRANTED

    LaunchedEffect(state.message) {
        state.message?.let {
            snackbar.showSnackbar(it)
            vm.consumeMessage()
        }
    }

    LaunchedEffect(state.signedOut) {
        if (state.signedOut) onSignedOut()
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Settings") },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
            )
        },
        snackbarHost = { SnackbarHost(snackbar) },
    ) { padding ->
        Column(
            Modifier
                .padding(padding)
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            SectionCard(title = "Account") {
                DetailRow("Name", state.user?.name)
                DetailRow("Phone", state.user?.phone)
                DetailRow(
                    "Role",
                    when {
                        state.user?.isAdmin == true -> "Head office"
                        state.user?.isPartner == true -> "Partner"
                        else -> "Telecaller"
                    }
                )
                state.user?.agencyName?.let { DetailRow("Agency", it) }

                Spacer(Modifier.height(10.dp))

                OutlinedButton(
                    onClick = { showPasswordDialog = true },
                    modifier = Modifier.fillMaxWidth(),
                ) { Text("Change password") }
            }

            SectionCard(title = "Automatic call tracking") {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text("Log my calls", style = MaterialTheme.typography.bodyMedium)
                        Text(
                            "Records the number and how long you spoke, then asks you to " +
                                "set the outcome",
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                    Switch(
                        checked = state.callTrackingEnabled,
                        onCheckedChange = { vm.setCallTracking(it) },
                    )
                }

                if (!callLogGranted) {
                    Spacer(Modifier.height(12.dp))
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(
                            Icons.Default.Warning,
                            contentDescription = null,
                            tint = MaterialTheme.colorScheme.error,
                            modifier = Modifier.size(18.dp),
                        )
                        Spacer(Modifier.size(8.dp))
                        Text(
                            "Call log permission is off, so durations cannot be recorded.",
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.error,
                        )
                    }
                    Spacer(Modifier.height(8.dp))
                    OutlinedButton(
                        onClick = {
                            context.startActivity(
                                Intent(
                                    Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                                    Uri.fromParts("package", context.packageName, null)
                                )
                            )
                        },
                        modifier = Modifier.fillMaxWidth(),
                    ) { Text("Open app permissions") }
                }
            }

            SectionCard(title = "Sync") {
                DetailRow("Waiting to send", "${state.pendingCalls} call(s)")
                Spacer(Modifier.height(10.dp))
                OutlinedButton(
                    onClick = { vm.syncNow(context) },
                    enabled = !state.busy,
                    modifier = Modifier.fillMaxWidth(),
                ) { Text(if (state.busy) "Syncing…" else "Sync now") }
            }

            SectionCard(title = "Calls not being recorded?") {
                Text(
                    "Checks every setting this phone needs and names the one that is wrong. " +
                        "Start here if calls are not reaching the office.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Spacer(Modifier.height(10.dp))
                Button(
                    onClick = onOpenDiagnostics,
                    modifier = Modifier.fillMaxWidth(),
                ) { Text("Run the check") }
            }

            SectionCard(title = "About") {
                DetailRow("App version", BuildConfig.VERSION_NAME)
                DetailRow("Server", state.serverUrl)
            }

            Button(
                onClick = { showSignOutDialog = true },
                colors = ButtonDefaults.buttonColors(
                    containerColor = MaterialTheme.colorScheme.error,
                ),
                modifier = Modifier.fillMaxWidth(),
            ) { Text("Sign out") }

            Spacer(Modifier.height(24.dp))
        }
    }

    if (showPasswordDialog) {
        var current by remember { mutableStateOf("") }
        var next by remember { mutableStateOf("") }

        AlertDialog(
            onDismissRequest = { showPasswordDialog = false },
            title = { Text("Change password") },
            text = {
                Column {
                    OutlinedTextField(
                        value = current,
                        onValueChange = { current = it },
                        label = { Text("Current password") },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )
                    Spacer(Modifier.height(8.dp))
                    OutlinedTextField(
                        value = next,
                        onValueChange = { next = it },
                        label = { Text("New password") },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )
                }
            },
            confirmButton = {
                Button(
                    onClick = {
                        vm.changePassword(current, next)
                        showPasswordDialog = false
                    }
                ) { Text("Change") }
            },
            dismissButton = {
                TextButton(onClick = { showPasswordDialog = false }) { Text("Cancel") }
            },
        )
    }

    if (showSignOutDialog) {
        AlertDialog(
            onDismissRequest = { showSignOutDialog = false },
            title = { Text("Sign out?") },
            text = {
                Text(
                    if (state.pendingCalls > 0) {
                        "${state.pendingCalls} call(s) have not reached the office yet. " +
                            "They are kept on this phone and will be sent when someone signs in again. " +
                            "Sync first if you can."
                    } else {
                        "You will need your phone number and password to sign back in."
                    }
                )
            },
            confirmButton = {
                Button(
                    onClick = {
                        vm.signOut()
                        showSignOutDialog = false
                    },
                    colors = ButtonDefaults.buttonColors(
                        containerColor = MaterialTheme.colorScheme.error,
                    ),
                ) { Text("Sign out") }
            },
            dismissButton = {
                TextButton(onClick = { showSignOutDialog = false }) { Text("Cancel") }
            },
        )
    }
}
