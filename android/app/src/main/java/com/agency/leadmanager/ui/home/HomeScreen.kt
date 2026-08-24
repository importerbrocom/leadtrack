package com.agency.leadmanager.ui.home

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
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
import androidx.compose.material.icons.filled.Groups
import androidx.compose.material.icons.filled.Phone
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.agency.leadmanager.ServiceLocator
import com.agency.leadmanager.data.ApiResult
import com.agency.leadmanager.data.prefs.StoredUser
import com.agency.leadmanager.data.remote.dto.DashboardDto
import com.agency.leadmanager.data.remote.dto.FollowUpDto
import com.agency.leadmanager.ui.appViewModel
import com.agency.leadmanager.ui.components.EmptyState
import com.agency.leadmanager.ui.components.OfflineBanner
import com.agency.leadmanager.ui.components.SectionCard
import com.agency.leadmanager.ui.components.StatTile
import com.agency.leadmanager.ui.components.StatusChip
import com.agency.leadmanager.ui.dial
import com.agency.leadmanager.ui.theme.StatusConverted
import com.agency.leadmanager.ui.theme.StatusFollowUp
import com.agency.leadmanager.ui.theme.StatusInterested
import com.agency.leadmanager.ui.theme.StatusLost
import com.agency.leadmanager.util.DateUtils
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class HomeUiState(
    val loading: Boolean = true,
    val dashboard: DashboardDto? = null,
    val todaysCallbacks: List<FollowUpDto> = emptyList(),
    val error: String? = null,
)

class HomeViewModel(private val locator: ServiceLocator) : ViewModel() {

    private val _state = MutableStateFlow(HomeUiState())
    val state = _state.asStateFlow()

    val user = locator.session.userFlow
    val pendingCalls = locator.callRepository.pendingCountFlow

    init {
        refresh()
    }

    fun refresh() {
        _state.update { it.copy(loading = true, error = null) }

        viewModelScope.launch {
            val dashboard = locator.dashboardRepository.dashboard()
            val callbacks = locator.leadRepository.followUps("today")

            _state.update { current ->
                current.copy(
                    loading = false,
                    dashboard = (dashboard as? ApiResult.Success)?.data ?: current.dashboard,
                    todaysCallbacks = (callbacks as? ApiResult.Success)?.data ?: emptyList(),
                    error = (dashboard as? ApiResult.Failure)?.message,
                )
            }
        }
    }

