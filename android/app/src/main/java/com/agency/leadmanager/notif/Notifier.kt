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
        const val CHANNEL_REMINDERS = "callback_reminders"
        const val CHANNEL_GENERAL = "general_updates"

        const val ID_CALL_CAPTURE = 1001
        private const val REMINDER_ID_BASE = 20_000

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

            manager.createNotificationChannels(listOf(capture, reminders, general))
        }
    }
}
