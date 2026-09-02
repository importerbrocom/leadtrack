package com.agency.leadmanager.ui.diagnostics

import android.Manifest
import android.app.AppOpsManager
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.PowerManager
import android.os.Process
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
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Error
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.agency.leadmanager.ServiceLocator
import com.agency.leadmanager.call.CallLogScanner
import com.agency.leadmanager.data.ApiResult
import com.agency.leadmanager.notif.Notifier
import com.agency.leadmanager.sync.SyncScheduler
import com.agency.leadmanager.ui.appViewModel
import com.agency.leadmanager.ui.components.SectionCard
import com.agency.leadmanager.util.DateUtils
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/**
 * Tells the telecaller (and whoever is supporting them) exactly why call
 * tracking is or is not working.
 *
 * This exists because "it doesn't work" is impossible to act on remotely. On
 * Xiaomi, Oppo, Vivo and Realme phones there are three or four separate settings
 * that each silently break capture, and none of them produce an error the user
 * can see. This screen names the one that is wrong.
 */

enum class CheckLevel { OK, WARN, FAIL }

data class Check(
    val label: String,
    val detail: String,
    val level: CheckLevel,
    val fix: String? = null,
    val action: DiagnosticAction? = null,
)

enum class DiagnosticAction { APP_SETTINGS, BATTERY_SETTINGS, NOTIFICATION_SETTINGS }

data class DiagnosticsUiState(
    val loading: Boolean = true,
    val checks: List<Check> = emptyList(),
    val message: String? = null,
    val busy: Boolean = false,
)

