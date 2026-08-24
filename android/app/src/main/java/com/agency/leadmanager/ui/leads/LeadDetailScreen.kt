package com.agency.leadmanager.ui.leads

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
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
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.CallMade
import androidx.compose.material.icons.automirrored.filled.CallMissed
import androidx.compose.material.icons.automirrored.filled.CallReceived
import androidx.compose.material.icons.automirrored.filled.Chat
import androidx.compose.material.icons.filled.Phone
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.AssistChip
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.agency.leadmanager.ServiceLocator
import com.agency.leadmanager.data.ApiResult
import com.agency.leadmanager.data.remote.dto.CallDto
import com.agency.leadmanager.data.remote.dto.ConvertRequest
import com.agency.leadmanager.data.remote.dto.LeadDto
import com.agency.leadmanager.ui.appViewModel
import com.agency.leadmanager.ui.components.DetailRow
import com.agency.leadmanager.ui.components.SectionCard
import com.agency.leadmanager.ui.components.StatusChip
import com.agency.leadmanager.ui.dial
import com.agency.leadmanager.ui.openWhatsApp
import com.agency.leadmanager.util.DateUtils
import com.agency.leadmanager.util.Labels
import com.agency.leadmanager.util.PhoneUtils
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class LeadDetailUiState(
    val loading: Boolean = true,
    val lead: LeadDto? = null,
    val saving: Boolean = false,
    val message: String? = null,
    val error: String? = null,
    val canConvert: Boolean = true,
    val convertedProjectId: Long? = null,
)

