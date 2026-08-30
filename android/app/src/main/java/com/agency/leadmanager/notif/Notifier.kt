package com.agency.leadmanager.notif

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.net.Uri
import com.agency.leadmanager.R
import com.agency.leadmanager.ui.MainActivity
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat

/**
 * All notifications in one place: the call-capture foreground notice and the
 * "time to call X back" reminders.
 */
class Notifier(private val context: Context) {

    private val manager: NotificationManager =
        context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager

    /** The quiet, low-priority notice shown while a call is being recorded. */
    fun callCaptureNotification(): Notification =
        NotificationCompat.Builder(context, CHANNEL_CALL_CAPTURE)
            .setSmallIcon(R.drawable.ic_stat_call)
            .setContentTitle("Saving call details")
            .setPriority(NotificationCompat.PRIORITY_MIN)
            .setSilent(true)
            .setOngoing(true)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .build()

    /**
     * The prompt shown the moment a call ends.
     *
     * This is a notification rather than a popup window on purpose: since
     * Android 10, an app with no visible window is not allowed to launch an
     * activity from the background, and a call ending is exactly that situation.
     * A notification always gets through.
     *
     * For a number that is not yet a lead it offers a one-tap "Yes, it's a lead".
     * For an existing lead it offers to log the outcome. Tapping the body always
     * opens the full sheet (status, callback time, notes).
     */
    fun showPostCallPrompt(
        pendingCallId: Long,
        phoneNumber: String,
        displayName: String?,
        durationSec: Int,
        direction: String,
        leadId: Long?,
        leadName: String?,
        leadStatus: String?,
        suggestedName: String?,
    ) {
        val isKnownLead = leadId != null
        val who = leadName ?: displayName ?: phoneNumber

        val duration = if (durationSec > 0) {
            "Talked " + com.agency.leadmanager.util.DateUtils.duration(durationSec)
        } else {
            when (direction) {
                "missed" -> "Missed call"
                "incoming" -> "Not answered"
                else -> "No answer"
            }
        }

        // Tapping the notification body opens the full sheet. Starting an
        // activity from a notification tap is user-initiated, so it is allowed.
        val openSheet = PendingIntent.getActivity(
            context,
            pendingCallId.toInt(),
            com.agency.leadmanager.call.PostCallActivity.intentFor(
                context = context,
                pendingCallId = pendingCallId,
                phoneNumber = phoneNumber,
                durationSec = durationSec,
                direction = direction,
                leadId = leadId,
                leadName = leadName,
                leadStatus = leadStatus,
                contactName = displayName,
            ),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val builder = NotificationCompat.Builder(context, CHANNEL_POST_CALL)
            .setSmallIcon(R.drawable.ic_stat_call)
            .setContentTitle(
                if (isKnownLead) who else "Is this a lead?"
            )
            .setContentText(
                if (isKnownLead) {
                    "$duration · tap to log the outcome"
                } else {
                    "$who · $duration"
                }
            )
            .setStyle(
                NotificationCompat.BigTextStyle().bigText(
                    if (isKnownLead) {
                        "$who — $duration.\nTap to set the status and when to call back."
                    } else {
                        "$who — $duration.\nTap Yes to save this number as a lead."
                    }
                )
            )
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(NotificationCompat.CATEGORY_CALL)
            .setAutoCancel(true)
            .setOnlyAlertOnce(true)
            .setContentIntent(openSheet)

        if (isKnownLead) {
            builder.addAction(
                R.drawable.ic_stat_call,
                "Log outcome",
                openSheet
            )
        } else {
            // One tap creates the lead, using the phonebook name when we have it.
            builder.addAction(
                R.drawable.ic_stat_call,
                "Yes, add lead",
                PendingIntent.getBroadcast(
                    context,
                    (pendingCallId * 10 + 1).toInt(),
                    com.agency.leadmanager.call.PostCallActionReceiver.addLeadIntent(
                        context = context,
                        pendingCallId = pendingCallId,
                        phoneNumber = phoneNumber,
                        suggestedName = suggestedName,
                    ),
                    PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
                )
            )
        }

        builder.addAction(
            R.drawable.ic_stat_call,
            if (isKnownLead) "Skip" else "No",
            PendingIntent.getBroadcast(
                context,
                (pendingCallId * 10 + 2).toInt(),
                com.agency.leadmanager.call.PostCallActionReceiver.dismissIntent(context, pendingCallId),
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
            )
        )

        notify(postCallNotificationId(pendingCallId), builder.build())
    }

    fun cancelPostCallPrompt(pendingCallId: Long) {
        NotificationManagerCompat.from(context).cancel(postCallNotificationId(pendingCallId))
    }

    /** Brief confirmation after a one-tap lead save. */
    fun showLeadSaved(name: String, offline: Boolean) {
        val builder = NotificationCompat.Builder(context, CHANNEL_GENERAL)
            .setSmallIcon(R.drawable.ic_stat_call)
            .setContentTitle("Lead saved")
            .setContentText(
                if (offline) {
                    "$name saved on this phone, will sync when you are online"
                } else {
                    "$name added"
                }
            )
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)
            .setAutoCancel(true)
            .setTimeoutAfter(8_000)

        notify(ID_LEAD_SAVED, builder.build())
    }

