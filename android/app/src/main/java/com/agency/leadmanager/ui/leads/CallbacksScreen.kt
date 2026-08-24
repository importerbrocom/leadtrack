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
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.EventAvailable
import androidx.compose.material.icons.filled.Phone
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.agency.leadmanager.ServiceLocator
import com.agency.leadmanager.data.ApiResult
import com.agency.leadmanager.data.remote.dto.FollowUpDto
import com.agency.leadmanager.ui.appViewModel
import com.agency.leadmanager.ui.components.EmptyState
import com.agency.leadmanager.ui.components.SectionCard
import com.agency.leadmanager.ui.components.StatusChip
import com.agency.leadmanager.ui.dial
import com.agency.leadmanager.util.DateUtils
import com.agency.leadmanager.util.PhoneUtils
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

private val buckets = listOf(
    "today" to "Today",
    "overdue" to "Overdue",
    "week" to "Next 7 days",
    "upcoming" to "Upcoming",
)

data class CallbacksUiState(
    val loading: Boolean = true,
    val bucket: String = "today",
    val items: List<FollowUpDto> = emptyList(),
    val error: String? = null,
)

class CallbacksViewModel(private val locator: ServiceLocator) : ViewModel() {

    private val _state = MutableStateFlow(CallbacksUiState())
    val state = _state.asStateFlow()

    init {
        load()
    }

    fun setBucket(bucket: String) {
        _state.update { it.copy(bucket = bucket) }
        load()
    }

    fun load() {
        _state.update { it.copy(loading = true, error = null) }

        viewModelScope.launch {
            when (val result = locator.leadRepository.followUps(_state.value.bucket)) {
                is ApiResult.Success ->
                    _state.update { it.copy(loading = false, items = result.data) }

                is ApiResult.Failure ->
                    _state.update { it.copy(loading = false, error = result.message) }
            }
        }
    }

    fun markDone(id: Long) {
        viewModelScope.launch {
            locator.leadRepository.completeFollowUp(id)
            load()
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CallbacksScreen(onOpenLead: (Long) -> Unit) {
    val vm: CallbacksViewModel = appViewModel { CallbacksViewModel(it) }
    val state by vm.state.collectAsState()
    val context = LocalContext.current

    LaunchedEffect(Unit) { vm.load() }

    Scaffold(
        topBar = { TopAppBar(title = { Text("Callbacks") }) }
    ) { padding ->
        Column(Modifier.padding(padding).fillMaxSize()) {

            LazyRow(
                contentPadding = PaddingValues(horizontal = 16.dp, vertical = 8.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                items(buckets) { (value, label) ->
                    FilterChip(
                        selected = state.bucket == value,
                        onClick = { vm.setBucket(value) },
                        label = { Text(label, style = MaterialTheme.typography.labelMedium) },
                    )
                }
            }

            when {
                state.loading && state.items.isEmpty() ->
                    Column(
                        Modifier.fillMaxSize(),
                        horizontalAlignment = Alignment.CenterHorizontally,
                        verticalArrangement = Arrangement.Center,
                    ) { CircularProgressIndicator() }

                state.items.isEmpty() ->
                    EmptyState(
                        title = when (state.bucket) {
                            "overdue" -> "Nothing overdue"
                            "today" -> "No callbacks due today"
                            else -> "No callbacks scheduled"
                        },
                        subtitle = state.error
                            ?: "Set a callback from a lead, or right after a call ends",
                        icon = Icons.Default.EventAvailable,
                    )

                else -> LazyColumn(
                    contentPadding = PaddingValues(16.dp),
                    verticalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    items(state.items, key = { it.id }) { item ->
                        SectionCard(modifier = Modifier.clickable { onOpenLead(item.leadId) }) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Column(Modifier.weight(1f)) {
                                    Row(verticalAlignment = Alignment.CenterVertically) {
                                        Text(
                                            item.leadName,
                                            style = MaterialTheme.typography.bodyLarge,
                                            fontWeight = FontWeight.Medium,
                                        )
                                        if (item.leadPriority == "high") {
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
                                            append(PhoneUtils.formatForDisplay(item.leadPhone))
                                            item.leadCity?.takeIf { it.isNotBlank() }
                                                ?.let { append(" · $it") }
                                        },
                                        style = MaterialTheme.typography.bodySmall,
                                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    )

                                    Text(
                                        text = DateUtils.pretty(item.scheduledAt) +
                                            " (" + DateUtils.relative(item.scheduledAt) + ")",
                                        style = MaterialTheme.typography.labelMedium,
                                        color = if (item.isOverdue) {
                                            MaterialTheme.colorScheme.error
                                        } else {
                                            MaterialTheme.colorScheme.primary
                                        },
                                    )

                                    if (!item.remarks.isNullOrBlank()) {
                                        Text(
                                            item.remarks,
                                            style = MaterialTheme.typography.labelSmall,
                                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                                        )
                                    }

                                    item.leadStatus?.let {
                                        Spacer(Modifier.height(4.dp))
                                        StatusChip(it)
                                    }
                                }

                                IconButton(onClick = { vm.markDone(item.id) }) {
                                    Icon(Icons.Default.Check, contentDescription = "Mark done")
                                }

                                IconButton(onClick = { dial(context, item.leadPhone) }) {
                                    Icon(
                                        Icons.Default.Phone,
                                        contentDescription = "Call",
                                        tint = MaterialTheme.colorScheme.primary,
                                    )
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