class LeadDetailViewModel(
    private val locator: ServiceLocator,
    private val leadId: Long,
) : ViewModel() {

    private val _state = MutableStateFlow(LeadDetailUiState())
    val state = _state.asStateFlow()

    init {
        load()
        viewModelScope.launch {
            val user = locator.session.user()
            val allowed = locator.session.partnerCanConvert()
            _state.update { it.copy(canConvert = user?.isAdmin == true || allowed) }
        }
    }

    fun load() {
        _state.update { it.copy(loading = true) }

        viewModelScope.launch {
            when (val result = locator.leadRepository.lead(leadId)) {
                is ApiResult.Success ->
                    _state.update { it.copy(loading = false, lead = result.data, error = null) }

                is ApiResult.Failure ->
                    _state.update { it.copy(loading = false, error = result.message) }
            }
        }
    }

    /** Update status, optionally scheduling the next callback in one go. */
    fun updateStatus(status: String, remarks: String?, callbackMillis: Long?) {
        _state.update { it.copy(saving = true) }

        viewModelScope.launch {
            val result = locator.leadRepository.updateStatus(
                leadId = leadId,
                status = status,
                remarks = remarks,
                nextFollowUpAtMillis = callbackMillis,
            )

            when (result) {
                is ApiResult.Success -> {
                    _state.update {
                        it.copy(saving = false, lead = result.data, message = "Status updated")
                    }
                }

                is ApiResult.Failure -> {
                    _state.update {
                        it.copy(
                            saving = false,
                            message = if (result.isOffline) result.message else null,
                            error = if (result.isOffline) null else result.message,
                        )
                    }
                    if (result.isOffline) load()
                }
            }
        }
    }

    fun scheduleCallback(whenMillis: Long, remarks: String?) {
        _state.update { it.copy(saving = true) }

        viewModelScope.launch {
            when (val result = locator.leadRepository.scheduleFollowUp(leadId, whenMillis, remarks)) {
                is ApiResult.Success -> {
                    _state.update { it.copy(saving = false, message = "Callback scheduled") }
                    load()
                }

                is ApiResult.Failure ->
                    _state.update { it.copy(saving = false, error = result.message) }
            }
        }
    }

    fun convert(candidateName: String, position: String?, country: String?, employer: String?, passport: String?) {
        _state.update { it.copy(saving = true) }

        viewModelScope.launch {
            val result = locator.leadRepository.convert(
                leadId,
                ConvertRequest(
                    candidateName = candidateName.takeIf { it.isNotBlank() },
                    position = position?.takeIf { it.isNotBlank() },
                    destinationCountry = country?.takeIf { it.isNotBlank() },
                    employerName = employer?.takeIf { it.isNotBlank() },
                    passportNo = passport?.takeIf { it.isNotBlank() },
                )
            )

            when (result) {
                is ApiResult.Success -> _state.update {
                    it.copy(
                        saving = false,
                        message = "Converted to project ${result.data.projectCode}",
                        convertedProjectId = result.data.id,
                    )
                }

                is ApiResult.Failure ->
                    _state.update { it.copy(saving = false, error = result.message) }
            }
        }
    }

    fun consumeMessage() = _state.update { it.copy(message = null, error = null) }
}

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun LeadDetailScreen(
    leadId: Long,
    onBack: () -> Unit,
    onOpenProject: (Long) -> Unit,
) {
    val vm: LeadDetailViewModel = appViewModel(key = "lead-$leadId") {
        LeadDetailViewModel(it, leadId)
    }
    val state by vm.state.collectAsState()
    val context = LocalContext.current
    val snackbar = remember { SnackbarHostState() }

    var showStatusSheet by remember { mutableStateOf(false) }
    var showCallbackSheet by remember { mutableStateOf(false) }
    var showConvertSheet by remember { mutableStateOf(false) }

    LaunchedEffect(state.message, state.error) {
        val text = state.message ?: state.error
        if (text != null) {
            snackbar.showSnackbar(text)
            vm.consumeMessage()
        }
    }

    LaunchedEffect(state.convertedProjectId) {
        state.convertedProjectId?.let(onOpenProject)
    }

    val lead = state.lead

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(lead?.name ?: "Lead") },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
                actions = {
                    if (lead != null) {
                        IconButton(onClick = { openWhatsApp(context, lead.whatsapp ?: lead.phone) }) {
                            Icon(Icons.AutoMirrored.Filled.Chat, contentDescription = "WhatsApp")
                        }
                        IconButton(onClick = { dial(context, lead.phone) }) {
                            Icon(
                                Icons.Default.Phone,
                                contentDescription = "Call",
                                tint = MaterialTheme.colorScheme.primary,
                            )
                        }
                    }
                },
            )
        },
        snackbarHost = { SnackbarHost(snackbar) },
    ) { padding ->

        if (state.loading && lead == null) {
            Column(
                Modifier.padding(padding).fillMaxSize(),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.Center,
            ) { CircularProgressIndicator() }
            return@Scaffold
        }

        if (lead == null) {
            Column(
                Modifier.padding(padding).fillMaxSize().padding(24.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.Center,
            ) {
                Text(state.error ?: "Could not load this lead")
                Spacer(Modifier.height(12.dp))
                OutlinedButton(onClick = { vm.load() }) { Text("Try again") }
            }
            return@Scaffold
        }

        LazyColumn(
            modifier = Modifier.padding(padding).fillMaxSize(),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            // ---------------------------------------------------- summary
            item {
                SectionCard {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(
                                lead.name,
                                style = MaterialTheme.typography.titleMedium,
                                fontWeight = FontWeight.SemiBold,
                            )
                            Text(
                                PhoneUtils.formatForDisplay(lead.phone),
                                style = MaterialTheme.typography.bodyMedium,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                        StatusChip(lead.status)
                    }

                    Spacer(Modifier.height(10.dp))

                    Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                        Column {
                            Text(
                                "${lead.callCount}",
                                style = MaterialTheme.typography.titleMedium,
                                fontWeight = FontWeight.SemiBold,
                            )
                            Text(
                                "calls",
                                style = MaterialTheme.typography.labelSmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                        Column {
                            Text(
                                lead.talkTimeDisplay ?: DateUtils.duration(lead.totalTalkTimeSec),
                                style = MaterialTheme.typography.titleMedium,
                                fontWeight = FontWeight.SemiBold,
                            )
                            Text(
                                "talk time",
                                style = MaterialTheme.typography.labelSmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                        Column {
                            Text(
                                if (lead.lastContactedAt != null) {
                                    DateUtils.relative(lead.lastContactedAt)
                                } else {
                                    "never"
                                },
                                style = MaterialTheme.typography.titleMedium,
                                fontWeight = FontWeight.SemiBold,
                            )
                            Text(
                                "last called",
                                style = MaterialTheme.typography.labelSmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                    }

                    lead.nextFollowUpAt?.let { next ->
                        Spacer(Modifier.height(10.dp))
                        val overdue = DateUtils.isOverdue(next)
                        Text(
                            text = (if (overdue) "Overdue callback: " else "Next callback: ") +
                                DateUtils.pretty(next) + " (" + DateUtils.relative(next) + ")",
                            style = MaterialTheme.typography.bodySmall,
                            color = if (overdue) {
                                MaterialTheme.colorScheme.error
                            } else {
                                MaterialTheme.colorScheme.primary
                            },
                        )
                    }
                }
            }

            // ---------------------------------------------------- actions
            item {
                if (lead.isConverted) {
                    SectionCard {
                        Text(
                            "This lead is converted into a project.",
                            style = MaterialTheme.typography.bodyMedium,
                        )
                        lead.projectId?.let { projectId ->
                            Spacer(Modifier.height(8.dp))
                            Button(
                                onClick = { onOpenProject(projectId) },
                                modifier = Modifier.fillMaxWidth(),
                            ) { Text("Open project") }
                        }
                    }
                } else {
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        FilledTonalButton(
                            onClick = { showStatusSheet = true },
                            modifier = Modifier.weight(1f),
                        ) { Text("Update status") }

                        FilledTonalButton(
                            onClick = { showCallbackSheet = true },
                            modifier = Modifier.weight(1f),
                        ) { Text("Set callback") }
                    }
                }
            }

            if (!lead.isConverted && state.canConvert) {
                item {
                    OutlinedButton(
                        onClick = { showConvertSheet = true },
                        modifier = Modifier.fillMaxWidth(),
                    ) { Text("Convert to project") }
                }
            }

            // ---------------------------------------------------- details
            item {
                SectionCard(title = "Candidate") {
                    DetailRow("City", lead.city)
                    DetailRow("Job category", lead.jobCategoryName)
                    DetailRow("Country wanted", lead.preferredCountry)
                    DetailRow("Qualification", lead.qualification)
                    DetailRow(
                        "Experience",
                        lead.experienceYears?.let { "$it years" },
                    )
                    DetailRow("Passport", lead.passportStatus?.let { Labels.pretty(it) })
                    DetailRow("Priority", Labels.pretty(lead.priority))
                    DetailRow("Source", lead.sourceName)
                    DetailRow("Assigned to", lead.assignedToName)

                    if (!lead.notes.isNullOrBlank()) {
                        HorizontalDivider(Modifier.padding(vertical = 8.dp))
                        Text(
                            "Notes",
                            style = MaterialTheme.typography.labelMedium,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                        Text(lead.notes, style = MaterialTheme.typography.bodySmall)
                    }
                }
            }

            // ---------------------------------------------------- call history
            item {
                Text(
                    "Call history",
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.SemiBold,
                    modifier = Modifier.padding(top = 4.dp),
                )
            }

            val calls = lead.calls.orEmpty()

            if (calls.isEmpty()) {
                item {
                    SectionCard {
                        Text(
                            "No calls recorded yet. Calls are logged automatically when you dial from this phone.",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }
            } else {
                items(calls, key = { it.id }) { call -> CallRow(call) }
            }

            // ---------------------------------------------------- status history
            val history = lead.statusHistory.orEmpty()
            if (history.isNotEmpty()) {
                item {
                    SectionCard(title = "Activity") {
                        history.take(12).forEach { entry ->
                            Column(Modifier.padding(vertical = 4.dp)) {
                                Text(
                                    buildString {
                                        entry.fromStatus?.let { append(Labels.pretty(it)).append(" → ") }
                                        append(Labels.pretty(entry.toStatus))
                                    },
                                    style = MaterialTheme.typography.bodySmall,
                                    fontWeight = FontWeight.Medium,
                                )
                                if (!entry.remarks.isNullOrBlank()) {
                                    Text(
                                        entry.remarks,
                                        style = MaterialTheme.typography.labelSmall,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    )
                                }
                                Text(
                                    "${entry.userName ?: "System"} · ${DateUtils.pretty(entry.createdAt)}",
                                    style = MaterialTheme.typography.labelSmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                            }
                        }
                    }
                }
            }

            item { Spacer(Modifier.height(24.dp)) }
        }
    }

    // ------------------------------------------------------------ dialogs

    // The early returns above live inside the Scaffold lambda, so out here the
    // compiler still sees a nullable lead. Guard once and share it.
    val loadedLead = state.lead ?: return

    if (showStatusSheet) {
        StatusDialog(
            currentStatus = loadedLead.status,
            saving = state.saving,
            onDismiss = { showStatusSheet = false },
            onConfirm = { status, remarks, callback ->
                vm.updateStatus(status, remarks, callback)
                showStatusSheet = false
            },
        )
    }

    if (showCallbackSheet) {
        CallbackDialog(
            saving = state.saving,
            onDismiss = { showCallbackSheet = false },
            onConfirm = { millis, remarks ->
                vm.scheduleCallback(millis, remarks)
                showCallbackSheet = false
            },
        )
    }

    if (showConvertSheet) {
        ConvertDialog(
            lead = loadedLead,
            saving = state.saving,
            onDismiss = { showConvertSheet = false },
            onConfirm = { name, position, country, employer, passport ->
                vm.convert(name, position, country, employer, passport)
                showConvertSheet = false
            },
        )
    }
}

@Composable
private fun CallRow(call: CallDto) {
    SectionCard {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(
                imageVector = when (call.direction) {
                    "incoming" -> Icons.AutoMirrored.Filled.CallReceived
                    "missed", "rejected" -> Icons.AutoMirrored.Filled.CallMissed
                    else -> Icons.AutoMirrored.Filled.CallMade
                },
                contentDescription = null,
                modifier = Modifier.size(18.dp),
                tint = if (call.answered) {
                    MaterialTheme.colorScheme.primary
                } else {
                    MaterialTheme.colorScheme.onSurfaceVariant
                },
            )

            Spacer(Modifier.size(10.dp))

            Column(Modifier.weight(1f)) {
                Text(
                    buildString {
                        append(Labels.pretty(call.direction))
                        append(" · ")
                        append(call.duration ?: DateUtils.duration(call.durationSec))
                    },
                    style = MaterialTheme.typography.bodySmall,
                    fontWeight = FontWeight.Medium,
                )
                Text(
                    DateUtils.pretty(call.startedAt) +
                        (call.userName?.let { " · $it" } ?: ""),
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                if (!call.notes.isNullOrBlank()) {
                    Text(
                        call.notes,
                        style = MaterialTheme.typography.labelSmall,
                    )
                }
            }

            call.disposition?.let {
                Text(
                    Labels.pretty(it),
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun StatusDialog(
    currentStatus: String,
    saving: Boolean,
    onDismiss: () -> Unit,
    onConfirm: (String, String?, Long?) -> Unit,
) {
    var status by remember { mutableStateOf(currentStatus) }
    var remarks by remember { mutableStateOf("") }
    var callbackMillis by remember { mutableStateOf<Long?>(null) }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Update status") },
        text = {
            Column {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    Labels.leadStatusChoices.forEach { (value, label) ->
                        FilterChip(
                            selected = status == value,
                            onClick = { status = value },
                            label = { Text(label, style = MaterialTheme.typography.labelSmall) },
                        )
                    }
                }

                Spacer(Modifier.height(12.dp))
                Text("Call back (optional)", style = MaterialTheme.typography.labelLarge)
                Spacer(Modifier.height(6.dp))

                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    callbackOptions().forEach { (label, millis) ->
                        FilterChip(
                            selected = callbackMillis == millis,
                            onClick = {
                                callbackMillis = if (callbackMillis == millis) null else millis
                            },
                            label = { Text(label, style = MaterialTheme.typography.labelSmall) },
                        )
                    }
                }

                Spacer(Modifier.height(12.dp))

                OutlinedTextField(
                    value = remarks,
                    onValueChange = { remarks = it },
                    label = { Text("Remarks") },
                    minLines = 2,
                    modifier = Modifier.fillMaxWidth(),
                )
            }
        },
        confirmButton = {
            Button(
                enabled = !saving,
                onClick = { onConfirm(status, remarks.trim().takeIf { it.isNotBlank() }, callbackMillis) },
            ) { Text("Save") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
    )
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun CallbackDialog(
    saving: Boolean,
    onDismiss: () -> Unit,
    onConfirm: (Long, String?) -> Unit,
) {
    var millis by remember { mutableStateOf(DateUtils.tomorrowAt(10)) }
    var remarks by remember { mutableStateOf("") }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Schedule a callback") },
        text = {
            Column {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    callbackOptions().forEach { (label, value) ->
                        FilterChip(
                            selected = millis == value,
                            onClick = { millis = value },
                            label = { Text(label, style = MaterialTheme.typography.labelSmall) },
                        )
                    }
                }

                Spacer(Modifier.height(8.dp))

                AssistChip(
                    onClick = { },
                    label = { Text(DateUtils.prettyTime(millis), style = MaterialTheme.typography.labelSmall) },
                )

                Spacer(Modifier.height(12.dp))

                OutlinedTextField(
                    value = remarks,
                    onValueChange = { remarks = it },
                    label = { Text("Reason (optional)") },
                    modifier = Modifier.fillMaxWidth(),
                )
            }
        },
        confirmButton = {
            Button(
                enabled = !saving,
                onClick = { onConfirm(millis, remarks.trim().takeIf { it.isNotBlank() }) },
            ) { Text("Schedule") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
    )
}

@Composable
private fun ConvertDialog(
    lead: LeadDto,
    saving: Boolean,
    onDismiss: () -> Unit,
    onConfirm: (String, String?, String?, String?, String?) -> Unit,
) {
    var name by remember { mutableStateOf(lead.name) }
    var position by remember { mutableStateOf("") }
    var country by remember { mutableStateOf(lead.preferredCountry.orEmpty()) }
    var employer by remember { mutableStateOf("") }
    var passport by remember { mutableStateOf("") }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Convert to project") },
        text = {
            Column {
                Text(
                    "The lead becomes a placement case. You can fill the rest in later.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Spacer(Modifier.height(12.dp))

                OutlinedTextField(
                    value = name,
                    onValueChange = { name = it },
                    label = { Text("Candidate name") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                Spacer(Modifier.height(8.dp))
                OutlinedTextField(
                    value = position,
                    onValueChange = { position = it },
                    label = { Text("Position") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                Spacer(Modifier.height(8.dp))
                OutlinedTextField(
                    value = country,
                    onValueChange = { country = it },
                    label = { Text("Destination country") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                Spacer(Modifier.height(8.dp))
                OutlinedTextField(
                    value = employer,
                    onValueChange = { employer = it },
                    label = { Text("Employer") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                Spacer(Modifier.height(8.dp))
                OutlinedTextField(
                    value = passport,
                    onValueChange = { passport = it },
                    label = { Text("Passport number") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
            }
        },
        confirmButton = {
            Button(
                enabled = !saving && name.isNotBlank(),
                onClick = { onConfirm(name, position, country, employer, passport) },
            ) { Text("Convert") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
    )
}

private fun callbackOptions(): List<Pair<String, Long>> = listOf(
    "In 1 hour" to DateUtils.inHours(1),
    "In 3 hours" to DateUtils.inHours(3),
    "Tomorrow 10am" to DateUtils.tomorrowAt(10),
    "Tomorrow 4pm" to DateUtils.tomorrowAt(16),
    "In 3 days" to DateUtils.inDays(3),
    "Next week" to DateUtils.inDays(7),
)
