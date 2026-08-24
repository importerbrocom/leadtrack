package com.agency.leadmanager.ui.projects

import androidx.compose.foundation.clickable
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
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.WorkOutline
import androidx.compose.material3.Badge
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
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
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.agency.leadmanager.ServiceLocator
import com.agency.leadmanager.data.ApiResult
import com.agency.leadmanager.data.remote.dto.ProjectDto
import com.agency.leadmanager.ui.appViewModel
import com.agency.leadmanager.ui.components.EmptyState
import com.agency.leadmanager.ui.components.SectionCard
import com.agency.leadmanager.ui.components.StatusChip
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class ProjectListUiState(
    val loading: Boolean = true,
    val projects: List<ProjectDto> = emptyList(),
    val search: String = "",
    val error: String? = null,
)

class ProjectListViewModel(private val locator: ServiceLocator) : ViewModel() {

    private val _state = MutableStateFlow(ProjectListUiState())
    val state = _state.asStateFlow()

    private var searchJob: Job? = null

    init {
        load()
    }

    fun setSearch(query: String) {
        _state.update { it.copy(search = query) }
        searchJob?.cancel()
        searchJob = viewModelScope.launch {
            delay(350)
            load()
        }
    }

    fun load() {
        _state.update { it.copy(loading = true, error = null) }

        viewModelScope.launch {
            val result = locator.projectRepository.projects(
                search = _state.value.search.trim().takeIf { it.isNotBlank() }
            )

            when (result) {
                is ApiResult.Success ->
                    _state.update { it.copy(loading = false, projects = result.data) }

                is ApiResult.Failure ->
                    _state.update { it.copy(loading = false, error = result.message) }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ProjectListScreen(onOpenProject: (Long) -> Unit) {
    val vm: ProjectListViewModel = appViewModel { ProjectListViewModel(it) }
    val state by vm.state.collectAsState()

    var searchVisible by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) { vm.load() }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Projects") },
                actions = {
                    IconButton(onClick = { searchVisible = !searchVisible }) {
                        Icon(Icons.Default.Search, contentDescription = "Search")
                    }
                },
            )
        }
    ) { padding ->
        Column(Modifier.padding(padding).fillMaxSize()) {

            if (searchVisible) {
                OutlinedTextField(
                    value = state.search,
                    onValueChange = { vm.setSearch(it) },
                    placeholder = { Text("Name, code or passport number") },
                    singleLine = true,
                    leadingIcon = { Icon(Icons.Default.Search, contentDescription = null) },
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(horizontal = 16.dp, vertical = 8.dp),
                )
            }

            when {
                state.loading && state.projects.isEmpty() ->
                    Column(
                        Modifier.fillMaxSize(),
                        horizontalAlignment = Alignment.CenterHorizontally,
                        verticalArrangement = Arrangement.Center,
                    ) { CircularProgressIndicator() }

                state.projects.isEmpty() ->
                    EmptyState(
                        title = "No projects yet",
                        subtitle = state.error
                            ?: "Convert an interested lead to start a placement case",
                        icon = Icons.Default.WorkOutline,
                    )

                else -> LazyColumn(
                    contentPadding = PaddingValues(16.dp),
                    verticalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    items(state.projects, key = { it.id }) { project ->
                        SectionCard(modifier = Modifier.clickable { onOpenProject(project.id) }) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Column(Modifier.weight(1f)) {
                                    Text(
                                        project.candidateName,
                                        style = MaterialTheme.typography.bodyLarge,
                                        fontWeight = FontWeight.Medium,
                                    )
                                    Text(
                                        project.projectCode,
                                        style = MaterialTheme.typography.labelSmall,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    )
                                    Text(
                                        buildString {
                                            project.position?.takeIf { it.isNotBlank() }?.let { append(it) }
                                            project.destinationCountry?.takeIf { it.isNotBlank() }?.let {
                                                if (isNotEmpty()) append(" · ")
                                                append(it)
                                            }
                                        }.ifBlank { project.candidatePhone },
                                        style = MaterialTheme.typography.bodySmall,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    )
                                }

                                Column(horizontalAlignment = Alignment.End) {
                                    StatusChip(project.status)
                                    if (project.pendingDocumentCount > 0) {
                                        Spacer(Modifier.height(4.dp))
                                        Badge {
                                            Text("${project.pendingDocumentCount} pending")
                                        }
                                    }
                                }
                            }

                            project.documentProgress?.let { progress ->
                                Spacer(Modifier.height(8.dp))
                                LinearProgressIndicator(
                                    progress = { progress.percentComplete / 100f },
                                    modifier = Modifier.fillMaxWidth(),
                                )
                            }
                        }
                    }

                    item { Spacer(Modifier.height(24.dp)) }
                }
            }
        }
    }
}
