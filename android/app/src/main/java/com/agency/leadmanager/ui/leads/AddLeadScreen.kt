package com.agency.leadmanager.ui.leads

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.agency.leadmanager.ServiceLocator
import com.agency.leadmanager.data.ApiResult
import com.agency.leadmanager.data.remote.dto.CreateLeadRequest
import com.agency.leadmanager.data.remote.dto.NamedIdDto
import com.agency.leadmanager.ui.appViewModel
import com.agency.leadmanager.util.DateUtils
import com.agency.leadmanager.util.Labels
import com.agency.leadmanager.util.PhoneUtils
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class AddLeadUiState(
    val saving: Boolean = false,
    val saved: Boolean = false,
    val message: String? = null,
    val jobCategories: List<NamedIdDto> = emptyList(),
    val sources: List<NamedIdDto> = emptyList(),
)

class AddLeadViewModel(private val locator: ServiceLocator) : ViewModel() {

    private val _state = MutableStateFlow(AddLeadUiState())
    val state = _state.asStateFlow()

    init {
        viewModelScope.launch {
            val result = locator.dashboardRepository.lookups()
            if (result is ApiResult.Success) {
                _state.update {
                    it.copy(
                        jobCategories = result.data.jobCategories.orEmpty(),
                        sources = result.data.leadSources.orEmpty(),
                    )
                }
            }
        }
    }

    fun save(
        name: String,
        phone: String,
        city: String,
        jobCategoryId: Int?,
        sourceId: Int?,
        country: String,
        qualification: String,
        priority: String,
        notes: String,
        callbackMillis: Long?,
    ) {
        if (name.isBlank()) {
            _state.update { it.copy(message = "Enter the candidate's name") }
            return
        }

        if (!PhoneUtils.isValid(phone)) {
            _state.update { it.copy(message = "Enter a valid 10-digit phone number") }
            return
        }

        _state.update { it.copy(saving = true, message = null) }

        viewModelScope.launch {
            val result = locator.leadRepository.createLead(
                CreateLeadRequest(
                    name = name.trim(),
                    phone = phone.trim(),
                    city = city.trim().takeIf { it.isNotBlank() },
                    jobCategoryId = jobCategoryId,
                    sourceId = sourceId,
                    preferredCountry = country.trim().takeIf { it.isNotBlank() },
                    qualification = qualification.trim().takeIf { it.isNotBlank() },
                    priority = priority,
                    notes = notes.trim().takeIf { it.isNotBlank() },
                    nextFollowUpAt = callbackMillis?.let { DateUtils.toApi(it) },
                )
            )

            when (result) {
                is ApiResult.Success ->
                    _state.update { it.copy(saving = false, saved = true, message = "Lead added") }

                is ApiResult.Failure ->
                    // An offline save still counts as done from the user's view.
                    if (result.isOffline) {
                        _state.update { it.copy(saving = false, saved = true, message = result.message) }
                    } else {
                        _state.update { it.copy(saving = false, message = result.message) }
                    }
            }
        }
    }

