package com.agency.leadmanager.ui.projects

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
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
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Phone
import androidx.compose.material.icons.filled.RadioButtonUnchecked
import androidx.compose.material.icons.filled.UploadFile
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.MenuAnchorType
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
import com.agency.leadmanager.data.remote.dto.ChecklistItemDto
import com.agency.leadmanager.data.remote.dto.DocumentTypeDto
import com.agency.leadmanager.data.remote.dto.ProjectDto
import com.agency.leadmanager.ui.appViewModel
import com.agency.leadmanager.ui.components.DetailRow
import com.agency.leadmanager.ui.components.SectionCard
import com.agency.leadmanager.ui.components.StatusChip
import com.agency.leadmanager.ui.dial
import com.agency.leadmanager.util.DateUtils
import com.agency.leadmanager.util.Labels
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class ProjectDetailUiState(
    val loading: Boolean = true,
    val project: ProjectDto? = null,
    val documentTypes: List<DocumentTypeDto> = emptyList(),
    val saving: Boolean = false,
    val uploading: Boolean = false,
    val message: String? = null,
    val error: String? = null,
    val isAdmin: Boolean = false,
)

class ProjectDetailViewModel(
    private val locator: ServiceLocator,
    private val projectId: Long,
) : ViewModel() {

    private val _state = MutableStateFlow(ProjectDetailUiState())
    val state = _state.asStateFlow()

    init {
        load()

        viewModelScope.launch {
            val user = locator.session.user()
            _state.update { it.copy(isAdmin = user?.isAdmin == true) }

            val types = locator.documentRepository.documentTypes()
            if (types is ApiResult.Success) {
                _state.update { it.copy(documentTypes = types.data) }
            }
        }
    }

    fun load() {
        _state.update { it.copy(loading = true) }

        viewModelScope.launch {
            when (val result = locator.projectRepository.project(projectId)) {
                is ApiResult.Success ->
                    _state.update { it.copy(loading = false, project = result.data, error = null) }

                is ApiResult.Failure ->
                    _state.update { it.copy(loading = false, error = result.message) }
            }
        }
    }

    fun updateStatus(status: String, remarks: String?) {
        _state.update { it.copy(saving = true) }

        viewModelScope.launch {
            when (val result = locator.projectRepository.updateStatus(projectId, status, remarks)) {
                is ApiResult.Success ->
                    _state.update {
                        it.copy(saving = false, project = result.data, message = "Stage updated")
                    }

                is ApiResult.Failure ->
                    _state.update { it.copy(saving = false, error = result.message) }
            }
        }
    }

    /** Upload a filled form or a scan the candidate handed over. */
    fun upload(uri: Uri, fileName: String?, documentTypeId: Int?, title: String?) {
        _state.update { it.copy(uploading = true) }

        viewModelScope.launch {
            val result = locator.documentRepository.uploadDocument(
                uri = uri,
                displayName = fileName,
                projectId = projectId,
                documentTypeId = documentTypeId,
                title = title,
            )

            when (result) {
                is ApiResult.Success -> {
                    _state.update { it.copy(uploading = false, message = "Document uploaded") }
                    load()
                }

                is ApiResult.Failure ->
                    _state.update { it.copy(uploading = false, error = result.message) }
            }
        }
    }

    fun consumeMessage() = _state.update { it.copy(message = null, error = null) }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ProjectDetailScreen(
    projectId: Long,
    onBack: () -> Unit,
) {
    val vm: ProjectDetailViewModel = appViewModel(key = "project-$projectId") {
        ProjectDetailViewModel(it, projectId)
    }
    val state by vm.state.collectAsState()
    val context = LocalContext.current
    val snackbar = remember { SnackbarHostState() }

    var showStageDialog by remember { mutableStateOf(false) }
    var pendingUpload by remember { mutableStateOf<Uri?>(null) }

    val filePicker = rememberLauncherForActivityResult(
        ActivityResultContracts.GetContent()
    ) { uri -> pendingUpload = uri }

    LaunchedEffect(state.message, state.error) {
        val text = state.message ?: state.error
        if (text != null) {
            snackbar.showSnackbar(text)
            vm.consumeMessage()
        }
    }

    val project = state.project

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(project?.candidateName ?: "Project") },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
                actions = {
                    project?.let {
                        IconButton(onClick = { dial(context, it.candidatePhone) }) {
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

        if (project == null) {
            Column(
                Modifier.padding(padding).fillMaxSize(),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.Center,
            ) {
                if (state.loading) {
                    CircularProgressIndicator()
                } else {
                    Text(state.error ?: "Could not load this project")
                }
            }
            return@Scaffold
        }

        LazyColumn(
            modifier = Modifier.padding(padding).fillMaxSize(),
            contentPadding = PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            item {
                SectionCard {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(
                                project.candidateName,
                                style = MaterialTheme.typography.titleMedium,
                                fontWeight = FontWeight.SemiBold,
                            )
                            Text(
                                project.projectCode,
                                style = MaterialTheme.typography.labelSmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                        StatusChip(project.status)
                    }

                    project.documentProgress?.let { progress ->
                        Spacer(Modifier.height(12.dp))
                        Text(
                            "Documents ${progress.verified} of ${progress.required} verified",
                            style = MaterialTheme.typography.labelMedium,
                        )
                        Spacer(Modifier.height(4.dp))
                        LinearProgressIndicator(
                            progress = { progress.percentComplete / 100f },
                            modifier = Modifier.fillMaxWidth(),
                        )
                    }
                }
            }

            item {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    FilledTonalButton(
                        onClick = { showStageDialog = true },
                        modifier = Modifier.weight(1f),
                    ) { Text("Change stage") }

                    FilledTonalButton(
                        onClick = { filePicker.launch("*/*") },
                        enabled = !state.uploading,
                        modifier = Modifier.weight(1f),
                    ) {
                        if (state.uploading) {
                            CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp)
                        } else {
                            Icon(
                                Icons.Default.UploadFile,
                                contentDescription = null,
                                modifier = Modifier.size(16.dp),
                            )
                            Spacer(Modifier.size(6.dp))
                            Text("Upload")
                        }
                    }
                }
            }

            item {
                SectionCard(title = "Placement") {
                    DetailRow("Position", project.position)
                    DetailRow("Employer", project.employerName)
                    DetailRow("Country", project.destinationCountry)
                    DetailRow("Visa type", project.visaType)
                    DetailRow("Passport", project.passportNo)
                    DetailRow("Passport expiry", project.passportExpiry?.let { DateUtils.prettyDate(it) })
                    DetailRow("Interview", project.interviewDate?.let { DateUtils.pretty(it) })
                    DetailRow("Medical", project.medicalDate?.let { DateUtils.prettyDate(it) })
                    DetailRow("Deployment", project.deploymentDate?.let { DateUtils.prettyDate(it) })
                    DetailRow("Handled by", project.assignedToName)

                    if (state.isAdmin && project.agreedAmount != null) {
                        Spacer(Modifier.height(8.dp))
                        DetailRow("Agreed", "₹${project.agreedAmount}")
                        DetailRow("Received", "₹${project.paidAmount ?: 0.0}")
                        DetailRow("Balance", "₹${project.balanceAmount ?: 0.0}")
                    }
                }
            }

            // -------------------------------------------------- checklist
            item {
                Text(
                    "Document checklist",
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.SemiBold,
                )
            }

            val checklist = project.checklist.orEmpty()

            if (checklist.isEmpty()) {
                item {
                    SectionCard {
                        Text(
                            "No checklist configured",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }
            } else {
                item {
                    SectionCard {
                        checklist.forEach { item -> ChecklistRow(item) }
                    }
                }
            }

            // -------------------------------------------------- documents
            val documents = project.documents.orEmpty()
            if (documents.isNotEmpty()) {
                item {
                    Text(
                        "Uploaded documents",
                        style = MaterialTheme.typography.titleSmall,
                        fontWeight = FontWeight.SemiBold,
                    )
                }

                items(documents, key = { it.id }) { doc ->
                    SectionCard {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(
                                    doc.title?.takeIf { it.isNotBlank() } ?: doc.fileName,
                                    style = MaterialTheme.typography.bodyMedium,
                                    fontWeight = FontWeight.Medium,
                                )
                                Text(
                                    buildString {
                                        doc.documentTypeName?.let { append(it).append(" · ") }
                                        append(doc.fileSizeDisplay ?: "")
                                    },
                                    style = MaterialTheme.typography.labelSmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                                if (!doc.rejectReason.isNullOrBlank()) {
                                    Text(
                                        "Rejected: ${doc.rejectReason}",
                                        style = MaterialTheme.typography.labelSmall,
                                        color = MaterialTheme.colorScheme.error,
                                    )
                                }
                                Text(
                                    "${doc.uploadedByName ?: ""} · ${DateUtils.relative(doc.createdAt)}",
                                    style = MaterialTheme.typography.labelSmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                            }
                            StatusChip(doc.verificationStatus)
                        }
                    }
                }
            }

            // -------------------------------------------------- history
            val history = project.statusHistory.orEmpty()
            if (history.isNotEmpty()) {
                item {
                    SectionCard(title = "Stage history") {
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

    // Same reason as LeadDetailScreen: the guard above is inside the Scaffold
    // lambda, so re-establish non-nullness for the dialogs.
    val loadedProject = state.project ?: return

    if (showStageDialog) {
        StageDialog(
            current = loadedProject.status,
            isAdmin = state.isAdmin,
            saving = state.saving,
            onDismiss = { showStageDialog = false },
            onConfirm = { status, remarks ->
                vm.updateStatus(status, remarks)
                showStageDialog = false
            },
        )
    }

    pendingUpload?.let { uri ->
        UploadDialog(
            documentTypes = state.documentTypes,
            onDismiss = { pendingUpload = null },
            onConfirm = { typeId, title ->
                vm.upload(uri, title.takeIf { it.isNotBlank() }, typeId, title.takeIf { it.isNotBlank() })
                pendingUpload = null
            },
        )
    }
}

@Composable
private fun ChecklistRow(item: ChecklistItemDto) {
    Row(
        Modifier
            .fillMaxWidth()
            .padding(vertical = 5.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(
            imageVector = if (item.status == "verified") {
                Icons.Default.CheckCircle
            } else {
                Icons.Default.RadioButtonUnchecked
            },
            contentDescription = null,
            modifier = Modifier.size(16.dp),
            tint = if (item.status == "verified") {
                com.agency.leadmanager.ui.theme.StatusConverted
            } else {
                MaterialTheme.colorScheme.onSurfaceVariant
            },
        )

        Spacer(Modifier.size(10.dp))

        Text(
            text = item.name + if (item.isRequired) " *" else "",
            style = MaterialTheme.typography.bodySmall,
            modifier = Modifier.weight(1f),
        )

        if (item.status == "missing") {
            Text(
                "Missing",
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        } else {
            StatusChip(item.status)
        }
    }
}

@Composable
private fun StageDialog(
    current: String,
    isAdmin: Boolean,
    saving: Boolean,
    onDismiss: () -> Unit,
    onConfirm: (String, String?) -> Unit,
) {
    var selected by remember { mutableStateOf(current) }
    var remarks by remember { mutableStateOf("") }

    val options = Labels.projectStatuses.filter {
        isAdmin || it !in Labels.adminOnlyProjectStatuses
    }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Change stage") },
        text = {
            Column {
                options.forEach { status ->
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .padding(vertical = 3.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        androidx.compose.material3.RadioButton(
                            selected = selected == status,
                            onClick = { selected = status },
                        )
                        Text(Labels.pretty(status), style = MaterialTheme.typography.bodySmall)
                    }
                }

                Spacer(Modifier.height(8.dp))

                OutlinedTextField(
                    value = remarks,
                    onValueChange = { remarks = it },
                    label = { Text("Remarks") },
                    modifier = Modifier.fillMaxWidth(),
                )
            }
        },
        confirmButton = {
            Button(
                enabled = !saving,
                onClick = { onConfirm(selected, remarks.trim().takeIf { it.isNotBlank() }) },
            ) { Text("Save") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
    )
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun UploadDialog(
    documentTypes: List<DocumentTypeDto>,
    onDismiss: () -> Unit,
    onConfirm: (Int?, String) -> Unit,
) {
    var selectedType by remember { mutableStateOf<DocumentTypeDto?>(null) }
    var title by remember { mutableStateOf("") }
    var expanded by remember { mutableStateOf(false) }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Upload document") },
        text = {
            Column {
                Text(
                    "Head office will verify this once it arrives.",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )

                Spacer(Modifier.height(12.dp))

                ExposedDropdownMenuBox(
                    expanded = expanded,
                    onExpandedChange = { expanded = it },
                ) {
                    OutlinedTextField(
                        value = selectedType?.name ?: "Other",
                        onValueChange = { },
                        readOnly = true,
                        label = { Text("Document type") },
                        trailingIcon = {
                            ExposedDropdownMenuDefaults.TrailingIcon(expanded = expanded)
                        },
                        modifier = Modifier
                            .fillMaxWidth()
                            .menuAnchor(MenuAnchorType.PrimaryNotEditable),
                    )

                    ExposedDropdownMenu(
                        expanded = expanded,
                        onDismissRequest = { expanded = false },
                    ) {
                        documentTypes.forEach { type ->
                            DropdownMenuItem(
                                text = { Text(type.name) },
                                onClick = {
                                    selectedType = type
                                    expanded = false
                                },
                            )
                        }
                    }
                }

                Spacer(Modifier.height(10.dp))

                OutlinedTextField(
                    value = title,
                    onValueChange = { title = it },
                    label = { Text("Label (optional)") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
            }
        },
        confirmButton = {
            Button(onClick = { onConfirm(selectedType?.id, title) }) { Text("Upload") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
    )
}
