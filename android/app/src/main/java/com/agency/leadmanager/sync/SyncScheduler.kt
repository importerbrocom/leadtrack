package com.agency.leadmanager.sync

import android.content.Context
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import java.util.concurrent.TimeUnit

/**
 * All the background scheduling in one place.
 */
object SyncScheduler {

    private const val WORK_SYNC_NOW = "sync_now"
    private const val WORK_SYNC_PERIODIC = "sync_periodic"
    private const val WORK_CALL_CATCH_UP = "call_catch_up"
    private const val WORK_REMINDERS = "callback_reminders"

    private val networkRequired = Constraints.Builder()
        .setRequiredNetworkType(NetworkType.CONNECTED)
        .build()

    /**
     * Push the outbox as soon as there is a network. Called right after a call
     * is captured and after the popup is saved.
     */
    fun syncNow(context: Context) {
        val request = OneTimeWorkRequestBuilder<SyncWorker>()
            .setConstraints(networkRequired)
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
            .build()

        WorkManager.getInstance(context)
            .enqueueUniqueWork(WORK_SYNC_NOW, ExistingWorkPolicy.REPLACE, request)
    }

    /**
     * Start the recurring jobs. Safe to call repeatedly - KEEP means an existing
     * schedule is left alone.
     */
    fun scheduleRecurring(context: Context) {
        val manager = WorkManager.getInstance(context)

        // Safety net for the outbox, in case a one-off request was dropped.
        manager.enqueueUniquePeriodicWork(
            WORK_SYNC_PERIODIC,
            ExistingPeriodicWorkPolicy.KEEP,
            PeriodicWorkRequestBuilder<SyncWorker>(30, TimeUnit.MINUTES)
                .setConstraints(networkRequired)
                .build()
        )

        // Catches calls the live receiver missed.
        manager.enqueueUniquePeriodicWork(
            WORK_CALL_CATCH_UP,
            ExistingPeriodicWorkPolicy.KEEP,
            PeriodicWorkRequestBuilder<CallCatchUpWorker>(15, TimeUnit.MINUTES)
                .build()
        )

        // Callback reminders.
        manager.enqueueUniquePeriodicWork(
            WORK_REMINDERS,
            ExistingPeriodicWorkPolicy.KEEP,
            PeriodicWorkRequestBuilder<ReminderWorker>(15, TimeUnit.MINUTES)
                .setConstraints(networkRequired)
                .build()
        )
    }

    /** Run the catch-up scan immediately, e.g. just after permissions are granted. */
    fun catchUpNow(context: Context) {
        val request = OneTimeWorkRequestBuilder<CallCatchUpWorker>().build()

        WorkManager.getInstance(context)
            .enqueueUniqueWork(WORK_CALL_CATCH_UP + "_now", ExistingWorkPolicy.REPLACE, request)
    }

    fun refreshRemindersNow(context: Context) {
        val request = OneTimeWorkRequestBuilder<ReminderWorker>()
            .setConstraints(networkRequired)
            .build()

        WorkManager.getInstance(context)
            .enqueueUniqueWork(WORK_REMINDERS + "_now", ExistingWorkPolicy.REPLACE, request)
    }

    /** On sign-out, stop the recurring work but keep the outbox intact. */
    fun cancelRecurring(context: Context) {
        val manager = WorkManager.getInstance(context)
        manager.cancelUniqueWork(WORK_SYNC_PERIODIC)
        manager.cancelUniqueWork(WORK_CALL_CATCH_UP)
        manager.cancelUniqueWork(WORK_REMINDERS)
    }
}