    fun consumeMessage() = _state.update { it.copy(message = null) }
}

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun AddLeadScreen(
    onSaved: () -> Unit,
    onBack: () -> Unit,
) {
    val vm: AddLeadViewModel = appViewModel { AddLeadViewModel(it) }
    val state by vm.state.collectAsState()
    val snackbar = remember { SnackbarHostState() }

    var name by remember { mutableStateOf("") }
    var phone by remember { mutableStateOf("") }
    var city by remember { mutableStateOf("") }
    var country by remember { mutableStateOf("") }
    var qualification by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    var priority by remember { mutableStateOf("medium") }
    var jobCategory by remember { mutableStateOf<NamedIdDto?>(null) }
    var source by remember { mutableStateOf<NamedIdDto?>(null) }
    var callbackMillis by remember { mutableStateOf<Long?>(null) }

    LaunchedEffect(state.message) {
        state.message?.let {
            snackbar.showSnackbar(it)
            vm.consumeMessage()
        }
    }

    LaunchedEffect(state.saved) {
        if (state.saved) onSaved()
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("New lead") },
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
                .imePadding()
                .padding(16.dp),
        ) {
            OutlinedTextField(
                value = name,
                onValueChange = { name = it },
                label = { Text("Candidate name *") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )

            Spacer(Modifier.height(10.dp))

            OutlinedTextField(
                value = phone,
                onValueChange = { phone = it },
                label = { Text("Phone number *") },
                singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
                modifier = Modifier.fillMaxWidth(),
            )

            Spacer(Modifier.height(10.dp))

            OutlinedTextField(
                value = city,
                onValueChange = { city = it },
                label = { Text("City / town") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )

            Spacer(Modifier.height(10.dp))

            LookupDropdown(
                label = "Job category",
                options = state.jobCategories,
                selected = jobCategory,
                onSelected = { jobCategory = it },
            )

            Spacer(Modifier.height(10.dp))

            OutlinedTextField(
                value = country,
                onValueChange = { country = it },
                label = { Text("Country wanted") },
                placeholder = { Text("UAE, Qatar, Saudi…") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )

            Spacer(Modifier.height(10.dp))

            OutlinedTextField(
                value = qualification,
                onValueChange = { qualification = it },
                label = { Text("Qualification") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )

            Spacer(Modifier.height(10.dp))

            LookupDropdown(
                label = "Where did they come from?",
                options = state.sources,
                selected = source,
                onSelected = { source = it },
            )

            Spacer(Modifier.height(14.dp))

            Text("Priority", style = MaterialTheme.typography.labelLarge)
            Spacer(Modifier.height(6.dp))
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Labels.priorities.forEach { (value, label) ->
                    FilterChip(
                        selected = priority == value,
                        onClick = { priority = value },
                        label = { Text(label) },
                    )
                }
            }

            Spacer(Modifier.height(14.dp))

            Text("First callback (optional)", style = MaterialTheme.typography.labelLarge)
            Spacer(Modifier.height(6.dp))
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf(
                    "In 1 hour" to DateUtils.inHours(1),
                    "Tomorrow 10am" to DateUtils.tomorrowAt(10),
                    "In 3 days" to DateUtils.inDays(3),
                ).forEach { (label, millis) ->
                    FilterChip(
                        selected = callbackMillis == millis,
                        onClick = { callbackMillis = if (callbackMillis == millis) null else millis },
                        label = { Text(label, style = MaterialTheme.typography.labelSmall) },
                    )
                }
            }

            Spacer(Modifier.height(14.dp))

            OutlinedTextField(
                value = notes,
                onValueChange = { notes = it },
                label = { Text("Notes") },
                minLines = 3,
                modifier = Modifier.fillMaxWidth(),
            )

            Spacer(Modifier.height(20.dp))

            Button(
                onClick = {
                    vm.save(
                        name = name,
                        phone = phone,
                        city = city,
                        jobCategoryId = jobCategory?.id,
                        sourceId = source?.id,
                        country = country,
                        qualification = qualification,
                        priority = priority,
                        notes = notes,
                        callbackMillis = callbackMillis,
                    )
                },
                enabled = !state.saving,
                modifier = Modifier.fillMaxWidth(),
            ) {
                if (state.saving) {
                    CircularProgressIndicator(
                        Modifier.size(18.dp),
                        strokeWidth = 2.dp,
                        color = MaterialTheme.colorScheme.onPrimary,
                    )
                } else {
                    Text("Save lead")
                }
            }

            Spacer(Modifier.height(32.dp))
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun LookupDropdown(
    label: String,
    options: List<NamedIdDto>,
    selected: NamedIdDto?,
    onSelected: (NamedIdDto?) -> Unit,
) {
    var expanded by remember { mutableStateOf(false) }

    ExposedDropdownMenuBox(
        expanded = expanded,
        onExpandedChange = { expanded = it },
    ) {
        OutlinedTextField(
            value = selected?.name.orEmpty(),
            onValueChange = { },
            readOnly = true,
            label = { Text(label) },
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = expanded) },
            modifier = Modifier
                .fillMaxWidth()
                .menuAnchor(androidx.compose.material3.MenuAnchorType.PrimaryNotEditable),
        )

        ExposedDropdownMenu(
            expanded = expanded,
            onDismissRequest = { expanded = false },
        ) {
            DropdownMenuItem(
                text = { Text("None") },
                onClick = {
                    onSelected(null)
                    expanded = false
                },
            )

            options.forEach { option ->
                DropdownMenuItem(
                    text = { Text(option.name) },
                    onClick = {
                        onSelected(option)
                        expanded = false
                    },
                )
            }
        }
    }
}
