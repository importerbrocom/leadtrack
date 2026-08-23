package com.agency.leadmanager.ui.forms

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Description
import androidx.compose.material.icons.filled.Download
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.core.content.FileProvider
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.agency.leadmanager.ServiceLocator
import com.agency.leadmanager.data.ApiResult
import com.agency.leadmanager.data.remote.dto.FormTemplateDto
import com.agency.leadmanager.ui.appViewModel
import com.agency.leadmanager.ui.components.EmptyState
import com.agency.leadmanager.ui.components.SectionCard
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import java.io.File

data class FormsUiState(
    val loading: Boolean = true,
    val templates: List<FormTemplateDto> = emptyList(),
    val downloadingId: Int? = null,
    val message: String? = null,
    val error: String? = null,
    val openFile: File? = null,
)

class FormsViewModel(private val locator: ServiceLocator) : ViewModel() {

    private val _state = MutableStateFlow(FormsUiState())
    val state = _state.asStateFlow()

    init {
        load()
    }

    fun load() {
        _state.update { it.copy(loading = true, error = null) }

        viewModelScope.launch {
            when (val result = locator.documentRepository.formTemplates()) {
                is ApiResult.Success ->
                    _state.update { it.copy(loading = false, templates = result.data) }

                is ApiResult.Failure ->
                    _state.update { it.copy(loading = false, error = result.message) }
            }
        }
    }

    fun download(template: FormTemplateDto) {
        _state.update { it.copy(downloadingId = template.id) }

        viewModelScope.launch {
            when (val result = locator.documentRepository.downloadTemplate(template)) {
                is ApiResult.Success ->
                    _state.update {
                        it.copy(
                            downloadingId = null,
                            message = "Saved ${template.fileName}",
                            openFile = result.data,
                        )
                    }

                is ApiResult.Failure ->
                    _state.update { it.copy(downloadingId = null, error = result.message) }
            }
        }
    }

    fun consumeMessage() = _state.update { it.copy(message = null, error = null) }
    fun consumeFile() = _state.update { it.copy(openFile = null) }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun FormsScreen() {
    val vm: FormsViewModel = appViewModel { FormsViewModel(it) }
    val state by vm.state.collectAsState()
    val context = LocalContext.current
    val snackbar = remember { SnackbarHostState() }

    LaunchedEffect(state.message, state.error) {
        val text = state.message ?: state.error
        if (text != null) {
            snackbar.showSnackbar(text)
            vm.consumeMessage()
        }
    }

    // Offer to open the file as soon as it lands.
    LaunchedEffect(state.openFile) {
        val file = state.openFile ?: return@LaunchedEffect

        try {
            val uri: Uri = FileProvider.getUriForFile(
                context,
                "${context.packageName}.fileprovider",
                file
            )

            context.startActivity(
                Intent(Intent.ACTION_VIEW).apply {
                    setDataAndType(uri, context.contentResolver.getType(uri) ?: "*/*")
                    addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
                }
            )
        } catch (e: Exception) {
            // No viewer installed for this file type - the file is still saved.
        }

        vm.consumeFile()
    }

    Scaffold(
        topBar = { TopAppBar(title = { Text("Forms") }) },
        snackbarHost = { SnackbarHost(snackbar) },
    ) { padding ->
        Column(Modifier.padding(padding).fillMaxSize()) {
            when {
                state.loading && state.templates.isEmpty() ->
                    Column(
                        Modifier.fillMaxSize(),
                        horizontalAlignment = Alignment.CenterHorizontally,
                        verticalArrangement = Arrangement.Center,
                    ) { CircularProgressIndicator() }

                state.templates.isEmpty() ->
                    EmptyState(
                        title = "No forms yet",
                        subtitle = state.error
                            ?: "Head office will upload the application and agreement forms here",
                        icon = Icons.Default.Description,
                    )

                else -> LazyColumn(
                    contentPadding = PaddingValues(16.dp),
                    verticalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    item {
                        Text(
                            "Download a form, get it filled and signed by the candidate, " +
                                "then upload the scan from the project screen.",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }

                    items(state.templates, key = { it.id }) { template ->
                        SectionCard {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Column(Modifier.weight(1f)) {
                                    Text(
                                        template.title,
                                        style = MaterialTheme.typography.bodyLarge,
                                        fontWeight = FontWeight.Medium,
                                    )
                                    if (!template.description.isNullOrBlank()) {
                                        Text(
                                            template.description,
                                            style = MaterialTheme.typography.bodySmall,
                                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                                        )
                                    }
                                    Text(
                                        buildString {
                                            append(template.fileName)
                                            template.fileSizeDisplay?.let { append(" · $it") }
                                            template.version?.let { append(" · v$it") }
                                        },
                                        style = MaterialTheme.typography.labelSmall,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    )
                                }

                                if (state.downloadingId == template.id) {
                                    CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp)
                                } else {
                                    IconButton(onClick = { vm.download(template) }) {
                                        Icon(
                                            Icons.Default.Download,
                                            contentDescription = "Download ${template.title}",
                                            tint = MaterialTheme.colorScheme.primary,
                                        )
                                    }
                                }
                            }
                        }
                    }

                    item { Spacer(Modifier.height(24.dp)) }
                }
            }
        }
    }
}
