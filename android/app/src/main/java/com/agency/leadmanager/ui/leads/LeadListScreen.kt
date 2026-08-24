package com.agency.leadmanager.ui.leads

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
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Phone
import androidx.compose.material.icons.filled.PersonSearch
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
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
import com.agency.leadmanager.data.remote.dto.LeadDto
import com.agency.leadmanager.ui.appViewModel
import com.agency.leadmanager.ui.components.EmptyState
import com.agency.leadmanager.ui.components.OfflineBanner
import com.agency.leadmanager.ui.components.SectionCard
import com.agency.leadmanager.ui.components.StatusChip
import com.agency.leadmanager.ui.dial
import com.agency.leadmanager.util.DateUtils
import com.agency.leadmanager.util.PhoneUtils
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

/** Filter tabs across the top of the list. */
private val filters = listOf(
    "all" to "All",
    "new" to "New",
    "interested" to "Interested",
    "follow_up" to "Follow up",
    "documents_pending" to "Documents",
    "contacted" to "Contacted",
    "converted" to "Converted",
    "not_interested" to "Not interested",
)

data class LeadListUiState(
    val loading: Boolean = true,
    val leads: List<LeadDto> = emptyList(),
    val filter: String = "all",
    val search: String = "",
    val error: String? = null,
    val page: Int = 1,
    val hasMore: Boolean = false,
    val loadingMore: Boolean = false,
)

class LeadListViewModel(private val locator: ServiceLocator) : ViewModel() {

    private val _state = MutableStateFlow(LeadListUiState())
    val state = _state.asStateFlow()

    val pendingCalls = locator.callRepository.pendingCountFlow

    private var searchJob: Job? = null

    init {
        load()
    }

    fun setFilter(filter: String) {
        _state.update { it.copy(filter = filter, page = 1) }
        load()
    }

    fun setSearch(query: String) {
        _state.update { it.copy(search = query) }

        // Debounce so we do not fire a request per keystroke.
        searchJob?.cancel()
        searchJob = viewModelScope.launch {
            delay(350)
            _state.update { it.copy(page = 1) }
            load()
        }
    }

    fun load() {
        val current = _state.value
        _state.update { it.copy(loading = true, error = null) }

        viewModelScope.launch {
            val result = locator.leadRepository.leads(
                status = current.filter.takeIf { it != "all" },
                search = current.search.trim().takeIf { it.isNotBlank() },
                sort = "recent",
                page = 1,
            )

            when (result) {
                is ApiResult.Success -> _state.update {
                    it.copy(loading = false, leads = result.data, page = 1, hasMore = result.data.size >= 30)
                }

                is ApiResult.Failure -> {
                    // Offline: fall back to whatever is cached locally.
                    val cached = locator.leadRepository.cachedSearch(current.search)
                    _state.update {
                        it.copy(
                            loading = false,
                            error = result.message,
                            leads = if (cached.isEmpty()) it.leads else it.leads,
                        )
                    }
                }
            }
        }
    }

