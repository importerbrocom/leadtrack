package com.agency.leadmanager.notif

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/**
 * Fires a callback reminder that was scheduled with AlarmManager for an exact
 * time. The periodic [com.agency.leadmanager.sync.ReminderWorker] is the
 * fallback; this gives minute-accurate reminders for callbacks the telecaller
 * set from the app.
 */
class ReminderReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        val leadId = intent.getLongExtra(EXTRA_LEAD_ID, -1L)
        val leadName = intent.getStringExtra(EXTRA_LEAD_NAME) ?: return
        val leadPhone = intent.getStringExtra(EXTRA_LEAD_PHONE) ?: return
        val followUpId = intent.getLongExtra(EXTRA_FOLLOW_UP_ID, leadId)

        if (leadId <= 0) return

        Notifier(context).showCallbackReminder(
            followUpId = followUpId,
            leadId = leadId,
            leadName = leadName,
            leadPhone = leadPhone,
            scheduledText = "Scheduled callback",
            overdue = false,
        )
    }

    companion object {
        const val EXTRA_LEAD_ID = "lead_id"
        const val EXTRA_LEAD_NAME = "lead_name"
        const val EXTRA_LEAD_PHONE = "lead_phone"
        const val EXTRA_FOLLOW_UP_ID = "follow_up_id"
    }
}
