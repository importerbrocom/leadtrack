package com.agency.leadmanager.call

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log
import com.agency.leadmanager.ServiceLocator
import com.agency.leadmanager.data.ApiResult
import com.agency.leadmanager.data.remote.dto.CreateLeadRequest
import com.agency.leadmanager.notif.Notifier
import com.agency.leadmanager.sync.SyncScheduler
import com.agency.leadmanager.util.PhoneUtils
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

/**
 * Handles the buttons on the post-call notification.
 *
 * "Yes, add lead" has to work in one tap, from the notification shade, with the
 * app nowhere in sight - so the whole save happens here rather than opening a
 * screen. If there is no signal the lead is queued locally and syncs later.
 */
class PostCallActionReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        val pendingCallId = intent.getLongExtra(EXTRA_PENDING_CALL_ID, -1L)
        val notifier = Notifier(context)

        when (intent.action) {
            ACTION_DISMISS -> {
                if (pendingCallId > 0) {
                    notifier.cancelPostCallPrompt(pendingCallId)
                }
                // The call itself stays recorded; the office still sees it as a
                // call to a number that is not a lead. Only the prompt goes away.
            }

            ACTION_ADD_LEAD -> {
                val phone = intent.getStringExtra(EXTRA_PHONE)
                if (phone.isNullOrBlank()) {
                    return
                }

                // Dismiss immediately so the tap feels instant.
                if (pendingCallId > 0) {
                    notifier.cancelPostCallPrompt(pendingCallId)
                }

                val suggested = intent.getStringExtra(EXTRA_SUGGESTED_NAME)

                // A lead must have a name. Prefer the phonebook name, otherwise
                // fall back to the number so the save never fails - the office
                // can rename it later.
                val name = suggested?.trim()?.takeIf { it.isNotBlank() }
                    ?: PhoneUtils.formatForDisplay(phone)

                // goAsync() would be neater, but a BroadcastReceiver only gets a
                // few seconds either way. The work is queued locally first, so
                // even if the process dies the lead is not lost.
                val pending = goAsync()

                CoroutineScope(SupervisorJob() + Dispatchers.IO).launch {
                    try {
                        val locator = ServiceLocator.from(context)

                        val result = locator.leadRepository.createLead(
                            CreateLeadRequest(
                                name = name,
                                phone = phone,
                                priority = "medium",
                                status = "contacted",
                            )
                        )

                        when (result) {
                            is ApiResult.Success -> {
                                // Link the captured call to the new lead so the
                                // duration counts towards it.
                                if (pendingCallId > 0) {
                                    locator.callRepository.attachOutcome(
                                        pendingCallId = pendingCallId,
                                        leadId = result.data.id,
                                        disposition = null,
                                        notes = null,
                                        statusSet = null,
                                        nextFollowUpAtMillis = null,
                                    )
                                }
                                notifier.showLeadSaved(name, offline = false)
                            }

                            is ApiResult.Failure -> {
                                // Offline: createLead already queued it. The call
                                // keeps the raw number, so the server matches the
                                // two up on the next sync.
                                notifier.showLeadSaved(name, offline = result.isOffline)

                                if (!result.isOffline) {
                                    Log.w(TAG, "Could not add lead: ${result.message}")
                                }
                            }
                        }

                        SyncScheduler.syncNow(context)
                    } catch (e: Exception) {
                        Log.e(TAG, "Failed to add lead from the notification", e)
                    } finally {
                        pending.finish()
                    }
                }
            }
        }
    }

    companion object {
        private const val TAG = "PostCallAction"

        private const val ACTION_ADD_LEAD = "com.agency.leadmanager.ADD_LEAD"
        private const val ACTION_DISMISS = "com.agency.leadmanager.DISMISS_PROMPT"

        private const val EXTRA_PENDING_CALL_ID = "pending_call_id"
        private const val EXTRA_PHONE = "phone"
        private const val EXTRA_SUGGESTED_NAME = "suggested_name"

        fun addLeadIntent(
            context: Context,
            pendingCallId: Long,
            phoneNumber: String,
            suggestedName: String?,
        ): Intent = Intent(context, PostCallActionReceiver::class.java).apply {
            action = ACTION_ADD_LEAD
            putExtra(EXTRA_PENDING_CALL_ID, pendingCallId)
            putExtra(EXTRA_PHONE, phoneNumber)
            putExtra(EXTRA_SUGGESTED_NAME, suggestedName)
        }

        fun dismissIntent(context: Context, pendingCallId: Long): Intent =
            Intent(context, PostCallActionReceiver::class.java).apply {
                action = ACTION_DISMISS
                putExtra(EXTRA_PENDING_CALL_ID, pendingCallId)
            }
    }
}