class DiagnosticsViewModel(
    private val locator: ServiceLocator,
    private val context: Context,
) : ViewModel() {

    private val _state = MutableStateFlow(DiagnosticsUiState())
    val state = _state.asStateFlow()

    init {
        run()
    }

    fun run() {
        _state.update { it.copy(loading = true) }

        viewModelScope.launch {
            val checks = mutableListOf<Check>()

            // ---------------------------------------------------- account
            val user = locator.session.user()
            checks += if (user != null) {
                Check("Signed in", "${user.name} (${user.role})", CheckLevel.OK)
            } else {
                Check("Signed in", "nobody", CheckLevel.FAIL, "Sign in again.")
            }

            if (user != null && user.role == "admin") {
                checks += Check(
                    "Account type",
                    "admin",
                    CheckLevel.WARN,
                    "Call tracking is meant for telecallers. Leads you create have no owner. Test with a telecaller account."
                )
            }

            // ---------------------------------------------------- permissions
            fun granted(p: String) =
                ContextCompat.checkSelfPermission(context, p) ==
                    android.content.pm.PackageManager.PERMISSION_GRANTED

            val callLog = granted(Manifest.permission.READ_CALL_LOG)
            checks += Check(
                "Call log permission",
                if (callLog) "granted" else "DENIED",
                if (callLog) CheckLevel.OK else CheckLevel.FAIL,
                if (callLog) null else "Without this the app cannot see the number or how long you spoke. Nothing is recorded at all.",
                if (callLog) null else DiagnosticAction.APP_SETTINGS,
            )

            val phoneState = granted(Manifest.permission.READ_PHONE_STATE)
            checks += Check(
                "Phone permission",
                if (phoneState) "granted" else "DENIED",
                if (phoneState) CheckLevel.OK else CheckLevel.FAIL,
                if (phoneState) null else "Without this the app is never told that a call started or ended.",
                if (phoneState) null else DiagnosticAction.APP_SETTINGS,
            )

            val contacts = granted(Manifest.permission.READ_CONTACTS)
            checks += Check(
                "Contacts permission",
                if (contacts) "granted" else "not granted (optional)",
                if (contacts) CheckLevel.OK else CheckLevel.WARN,
                if (contacts) null else "Grant it and the prompt shows the contact's name instead of the number.",
                if (contacts) null else DiagnosticAction.APP_SETTINGS,
            )

            // ---------------------------------------------------- notifications
            val notificationsOn = NotificationManagerCompat.from(context).areNotificationsEnabled()
            checks += Check(
                "Notifications",
                if (notificationsOn) "allowed" else "BLOCKED",
                if (notificationsOn) CheckLevel.OK else CheckLevel.FAIL,
                if (notificationsOn) null else "The 'Is this a lead?' prompt is a notification. Blocked means you will never see it.",
                if (notificationsOn) null else DiagnosticAction.NOTIFICATION_SETTINGS,
            )

            // ---------------------------------------------------- battery
            val power = context.getSystemService(Context.POWER_SERVICE) as? PowerManager
            val unrestricted = power?.isIgnoringBatteryOptimizations(context.packageName) ?: true
            checks += Check(
                "Battery restrictions",
                if (unrestricted) "unrestricted" else "RESTRICTED",
                if (unrestricted) CheckLevel.OK else CheckLevel.FAIL,
                if (unrestricted) null else "The phone will kill call tracking. Set battery usage to Unrestricted.",
                if (unrestricted) null else DiagnosticAction.BATTERY_SETTINGS,
            )

            // ---------------------------------------------------- manufacturer
            val brand = Build.MANUFACTURER.lowercase()
            if (brand.contains("xiaomi") || brand.contains("redmi") || brand.contains("poco")) {
                checks += Check(
                    "Xiaomi / MIUI extra settings",
                    "must be set by hand",
                    CheckLevel.WARN,
                    "In Settings > Apps > Lead Manager, turn ON 'Autostart', and under 'Other permissions' turn ON " +
                        "'Display pop-up windows while running in background'. MIUI blocks background work by default " +
                        "and gives no warning.",
                    DiagnosticAction.APP_SETTINGS,
                )
            } else if (brand.contains("oppo") || brand.contains("vivo") || brand.contains("realme") ||
                brand.contains("oneplus") || brand.contains("huawei")
            ) {
                checks += Check(
                    "${Build.MANUFACTURER} extra settings",
                    "must be set by hand",
                    CheckLevel.WARN,
                    "Allow Autostart / Auto-launch and set battery usage to Unrestricted, otherwise the phone stops call tracking.",
                    DiagnosticAction.APP_SETTINGS,
                )
            }

            // ---------------------------------------------------- can we actually read the log?
            if (callLog) {
                val scanner = CallLogScanner(context)
                val week = scanner.callsSince(System.currentTimeMillis() - 7L * 24 * 60 * 60 * 1000)
                val latest = scanner.latestCall()

                // Granted-but-withheld: READ_CALL_LOG is hard-restricted, so the
                // permission can read as granted while the AppOps op is ignored
                // and the provider quietly returns nothing.
                val opMode = try {
                    val ops = context.getSystemService(Context.APP_OPS_SERVICE) as? AppOpsManager
                    ops?.unsafeCheckOpNoThrow(
                        AppOpsManager.OPSTR_READ_CALL_LOG,
                        Process.myUid(),
                        context.packageName,
                    )
                } catch (e: Exception) {
                    null
                }

                if (opMode != null && opMode != AppOpsManager.MODE_ALLOWED) {
                    checks += Check(
                        "Call log data access",
                        when (opMode) {
                            AppOpsManager.MODE_IGNORED -> "GRANTED BUT WITHHELD"
                            AppOpsManager.MODE_ERRORED -> "BLOCKED"
                            else -> "restricted (mode $opMode)"
                        },
                        CheckLevel.FAIL,
                        "Android is withholding call-log data even though the permission is granted. This happens " +
                            "when the app is installed outside the Play Store: reading call logs is a restricted " +
                            "permission and the installer did not allow it. Reinstalling from a different installer, " +
                            "or installing over USB, restores it.",
                    )
                }

                val probe = scanner.probe()

                checks += Check(
                    "Calls visible to the app",
                    "${week.size} in the last 7 days",
                    if (week.isEmpty()) CheckLevel.WARN else CheckLevel.OK,
                    if (week.isEmpty()) "The app can read the call log but sees no recent calls. Make a call, then re-run this check." else null,
                )

                // The line that separates "platform gave us nothing" from
                // "platform gave us rows we threw away".
                checks += when {
                    probe.outcome != CallLogScanner.Probe.Outcome.OK -> Check(
                        "Call log read result",
                        when (probe.outcome) {
                            CallLogScanner.Probe.Outcome.NULL_CURSOR -> "the phone refused the request"
                            CallLogScanner.Probe.Outcome.THREW -> "the read failed with an error"
                            else -> "no permission"
                        },
                        CheckLevel.FAIL,
                        "The call log provider would not answer at all. Show this screen to the office.",
                    )

                    probe.rawRows == 0 -> Check(
                        "Call log read result",
                        "the phone returned 0 rows",
                        CheckLevel.FAIL,
                        "Your dialer shows call history, so the log is not empty - the phone is deliberately hiding " +
                            "it from this app. That is the restricted-permission problem above, not a bug in the app.",
                    )

                    probe.parsedRows == 0 && probe.blankNumbers > 0 -> Check(
                        "Call log read result",
                        "${probe.rawRows} rows, but every number is hidden",
                        CheckLevel.FAIL,
                        "The phone returns the calls with the phone number blanked out. Report this to the office - " +
                            "the app needs a change to handle it.",
                    )

                    else -> Check(
                        "Call log read result",
                        "${probe.rawRows} rows readable, ${probe.parsedRows} usable",
                        CheckLevel.OK,
                    )
                }

                if (latest != null) {
                    checks += Check(
                        "Most recent call on this phone",
                        "${latest.number} · ${DateUtils.duration(latest.durationSec)} · ${DateUtils.prettyTime(latest.startedAtMillis)}",
                        CheckLevel.OK,
                    )
                }
            }

            // ---------------------------------------------------- capture triggers
            val watching = com.agency.leadmanager.call.CallLogTriggerJobService.isScheduled(context)
            checks += Check(
                "Call log watcher",
                if (watching) "active" else "NOT REGISTERED",
                if (watching) CheckLevel.OK else CheckLevel.WARN,
                if (watching) null else "Open and close the app once to re-register it. If it stays off, this phone rejects call-log watching and capture falls back to the 15-minute scan.",
            )

            // ---------------------------------------------------- tracking switch
            val trackingOn = locator.session.callTrackingEnabled()
            checks += Check(
                "Call tracking switch",
                if (trackingOn) "on" else "OFF",
                if (trackingOn) CheckLevel.OK else CheckLevel.FAIL,
                if (trackingOn) null else "Turn 'Log my calls' back on in Settings.",
            )

            // ---------------------------------------------------- queue + server
            val pending = locator.callRepository.pendingCount()
            checks += Check(
                "Calls waiting to sync",
                "$pending",
                if (pending > 20) CheckLevel.WARN else CheckLevel.OK,
                if (pending > 20) "A lot are queued, which suggests the server is unreachable. Try Sync now in Settings." else null,
            )

            val health = locator.dashboardRepository.dashboard()
            checks += when (health) {
                is ApiResult.Success -> Check(
                    "Server connection",
                    "reachable · ${health.data.leads?.total ?: 0} leads visible to you",
                    CheckLevel.OK,
                )
                is ApiResult.Failure -> Check(
                    "Server connection",
                    health.message,
                    CheckLevel.FAIL,
                    "Check the phone's internet, and the server address in Settings.",
                )
            }

            _state.update { it.copy(loading = false, checks = checks) }
        }
    }

    /** Force the catch-up scan and report what it queued. */
    fun scanNow() {
        _state.update { it.copy(busy = true) }

        viewModelScope.launch {
            val before = locator.callRepository.pendingCount()

            val scanner = CallLogScanner(context)
            if (!scanner.hasPermission()) {
                _state.update {
                    it.copy(busy = false, message = "Cannot scan: the call log permission is denied.")
                }
                return@launch
            }

            // Look back a day regardless of the usual watermark, so a call that
            // was already skipped still gets picked up.
            val calls = scanner.callsSince(System.currentTimeMillis() - 24L * 60 * 60 * 1000)

            calls.sortedBy { it.startedAtMillis }.forEach { call ->
                val lookup = locator.leadRepository.lookupByPhone(call.number)
                val leadId =
                    (lookup as? com.agency.leadmanager.data.repo.LeadLookupResult.Found)?.leadId

                locator.callRepository.recordCall(
                    deviceCallId = call.deviceCallId,
                    phoneNumber = call.number,
                    direction = call.direction,
                    startedAtMillis = call.startedAtMillis,
                    durationSec = call.durationSec,
                    simSlot = call.simSlot,
                    leadId = leadId,
                )
            }

            val queued = locator.callRepository.pendingCount() - before
            val sent = locator.callRepository.syncPending()

            SyncScheduler.syncNow(context)

            _state.update {
                it.copy(
                    busy = false,
                    message = "Found ${calls.size} call(s) on the phone, queued $queued new, " +
                        (if (sent < 0) "no connection to send them yet" else "sent $sent to the office"),
                )
            }

            run()
        }
    }

    /** Fire the real post-call notification so the user can confirm it appears. */
    fun testPrompt() {
        viewModelScope.launch {
            Notifier(context).showPostCallPrompt(
                pendingCallId = 999_999L,
                phoneNumber = "9000000000",
                displayName = "Test number",
                durationSec = 42,
                direction = "outgoing",
                leadId = null,
                leadName = null,
                leadStatus = null,
                suggestedName = null,
            )
            _state.update {
                it.copy(message = "Test prompt sent. Check your notification shade. Tapping 'Yes' would create a test lead, so just dismiss it.")
            }
        }
    }

    fun consumeMessage() = _state.update { it.copy(message = null) }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun DiagnosticsScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val vm: DiagnosticsViewModel = appViewModel { DiagnosticsViewModel(it, context) }
    val state by vm.state.collectAsState()
    val snackbar = remember { SnackbarHostState() }

    LaunchedEffect(state.message) {
        state.message?.let {
            snackbar.showSnackbar(it)
            vm.consumeMessage()
        }
    }

    val failures = state.checks.count { it.level == CheckLevel.FAIL }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Why isn't it working?") },
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
            if (state.loading && state.checks.isEmpty()) {
                Row(
                    Modifier.fillMaxWidth().padding(32.dp),
                    horizontalArrangement = Arrangement.Center,
                ) { CircularProgressIndicator() }
                return@Column
            }

            SectionCard {
                Text(
                    if (failures == 0) "Call tracking looks correctly set up" else "$failures problem(s) stopping call tracking",
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.SemiBold,
                    color = if (failures == 0) {
                        com.agency.leadmanager.ui.theme.StatusConverted
                    } else {
                        MaterialTheme.colorScheme.error
                    },
                )
                Text(
                    "Show this screen to your office if calls are not appearing.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }

            state.checks.forEach { check ->
                SectionCard {
                    Row(verticalAlignment = Alignment.Top) {
                        Icon(
                            imageVector = when (check.level) {
                                CheckLevel.OK -> Icons.Default.CheckCircle
                                CheckLevel.WARN -> Icons.Default.Warning
                                CheckLevel.FAIL -> Icons.Default.Error
                            },
                            contentDescription = null,
                            tint = when (check.level) {
                                CheckLevel.OK -> com.agency.leadmanager.ui.theme.StatusConverted
                                CheckLevel.WARN -> Color(0xFF9A6700)
                                CheckLevel.FAIL -> MaterialTheme.colorScheme.error
                            },
                            modifier = Modifier.size(18.dp),
                        )
                        Spacer(Modifier.size(10.dp))
                        Column(Modifier.fillMaxWidth()) {
                            Text(
                                check.label,
                                style = MaterialTheme.typography.bodyMedium,
                                fontWeight = FontWeight.Medium,
                            )
                            Text(
                                check.detail,
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                            if (check.fix != null) {
                                Spacer(Modifier.height(6.dp))
                                Text(
                                    check.fix,
                                    style = MaterialTheme.typography.bodySmall,
                                    color = if (check.level == CheckLevel.FAIL) {
                                        MaterialTheme.colorScheme.error
                                    } else {
                                        Color(0xFF9A6700)
                                    },
                                )
                            }
                            if (check.action != null) {
                                Spacer(Modifier.height(8.dp))
                                OutlinedButton(onClick = { openSettings(context, check.action) }) {
                                    Text(
                                        when (check.action) {
                                            DiagnosticAction.APP_SETTINGS -> "Open app settings"
                                            DiagnosticAction.BATTERY_SETTINGS -> "Fix battery setting"
                                            DiagnosticAction.NOTIFICATION_SETTINGS -> "Open notification settings"
                                        },
                                        style = MaterialTheme.typography.labelMedium,
                                    )
                                }
                            }
                        }
                    }
                }
            }

            Button(
                onClick = { vm.scanNow() },
                enabled = !state.busy,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(if (state.busy) "Scanning…" else "Scan my recent calls and send them now")
            }

            OutlinedButton(
                onClick = { vm.testPrompt() },
                modifier = Modifier.fillMaxWidth(),
            ) { Text("Send a test 'Is this a lead?' prompt") }

            OutlinedButton(
                onClick = { vm.run() },
                modifier = Modifier.fillMaxWidth(),
            ) { Text("Re-run these checks") }

            Spacer(Modifier.height(24.dp))
        }
    }
}

private fun openSettings(context: Context, action: DiagnosticAction) {
    val intent = when (action) {
        DiagnosticAction.APP_SETTINGS ->
            Intent(
                Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                Uri.fromParts("package", context.packageName, null)
            )

        DiagnosticAction.BATTERY_SETTINGS ->
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                Intent(
                    Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS,
                    Uri.parse("package:${context.packageName}")
                )
            } else {
                Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                    Uri.fromParts("package", context.packageName, null))
            }

        DiagnosticAction.NOTIFICATION_SETTINGS ->
            Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS)
                .putExtra(Settings.EXTRA_APP_PACKAGE, context.packageName)
    }

    try {
        context.startActivity(intent)
    } catch (e: Exception) {
        try {
            context.startActivity(
                Intent(
                    Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                    Uri.fromParts("package", context.packageName, null)
                )
            )
        } catch (e2: Exception) {
            // Nothing more we can do.
        }
    }
}
