package com.agency.leadmanager.call

import android.content.Context
import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.CallMade
import androidx.compose.material.icons.automirrored.filled.CallMissed
import androidx.compose.material.icons.automirrored.filled.CallReceived
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.AssistChip
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.lifecycleScope
import com.agency.leadmanager.ServiceLocator
import com.agency.leadmanager.data.ApiResult
import com.agency.leadmanager.data.remote.dto.CreateLeadRequest
import com.agency.leadmanager.sync.SyncScheduler
import com.agency.leadmanager.ui.theme.LeadManagerTheme
import com.agency.leadmanager.util.DateUtils
import com.agency.leadmanager.util.Labels
import com.agency.leadmanager.util.PhoneUtils
import kotlinx.coroutines.launch

/**
 * The popup that appears the moment a call ends.
 *
 * This is where the product lives or dies: the number and the exact duration
 * are already filled in, so all the telecaller has to do is tap the outcome and
 * when to call back. Two taps and it is logged.
 *
 * If the number is not a lead yet, the same sheet offers to save it as one.
 */
class PostCallActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        val pendingCallId = intent.getLongExtra(EXTRA_PENDING_CALL_ID, -1L)
        val phoneNumber = intent.getStringExtra(EXTRA_PHONE) ?: ""
        val durationSec = intent.getIntExtra(EXTRA_DURATION, 0)
        val direction = intent.getStringExtra(EXTRA_DIRECTION) ?: "outgoing"
        val leadId = intent.getLongExtra(EXTRA_LEAD_ID, -1L).takeIf { it > 0 }
        val leadName = intent.getStringExtra(EXTRA_LEAD_NAME)
        val leadStatus = intent.getStringExtra(EXTRA_LEAD_STATUS)

        if (pendingCallId <= 0) {
            finish()
            return
        }

        setContent {
            LeadManagerTheme {
                PostCallSheet(
                    phoneNumber = phoneNumber,
                    durationSec = durationSec,
                    direction = direction,
                    leadId = leadId,
                    leadName = leadName,
                    leadStatus = leadStatus,
                    onDismiss = { finish() },
                    onSave = { outcome ->
                        save(pendingCallId, leadId, phoneNumber, outcome)
                    },
                )
            }
        }
    }

    /**
     * Persist locally first, then try the network. The popup closes instantly
     * either way - a telecaller should never wait on a spinner between calls.
     */
    private fun save(
        pendingCallId: Long,
        leadId: Long?,
        phoneNumber: String,
        outcome: CallOutcome,
    ) {
        val locator = ServiceLocator.from(applicationContext)

        lifecycleScope.launch {
            var resolvedLeadId = leadId

            // Brand new number the telecaller decided to keep.
            if (resolvedLeadId == null && outcome.newLeadName != null) {
                val created = locator.leadRepository.createLead(
                    CreateLeadRequest(
                        name = outcome.newLeadName,
                        phone = phoneNumber,
                        priority = "medium",
                        status = outcome.status ?: "contacted",
                        nextFollowUpAt = outcome.nextFollowUpAtMillis?.let { DateUtils.toApi(it) },
                        notes = outcome.notes,
                    )
                )

                if (created is ApiResult.Success) {
                    resolvedLeadId = created.data.id
                }
                // If that failed we still keep the call: the lead is queued in
                // pending_leads and the call keeps the raw number, so the server
                // will match them up on the next sync.
            }

            locator.callRepository.attachOutcome(
                pendingCallId = pendingCallId,
                leadId = resolvedLeadId,
                disposition = outcome.disposition,
                notes = outcome.notes,
                statusSet = outcome.status,
                nextFollowUpAtMillis = outcome.nextFollowUpAtMillis,
            )

            SyncScheduler.syncNow(applicationContext)
        }

        finish()
    }

    companion object {
        private const val EXTRA_PENDING_CALL_ID = "pending_call_id"
        private const val EXTRA_PHONE = "phone"
        private const val EXTRA_DURATION = "duration"
        private const val EXTRA_DIRECTION = "direction"
        private const val EXTRA_LEAD_ID = "lead_id"
        private const val EXTRA_LEAD_NAME = "lead_name"
        private const val EXTRA_LEAD_STATUS = "lead_status"

        fun launch(
            context: Context,
            pendingCallId: Long,
            phoneNumber: String,
            durationSec: Int,
            direction: String,
            leadId: Long?,
            leadName: String?,
            leadStatus: String?,
        ) {
            val intent = Intent(context, PostCallActivity::class.java).apply {
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP)
                putExtra(EXTRA_PENDING_CALL_ID, pendingCallId)
                putExtra(EXTRA_PHONE, phoneNumber)
                putExtra(EXTRA_DURATION, durationSec)
                putExtra(EXTRA_DIRECTION, direction)
                putExtra(EXTRA_LEAD_ID, leadId ?: -1L)
                putExtra(EXTRA_LEAD_NAME, leadName)
                putExtra(EXTRA_LEAD_STATUS, leadStatus)
            }

            context.startActivity(intent)
        }
    }
}

/** What the telecaller chose in the popup. */
data class CallOutcome(
    val disposition: String?,
    val status: String?,
    val notes: String?,
    val nextFollowUpAtMillis: Long?,
    val newLeadName: String? = null,
)