    fun markDone(followUpId: Long) {
        viewModelScope.launch {
            locator.leadRepository.completeFollowUp(followUpId)
            refresh()
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun HomeScreen(
    onOpenLead: (Long) -> Unit,
    onOpenLeads: () -> Unit,
    onOpenCallbacks: () -> Unit,
    onOpenTeam: () -> Unit,
    onOpenSettings: () -> Unit,
) {
    val vm: HomeViewModel = appViewModel { HomeViewModel(it) }
    val state by vm.state.collectAsState()
    val user by vm.user.collectAsState(initial = null)
    val pending by vm.pendingCalls.collectAsState(initial = 0)
    val context = androidx.compose.ui.platform.LocalContext.current

    LaunchedEffect(Unit) { vm.refresh() }

    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    Column {
                        Text(
                            text = greeting(user),
                            style = MaterialTheme.typography.titleMedium,
                        )
                        Text(
                            text = user?.agencyName ?: roleLabel(user),
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                },
                actions = {
                    if (user?.isPartner == true || user?.isAdmin == true) {
                        IconButton(onClick = onOpenTeam) {
                            Icon(Icons.Default.Groups, contentDescription = "Team")
                        }
                    }
                    IconButton(onClick = { vm.refresh() }) {
                        Icon(Icons.Default.Refresh, contentDescription = "Refresh")
                    }
                    IconButton(onClick = onOpenSettings) {
                        Icon(Icons.Default.Settings, contentDescription = "Settings")
                    }
                },
            )
        }
    ) { padding ->
        Column(Modifier.padding(padding).fillMaxSize()) {

            OfflineBanner(pendingCount = pending)

            if (state.loading && state.dashboard == null) {
                Column(
                    Modifier.fillMaxSize(),
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.Center,
                ) { CircularProgressIndicator() }
                return@Column
            }

            LazyColumn(
                modifier = Modifier.fillMaxSize(),
                contentPadding = androidx.compose.foundation.layout.PaddingValues(16.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                val dash = state.dashboard

                item {
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        StatTile(
                            value = (dash?.calls?.today ?: 0).toString(),
                            label = "Calls today",
                            accent = StatusInterested,
                            modifier = Modifier.weight(1f),
                        )
                        StatTile(
                            value = dash?.calls?.todayTalkTime ?: "0s",
                            label = "Talk time",
                            accent = StatusConverted,
                            modifier = Modifier.weight(1f),
                        )
                    }
                }

                item {
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        StatTile(
                            value = (dash?.followUps?.today ?: 0).toString(),
                            label = "Callbacks today",
                            accent = StatusFollowUp,
                            modifier = Modifier
                                .weight(1f)
                                .clickable { onOpenCallbacks() },
                        )
                        StatTile(
                            value = (dash?.followUps?.overdue ?: 0).toString(),
                            label = "Overdue",
                            accent = StatusLost,
                            modifier = Modifier
                                .weight(1f)
                                .clickable { onOpenCallbacks() },
                        )
                    }
                }

                item {
                    SectionCard(title = "My pipeline") {
                        val leads = dash?.leads

                        PipelineRow("New", leads?.new ?: 0, onOpenLeads)
                        PipelineRow("Contacted", leads?.contacted ?: 0, onOpenLeads)
                        PipelineRow("Interested", leads?.interested ?: 0, onOpenLeads)
                        PipelineRow("Follow up", leads?.followUp ?: 0, onOpenLeads)
                        PipelineRow("Documents pending", leads?.documentsPending ?: 0, onOpenLeads)
                        PipelineRow("Converted", leads?.converted ?: 0, onOpenLeads)

                        HorizontalDivider(Modifier.padding(vertical = 8.dp))

                        Row(
                            Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.SpaceBetween,
                        ) {
                            Text(
                                "Total leads",
                                style = MaterialTheme.typography.bodyMedium,
                                fontWeight = FontWeight.SemiBold,
                            )
                            Text(
                                "${leads?.total ?: 0}",
                                style = MaterialTheme.typography.bodyMedium,
                                fontWeight = FontWeight.SemiBold,
                            )
                        }

                        if ((dash?.conversionRate ?: 0.0) > 0) {
                            Text(
                                "${dash?.conversionRate}% converted",
                                style = MaterialTheme.typography.labelSmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                    }
                }

                item {
                    Row(
                        Modifier.fillMaxWidth().padding(top = 4.dp),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(
                            "Today's callbacks",
                            style = MaterialTheme.typography.titleSmall,
                            fontWeight = FontWeight.SemiBold,
                        )
                        Text(
                            "See all",
                            style = MaterialTheme.typography.labelMedium,
                            color = MaterialTheme.colorScheme.primary,
                            modifier = Modifier.clickable { onOpenCallbacks() },
                        )
                    }
                }

                if (state.todaysCallbacks.isEmpty()) {
                    item {
                        SectionCard {
                            EmptyState(
                                title = "No callbacks due today",
                                subtitle = "Scheduled callbacks show up here automatically",
                            )
                        }
                    }
                } else {
                    items(state.todaysCallbacks, key = { it.id }) { followUp ->
                        SectionCard {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Column(
                                    Modifier
                                        .weight(1f)
                                        .clickable { onOpenLead(followUp.leadId) }
                                ) {
                                    Text(
                                        followUp.leadName,
                                        style = MaterialTheme.typography.bodyLarge,
                                        fontWeight = FontWeight.Medium,
                                    )
                                    Text(
                                        "${followUp.leadPhone} · ${DateUtils.pretty(followUp.scheduledAt)}",
                                        style = MaterialTheme.typography.bodySmall,
                                        color = if (followUp.isOverdue) {
                                            MaterialTheme.colorScheme.error
                                        } else {
                                            MaterialTheme.colorScheme.onSurfaceVariant
                                        },
                                    )
                                    if (!followUp.remarks.isNullOrBlank()) {
                                        Text(
                                            followUp.remarks,
                                            style = MaterialTheme.typography.labelSmall,
                                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                                        )
                                    }
                                }

                                followUp.leadStatus?.let { StatusChip(it) }

                                Spacer(Modifier.size(8.dp))

                                IconButton(onClick = { dial(context, followUp.leadPhone) }) {
                                    Icon(
                                        Icons.Default.Phone,
                                        contentDescription = "Call",
                                        tint = MaterialTheme.colorScheme.primary,
                                    )
                                }
                            }
                        }
                    }
                }

                item { Spacer(Modifier.height(12.dp)) }
            }
        }
    }
}

@Composable
private fun PipelineRow(label: String, count: Int, onClick: () -> Unit) {
    Row(
        Modifier
            .fillMaxWidth()
            .clickable { onClick() }
            .padding(vertical = 5.dp),
        horizontalArrangement = Arrangement.SpaceBetween,
    ) {
        Text(label, style = MaterialTheme.typography.bodyMedium)
        Text(
            count.toString(),
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

private fun greeting(user: StoredUser?): String {
    val name = user?.name?.split(' ')?.firstOrNull().orEmpty()
    val hour = java.util.Calendar.getInstance().get(java.util.Calendar.HOUR_OF_DAY)

    val part = when {
        hour < 12 -> "Good morning"
        hour < 17 -> "Good afternoon"
        else -> "Good evening"
    }

    return if (name.isBlank()) part else "$part, $name"
}

private fun roleLabel(user: StoredUser?): String = when {
    user?.isAdmin == true -> "Head office"
    user?.isPartner == true -> "Partner"
    else -> "Telecaller"
}