    fun loadMore() {
        val current = _state.value
        if (current.loadingMore || !current.hasMore) return

        _state.update { it.copy(loadingMore = true) }

        viewModelScope.launch {
            val nextPage = current.page + 1
            val result = locator.leadRepository.leads(
                status = current.filter.takeIf { it != "all" },
                search = current.search.trim().takeIf { it.isNotBlank() },
                sort = "recent",
                page = nextPage,
            )

            when (result) {
                is ApiResult.Success -> _state.update {
                    it.copy(
                        loadingMore = false,
                        leads = it.leads + result.data,
                        page = nextPage,
                        hasMore = result.data.size >= 30,
                    )
                }

                is ApiResult.Failure -> _state.update {
                    it.copy(loadingMore = false, hasMore = false)
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun LeadListScreen(
    onOpenLead: (Long) -> Unit,
    onAddLead: () -> Unit,
) {
    val vm: LeadListViewModel = appViewModel { LeadListViewModel(it) }
    val state by vm.state.collectAsState()
    val pending by vm.pendingCalls.collectAsState(initial = 0)
    val context = LocalContext.current

    var searchVisible by remember { mutableStateOf(false) }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Leads") },
                actions = {
                    IconButton(onClick = { searchVisible = !searchVisible }) {
                        Icon(Icons.Default.Search, contentDescription = "Search")
                    }
                },
            )
        },
        floatingActionButton = {
            FloatingActionButton(onClick = onAddLead) {
                Icon(Icons.Default.Add, contentDescription = "Add lead")
            }
        },
    ) { padding ->
        Column(Modifier.padding(padding).fillMaxSize()) {

            OfflineBanner(pendingCount = pending)

            if (searchVisible) {
                OutlinedTextField(
                    value = state.search,
                    onValueChange = { vm.setSearch(it) },
                    placeholder = { Text("Search name, phone or city") },
                    singleLine = true,
                    leadingIcon = { Icon(Icons.Default.Search, contentDescription = null) },
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(horizontal = 16.dp, vertical = 8.dp),
                )
            }

            LazyRow(
                contentPadding = PaddingValues(horizontal = 16.dp, vertical = 4.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                items(filters) { (value, label) ->
                    FilterChip(
                        selected = state.filter == value,
                        onClick = { vm.setFilter(value) },
                        label = { Text(label, style = MaterialTheme.typography.labelMedium) },
                    )
                }
            }

            when {
                state.loading && state.leads.isEmpty() ->
                    Column(
                        Modifier.fillMaxSize(),
                        horizontalAlignment = Alignment.CenterHorizontally,
                        verticalArrangement = Arrangement.Center,
                    ) { CircularProgressIndicator() }

                state.leads.isEmpty() ->
                    EmptyState(
                        title = if (state.search.isNotBlank()) {
                            "No leads match \"${state.search}\""
                        } else {
                            "No leads here yet"
                        },
                        subtitle = state.error ?: "Tap + to add your first lead",
                        icon = Icons.Default.PersonSearch,
                    )

                else -> LazyColumn(
                    modifier = Modifier.fillMaxSize(),
                    contentPadding = PaddingValues(16.dp),
                    verticalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    items(state.leads, key = { it.id }) { lead ->
                        LeadRow(
                            lead = lead,
                            onClick = { onOpenLead(lead.id) },
                            onCall = { dial(context, lead.phone) },
                        )
                    }

                    if (state.hasMore) {
                        item {
                            Column(
                                Modifier
                                    .fillMaxWidth()
                                    .clickable { vm.loadMore() }
                                    .padding(16.dp),
                                horizontalAlignment = Alignment.CenterHorizontally,
                            ) {
                                if (state.loadingMore) {
                                    CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp)
                                } else {
                                    Text(
                                        "Load more",
                                        style = MaterialTheme.typography.labelLarge,
                                        color = MaterialTheme.colorScheme.primary,
                                    )
                                }
                            }
                        }
                    }

                    item { Spacer(Modifier.height(64.dp)) }
                }
            }
        }
    }
}

@Composable
private fun LeadRow(
    lead: LeadDto,
    onClick: () -> Unit,
    onCall: () -> Unit,
) {
    SectionCard(modifier = Modifier.clickable { onClick() }) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        lead.name,
                        style = MaterialTheme.typography.bodyLarge,
                        fontWeight = FontWeight.Medium,
                    )
                    if (lead.priority == "high") {
                        Spacer(Modifier.size(6.dp))
                        Text(
                            "HIGH",
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.error,
                            fontWeight = FontWeight.Bold,
                        )
                    }
                }

                Text(
                    buildString {
                        append(PhoneUtils.formatForDisplay(lead.phone))
                        lead.city?.takeIf { it.isNotBlank() }?.let { append(" · $it") }
                    },
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )

                Spacer(Modifier.height(4.dp))

                Row(verticalAlignment = Alignment.CenterVertically) {
                    StatusChip(lead.status)

                    if (lead.callCount > 0) {
                        Spacer(Modifier.size(8.dp))
                        Text(
                            "${lead.callCount} call${if (lead.callCount == 1) "" else "s"}" +
                                (lead.talkTimeDisplay?.let { " · $it" } ?: ""),
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }

                lead.nextFollowUpAt?.let { next ->
                    val overdue = DateUtils.isOverdue(next)
                    Text(
                        text = (if (overdue) "Overdue: " else "Call back ") + DateUtils.pretty(next),
                        style = MaterialTheme.typography.labelSmall,
                        color = if (overdue) {
                            MaterialTheme.colorScheme.error
                        } else {
                            MaterialTheme.colorScheme.onSurfaceVariant
                        },
                    )
                }
            }

            IconButton(onClick = onCall) {
                Icon(
                    Icons.Default.Phone,
                    contentDescription = "Call ${lead.name}",
                    tint = MaterialTheme.colorScheme.primary,
                )
            }
        }
    }
}