@OptIn(ExperimentalMaterial3Api::class, androidx.compose.foundation.layout.ExperimentalLayoutApi::class)
@Composable
private fun PostCallSheet(
    phoneNumber: String,
    durationSec: Int,
    direction: String,
    leadId: Long?,
    leadName: String?,
    leadStatus: String?,
    onDismiss: () -> Unit,
    onSave: (CallOutcome) -> Unit,
) {
    var disposition by remember {
        mutableStateOf(if (durationSec > 0) "connected" else "no_answer")
    }
    var status by remember { mutableStateOf<String?>(null) }
    var notes by remember { mutableStateOf("") }
    var callbackMillis by remember { mutableStateOf<Long?>(null) }
    var newLeadName by remember { mutableStateOf("") }
    var saving by remember { mutableStateOf(false) }

    val isKnownLead = leadId != null

    AlertDialog(
        onDismissRequest = onDismiss,
        title = {
            Column {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(
                        imageVector = when (direction) {
                            "incoming" -> Icons.AutoMirrored.Filled.CallReceived
                            "missed", "rejected" -> Icons.AutoMirrored.Filled.CallMissed
                            else -> Icons.AutoMirrored.Filled.CallMade
                        },
                        contentDescription = null,
                        tint = MaterialTheme.colorScheme.primary,
                    )
                    Spacer(Modifier.fillMaxWidth(0.03f))
                    Text(
                        text = leadName ?: PhoneUtils.formatForDisplay(phoneNumber),
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.SemiBold,
                    )
                }

                Spacer(Modifier.height(4.dp))

                // The two facts the telecaller no longer has to type.
                Text(
                    text = buildString {
                        append(PhoneUtils.formatForDisplay(phoneNumber))
                        append("  ·  ")
                        append(
                            if (durationSec > 0) {
                                "Talked ${DateUtils.duration(durationSec)}"
                            } else {
                                "Not connected"
                            }
                        )
                    },
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )

                if (leadStatus != null) {
                    Text(
                        text = "Currently: ${Labels.pretty(leadStatus)}",
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
        },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {

                if (!isKnownLead) {
                    Text(
                        "This number is not saved as a lead. Add it?",
                        style = MaterialTheme.typography.bodySmall,
                        fontWeight = FontWeight.Medium,
                    )
                    Spacer(Modifier.height(6.dp))
                    OutlinedTextField(
                        value = newLeadName,
                        onValueChange = { newLeadName = it },
                        label = { Text("Candidate name") },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )
                    Spacer(Modifier.height(14.dp))
                }

                Text("What happened?", style = MaterialTheme.typography.labelLarge)
                Spacer(Modifier.height(6.dp))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    Labels.callDispositions.forEach { (value, label) ->
                        FilterChip(
                            selected = disposition == value,
                            onClick = { disposition = value },
                            label = { Text(label, style = MaterialTheme.typography.labelSmall) },
                        )
                    }
                }

                Spacer(Modifier.height(14.dp))

                Text("Update lead status", style = MaterialTheme.typography.labelLarge)
                Spacer(Modifier.height(6.dp))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    Labels.leadStatusChoices.forEach { (value, label) ->
                        FilterChip(
                            selected = status == value,
                            onClick = { status = if (status == value) null else value },
                            label = { Text(label, style = MaterialTheme.typography.labelSmall) },
                        )
                    }
                }

                Spacer(Modifier.height(14.dp))

                Text("Call back", style = MaterialTheme.typography.labelLarge)
                Spacer(Modifier.height(6.dp))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    val options = listOf(
                        "In 1 hour" to DateUtils.inHours(1),
                        "In 3 hours" to DateUtils.inHours(3),
                        "Tomorrow 10am" to DateUtils.tomorrowAt(10),
                        "In 3 days" to DateUtils.inDays(3),
                        "Next week" to DateUtils.inDays(7),
                    )

                    options.forEach { (label, millis) ->
                        FilterChip(
                            selected = callbackMillis == millis,
                            onClick = {
                                callbackMillis = if (callbackMillis == millis) null else millis
                            },
                            label = { Text(label, style = MaterialTheme.typography.labelSmall) },
                        )
                    }
                }

                if (callbackMillis != null) {
                    Spacer(Modifier.height(6.dp))
                    AssistChip(
                        onClick = { callbackMillis = null },
                        label = {
                            Text(
                                "Reminder set for ${DateUtils.prettyTime(callbackMillis!!)}",
                                style = MaterialTheme.typography.labelSmall,
                            )
                        },
                    )
                }

                Spacer(Modifier.height(14.dp))

                OutlinedTextField(
                    value = notes,
                    onValueChange = { notes = it },
                    label = { Text("Notes (optional)") },
                    placeholder = { Text("What did they say?") },
                    minLines = 2,
                    maxLines = 4,
                    modifier = Modifier.fillMaxWidth(),
                )
            }
        },
        confirmButton = {
            Button(
                enabled = !saving,
                onClick = {
                    saving = true
                    onSave(
                        CallOutcome(
                            disposition = disposition,
                            status = status,
                            notes = notes.trim().takeIf { it.isNotBlank() },
                            nextFollowUpAtMillis = callbackMillis,
                            newLeadName = newLeadName.trim().takeIf { !isKnownLead && it.isNotBlank() },
                        )
                    )
                }
            ) {
                if (saving) {
                    CircularProgressIndicator(
                        modifier = Modifier.height(16.dp),
                        strokeWidth = 2.dp,
                    )
                } else {
                    Text("Save")
                }
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) { Text("Skip") }
        },
    )
}
