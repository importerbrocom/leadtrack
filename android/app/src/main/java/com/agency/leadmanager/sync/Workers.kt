package com.agency.leadmanager.sync

import android.content.Context
import android.util.Log
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import com.agency.leadmanager.ServiceLocator
import com.agency.leadmanager.call.CallLogScanner
import com.agency.leadmanager.data.ApiResult
import com.agency.leadmanager.notif.Notifier
import com.agency.leadmanager.util.DateUtils

/**
 * Drains the outbox: captured calls first (they are the most perishable), then
 * offline-created leads and status changes.
 */
class SyncWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val locator = ServiceLocator.from(applicationContext)

        if (locator.session.token().isNullOrBlank()) {
            // Nobody signed in: nothing to push, and retrying would be pointless.
            return Result.success()
        }

        return try {
            val calls = locator.callRepository.syncPending()

            if (calls < 0) {
                // No network. WorkManager will retry with backoff.
                return Result.retry()
            }

            val leads = locator.leadRepository.flushPendingLeads()
            val statuses = locator.leadRepository.flushPendingStatusUpdates()

            if (calls > 0 || leads > 0 || statuses > 0) {
                Log.d(TAG, "Synced $calls call(s), $leads lead(s), $statuses status update(s)")
            }

            Result.success()
        } catch (e: Exception) {
            Log.e(TAG, "Sync failed", e)
            Result.retry()
        }
    }

    companion object {
        private const val TAG = "SyncWorker"
    }
}

/**
 * Catch-up scan of the device call log.
 *
 * The live receiver handles the normal case, but it can miss calls: the app may
 * have been force-stopped, the phone rebooted, or an OEM battery manager may
 * have blocked the service. This runs periodically, reads anything newer than
 * the last watermark, and queues whatever is missing.
 */
class CallCatchUpWorker(context: Context, params: WorkerParameters) :
    CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val locator = ServiceLocator.from(applicationContext)

        if (locator.session.token().isNullOrBlank()) return Result.success()
        if (!locator.session.callTrackingEnabled()) return Result.success()

        val scanner = CallLogScanner(applicationContext)
        if (!scanner.hasPermission()) return Result.success()

        val since = locator.session.lastCallScanAt().let { last ->
            // First run: only look back a day, never import the whole history.
            if (last <= 0L) System.currentTimeMillis() - 24 * 60 * 60 * 1000L else last
        }

        val calls = scanner.callsSince(since)

        if (calls.isEmpty()) {
            locator.leadRepository.refreshCache()
            return Result.success()
        }

        var newest = since

        // Oldest first so the queue keeps chronological order.
        calls.sortedBy { it.startedAtMillis }.forEach { call ->
            val lookup = locator.leadRepository.lookupByPhone(call.number)
            val leadId = (lookup as? com.agency.leadmanager.data.repo.LeadLookupResult.Found)?.leadId

            locator.callRepository.recordCall(
                deviceCallId = call.deviceCallId,
                phoneNumber = call.number,
                direction = call.direction,
                startedAtMillis = call.startedAtMillis,
                durationSec = call.durationSec,
                simSlot = call.simSlot,
                leadId = leadId,
            )

            if (call.startedAtMillis > newest) newest = call.startedAtMillis
        }

        locator.session.setLastCallScanAt(newest)
        Log.d(TAG, "Catch-up queued ${calls.size} call(s)")

        SyncScheduler.syncNow(applicationContext)

        return Result.success()
    }

    companion object {
        private const val TAG = "CallCatchUpWorker"
    }
}

/**
 * Raises local notifications for callbacks that are due, and keeps the offline
 * lead cache warm.
 */
class ReminderWorker(context: Context, params: WorkerParameters) :
    CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val locator = ServiceLocator.from(applicationContext)

        if (locator.session.token().isNullOrBlank()) return Result.success()

        val result = locator.leadRepository.dueFollowUps()

        if (result is ApiResult.Success) {
            val notifier = Notifier(applicationContext)

            result.data.forEach { followUp ->
                notifier.showCallbackReminder(
                    followUpId = followUp.id,
                    leadId = followUp.leadId,
                    leadName = followUp.leadName,
                    leadPhone = followUp.leadPhone,
                    scheduledText = DateUtils.pretty(followUp.scheduledAt),
                    overdue = followUp.isOverdue,
                )
            }

            locator.leadRepository.refreshCache()
            return Result.success()
        }

        return if ((result as ApiResult.Failure).isOffline) Result.retry() else Result.success()
    }
}