    /** "Call Rajesh Kumar back now" */
    fun showCallbackReminder(
        followUpId: Long,
        leadId: Long,
        leadName: String,
        leadPhone: String,
        scheduledText: String,
        overdue: Boolean,
    ) {
        val openApp = PendingIntent.getActivity(
            context,
            leadId.toInt(),
            Intent(context, MainActivity::class.java).apply {
                flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
                putExtra(MainActivity.EXTRA_OPEN_LEAD_ID, leadId)
            },
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val dial = PendingIntent.getActivity(
            context,
            (leadId + 1_000_000).toInt(),
            Intent(Intent.ACTION_DIAL, Uri.parse("tel:$leadPhone")).apply {
                flags = Intent.FLAG_ACTIVITY_NEW_TASK
            },
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val notification = NotificationCompat.Builder(context, CHANNEL_REMINDERS)
            .setSmallIcon(R.drawable.ic_stat_call)
            .setContentTitle(if (overdue) "Overdue: call $leadName" else "Call $leadName")
            .setContentText("$leadPhone · $scheduledText")
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(NotificationCompat.CATEGORY_REMINDER)
            .setAutoCancel(true)
            .setContentIntent(openApp)
            .addAction(R.drawable.ic_stat_call, "Call now", dial)
            .build()

        notify(REMINDER_ID_BASE + followUpId.toInt(), notification)
    }

    fun showGeneral(id: Int, title: String, body: String?) {
        val openApp = PendingIntent.getActivity(
            context,
            id,
            Intent(context, MainActivity::class.java).apply {
                flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
            },
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val notification = NotificationCompat.Builder(context, CHANNEL_GENERAL)
            .setSmallIcon(R.drawable.ic_stat_call)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)
            .setAutoCancel(true)
            .setContentIntent(openApp)
            .build()

        notify(id, notification)
    }

    private fun notify(id: Int, notification: Notification) {
        try {
            NotificationManagerCompat.from(context).notify(id, notification)
        } catch (e: SecurityException) {
            // POST_NOTIFICATIONS was refused; nothing else to do.
        }
    }

    companion object {
        const val CHANNEL_CALL_CAPTURE = "call_capture"
        const val CHANNEL_POST_CALL = "post_call_prompt"
        const val CHANNEL_REMINDERS = "callback_reminders"
        const val CHANNEL_GENERAL = "general_updates"

        const val ID_CALL_CAPTURE = 1001
        private const val ID_LEAD_SAVED = 1002
        private const val REMINDER_ID_BASE = 20_000
        private const val POST_CALL_ID_BASE = 30_000

        private fun postCallNotificationId(pendingCallId: Long): Int =
            POST_CALL_ID_BASE + (pendingCallId % 1000).toInt()

        /** Called once from Application.onCreate(). */
        fun createChannels(context: Context) {
            val manager =
                context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager

            val capture = NotificationChannel(
                CHANNEL_CALL_CAPTURE,
                context.getString(R.string.channel_call_capture_name),
                NotificationManager.IMPORTANCE_MIN
            ).apply {
                description = context.getString(R.string.channel_call_capture_desc)
                setShowBadge(false)
            }

            // The post-call prompt is the app's most important moment, so it gets
            // its own high-importance channel that the user can tune separately.
            val postCall = NotificationChannel(
                CHANNEL_POST_CALL,
                context.getString(R.string.channel_post_call_name),
                NotificationManager.IMPORTANCE_HIGH
            ).apply {
                description = context.getString(R.string.channel_post_call_desc)
                enableVibration(true)
                setShowBadge(true)
            }

            val reminders = NotificationChannel(
                CHANNEL_REMINDERS,
                context.getString(R.string.channel_reminders_name),
                NotificationManager.IMPORTANCE_HIGH
            ).apply {
                description = context.getString(R.string.channel_reminders_desc)
                enableVibration(true)
            }

            val general = NotificationChannel(
                CHANNEL_GENERAL,
                context.getString(R.string.channel_general_name),
                NotificationManager.IMPORTANCE_DEFAULT
            ).apply {
                description = context.getString(R.string.channel_general_desc)
            }

            manager.createNotificationChannels(listOf(capture, postCall, reminders, general))
        }
    }
}
