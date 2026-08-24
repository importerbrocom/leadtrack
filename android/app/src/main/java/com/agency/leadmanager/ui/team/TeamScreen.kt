package com.agency.leadmanager.ui.team

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Groups
import androidx.compose.material.icons.filled.PersonAdd
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.agency.leadmanager.ServiceLocator
import com.agency.leadmanager.data.ApiResult
import com.agency.leadmanager.data.remote.dto.UserDto
import com.agency.leadmanager.ui.appViewModel
import com.agency.leadmanager.ui.components.EmptyState
import com.agency.leadmanager.ui.components.SectionCard
import com.agency.leadmanager.util.DateUtils
import com.agency.leadmanager.util.PhoneUtils
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class TeamUiState(
    val loading: Boolean = true,
    val members: List<UserDto> = emptyList(),
    val saving: Boolean = false,
    val message: String? = null,
    val error: String? = null,
)

class TeamViewModel(private val locator: ServiceLocator) : ViewModel() {

    private val _state = MutableStateFlow(TeamUiState())
    val state = _state.asStateFlow()

    init {
        load()
    }

    fun load() {
        _state.update { it.copy(loading = true, error = null) }

        viewModelScope.launch {
            when (val result = locator.teamRepository.team()) {
                is ApiResult.Success ->
                    _state.update { it.copy(loading = false, members = result.data) }

                is ApiResult.Failure ->
                    _state.update { it.copy(loading = false, error = result.message) }
            }
        }
    }

    fun addTelecaller(name: String, phone: String, password: String, city: String) {
        if (name.isBlank()) {
            _state.update { it.copy(message = "Enter a name") }
            return
        }

        if (!PhoneUtils.isValid(phone)) {
            _state.update { it.copy(message = "Enter a valid 10-digit phone number") }
            return
        }

        if (password.length < 6) {
            _state.update { it.copy(message = "Password must be at least 6 characters") }
            return
        }

        _state.update { it.copy(saving = true) }

        viewModelScope.launch {
            val result = locator.teamRepository.addTelecaller(
                name = name,
                phone = phone,
                password = password,
                city = city,
            )

            when (result) {
                is ApiResult.Success -> {
                    _state.update {
                        it.copy(saving = false, message = "${result.data.name} can now sign in")
                    }
                    load()
                }

                is ApiResult.Failure ->
                    _state.update { it.copy(saving = false, message = result.message) }
            }
        }
    }

    fun setActive(user: UserDto, active: Boolean) {
        viewModelScope.launch {
            val result = locator.teamRepository.setActive(user.id, active)

            _state.update {
                it.copy(
                    message = when (result) {
                        is ApiResult.Success ->
                            if (active) "${user.name} reactivated" else "${user.name} deactivated"
                        is ApiResult.Failure -> result.message
                    }
                )
            }

            load()
        }
    }

    fun consumeMessage() = _state.update { it.copy(message = null) }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun TeamScreen(onBack: () -> Unit) {
    val vm: TeamViewModel = appViewModel { TeamViewModel(it) }
    val state by vm.state.collectAsState()
    val snackbar = remember { SnackbarHostState() }

    var showAddDialog by remember { mutableStateOf(false) }

    LaunchedEffect(state.message) {
        state.message?.let {
            snackbar.showSnackbar(it)
            vm.consumeMessage()
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("My team") },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
            )
        },
        floatingActionButton = {
            FloatingActionButton(onClick = { showAddDialog = true }) {
                Icon(Icons.Default.PersonAdd, contentDescription = "Add telecaller")
            }
        },
        snackbarHost = { SnackbarHost(snackbar) },
    ) { padding ->
        Column(Modifier.padding(padding).fillMaxSize()) {
            when {
                state.loading && state.members.isEmpty() ->
                    Column(
                        Modifier.fillMaxSize(),
                        horizontalAlignment = Alignment.CenterHorizontally,
                        verticalArrangement = Arrangement.Center,
                    ) { CircularProgressIndicator() }

                state.members.isEmpty() ->
                    EmptyState(
                        title = "No telecallers yet",
                        subtitle = state.error ?: "Tap + to add your first telecaller",
                        icon = Icons.Default.Groups,
                    )

                else -> LazyColumn(
                    contentPadding = PaddingValues(16.dp),
                    verticalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    items(state.members, key = { it.id }) { member ->
                        SectionCard {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Column(Modifier.weight(1f)) {
                                    Text(
                                        member.name,
                                        style = MaterialTheme.typography.bodyLarge,
                                        fontWeight = FontWeight.Medium,
                                    )
                                    Text(
                                        PhoneUtils.formatForDisplay(member.phone),
                                        style = MaterialTheme.typography.bodySmall,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    )
                                    Text(
                                        buildString {
                                            append("${member.assignedLeads ?: 0} leads")
                                            member.lastLoginAt?.let {
                                                append(" · last seen ${DateUtils.relative(it)}")
                                            }
                                        },
                                        style = MaterialTheme.typography.labelSmall,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    )
                                }

                                Switch(
                                    checked = member.isActive,
                                    onCheckedChange = { vm.setActive(member, it) },
                                )
                            }
                        }
                    }

                    item { Spacer(Modifier.height(64.dp)) }
                }
            }
        }
    }

    if (showAddDialog) {
        AddTelecallerDialog(
            saving = state.saving,
            onDismiss = { showAddDialog = false },
            onConfirm = { name, phone, password, city ->
                vm.addTelecaller(name, phone, password, city)
                showAddDialog = false
            },
        )
    }
}

@Composable
private fun AddTelecallerDialog(
    saving: Boolean,
    onDismiss: () -> Unit,
    onConfirm: (String, String, String, String) -> Unit,
) {
    var name by remember { mutableStateOf("") }
    var phone by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var city by remember { mutableStateOf("") }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Add telecaller") },
        text = {
            Column {
                Text(
                    "They will sign in to this app with the phone number and password you set here.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )

                Spacer(Modifier.height(12.dp))

                OutlinedTextField(
                    value = name,
                    onValueChange = { name = it },
                    label = { Text("Name") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                Spacer(Modifier.height(8.dp))
                OutlinedTextField(
                    value = phone,
                    onValueChange = { phone = it },
                    label = { Text("Phone number (their login)") },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
                    modifier = Modifier.fillMaxWidth(),
                )
                Spacer(Modifier.height(8.dp))
                OutlinedTextField(
                    value = password,
                    onValueChange = { password = it },
                    label = { Text("Password (at least 6 characters)") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                Spacer(Modifier.height(8.dp))
                OutlinedTextField(
                    value = city,
                    onValueChange = { city = it },
                    label = { Text("City (optional)") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
            }
        },
        confirmButton = {
            Button(
                enabled = !saving,
                onClick = { onConfirm(name, phone, password, city) },
            ) {
                if (saving) {
                    CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp)
                } else {
                    Text("Create")
                }
            }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
    )
}
