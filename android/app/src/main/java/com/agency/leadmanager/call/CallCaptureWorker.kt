package com.agency.leadmanager.call

import android.content.Context
import android.content.pm.ServiceInfo
import android.os.Build
import android.util.Log
import androidx.work.CoroutineWorker
import androidx.work.Data
import androidx.work.ExistingWorkPolicy
import androidx.work.ForegroundInfo
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.OutOfQuotaPolicy
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import com.agency.leadmanager.ServiceLocator
import com.agency.leadmanager.data.repo.LeadLookupResult
import com.agency.leadmanager.notif.Notifier
import com.agency.leadmanager.sync.SyncScheduler
import com.agency.leadmanager.ui.AppVisibility
import kotlinx.coroutines.delay

/**
 * Captures a finished call: reads it from the platform call log, records it, and
 * raises the "Is this a lead?" prompt.
 *
 * ## Why a Worker and not a Service
 *
 * This used to be a foreground Service started straight from the broadcast
 * receiver. On Android 12 and later that is forbidden: an app in the background
 * may not start a foreground service, and a PHONE_STATE broadcast is not one of
 * the exemptions. The call threw ForegroundServiceStartNotAllowedException, which
 * was caught and logged - so on every modern phone capture failed silently while
 * looking perfectly healthy: permissions granted, receiver firing, nothing ever
 * recorded.
 *
 * Expedited WorkManager work is the sanctioned route. WorkManager owns the
 * foreground service, so the platform permits it from the background, and it
 * survives the receiver returning.
 */
class CallCaptureWorker(
    private val context: Context,
    params: WorkerParameters,
) : CoroutineWorker(context, params) {

    /**
     * Required for expedited work below API 31, where WorkManager runs it as a
     * foreground service and therefore needs a notification.
     */
    override suspend fun getForegroundInfo(): ForegroundInfo {
        val notification = Notifier(context).callCaptureNotification()

        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            ForegroundInfo(
                Notifier.ID_CALL_CAPTURE,
                notification,
                ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC
            )
        } else {
            ForegroundInfo(Notifier.ID_CALL_CAPTURE, notification)
        }
    }

    override suspend fun doWork(): Result {
        val hintedNumber = inputData.getString(KEY_NUMBER)

        return try {
            capture(hintedNumber)
            Result.success()
        } catch (e: Exception) {
            Log.e(TAG, "Failed to capture the call", e)
            // The periodic catch-up scan is the backstop, so do not spin here.
            Result.success()
        }
    }

    private suspend fun capture(hintedNumber: String?) {
        val locator = ServiceLocator.from(context)

        if (!locator.session.callTrackingEnabled()) {
            Log.d(TAG, "Call tracking is switched off")
            return
        }

        if (locator.session.token().isNullOrBlank()) {
            Log.d(TAG, "Nobody signed in; ignoring the call")
            return
        }

        val scanner = CallLogScanner(context)

        if (!scanner.hasPermission()) {
            Log.w(TAG, "No call-log permission, cannot read the duration")
            return
        }

        val call = awaitCallLogRow(scanner, hintedNumber) ?: run {
            Log.w(TAG, "No call-log row appeared for this call")
            return
        }

        if (call.number.isBlank()) return

        val lookup = locator.leadRepository.lookupByPhone(call.number)
        val leadId = (lookup as? LeadLookupResult.Found)?.leadId
        val contactName = ContactLookup(context).nameFor(call.number)

        val pendingId = locator.callRepository.recordCall(
            deviceCallId = call.deviceCallId,
            phoneNumber = call.number,
            direction = call.direction,
            startedAtMillis = call.startedAtMillis,
            durationSec = call.durationSec,
            simSlot = call.simSlot,
            leadId = leadId,
        )

        // Move the watermark so the catch-up scan does not redo this call.
        if (call.startedAtMillis > locator.session.lastCallScanAt()) {
            locator.session.setLastCallScanAt(call.startedAtMillis)
        }

        Log.d(TAG, "Captured ${call.direction} call, ${call.durationSec}s, lead=$leadId")

        // Dialling someone is intent, so ask about every outgoing call even if
        // unanswered. Incoming only when answered, or spam would nag.
        val worthAsking = when {
            lookup is LeadLookupResult.Found -> true
            call.direction == "outgoing" -> true
            call.direction == "incoming" && call.durationSec > 0 -> true
            else -> false
        }

        if (worthAsking && pendingId > 0) {
            Notifier(context).showPostCallPrompt(
                pendingCallId = pendingId,
                phoneNumber = call.number,
                displayName = contactName,
                durationSec = call.durationSec,
                direction = call.direction,
                leadId = leadId,
                leadName = (lookup as? LeadLookupResult.Found)?.name,
                leadStatus = (lookup as? LeadLookupResult.Found)?.status,
                suggestedName = contactName,
            )

            // Nicer when the app is already open, but never relied upon.
            if (AppVisibility.isForeground) {
                PostCallActivity.launch(
                    context = context,
                    pendingCallId = pendingId,
                    phoneNumber = call.number,
                    durationSec = call.durationSec,
                    direction = call.direction,
                    leadId = leadId,
                    leadName = (lookup as? LeadLookupResult.Found)?.name,
                    leadStatus = (lookup as? LeadLookupResult.Found)?.status,
                    contactName = contactName,
                )
            }
        }

        SyncScheduler.syncNow(context)
    }

    /**
     * The platform writes the call-log row asynchronously, so poll briefly.
     */
    private suspend fun awaitCallLogRow(
        scanner: CallLogScanner,
        hintedNumber: String?,
    ): CallLogScanner.DeviceCall? {
        repeat(POLL_ATTEMPTS) { attempt ->
            delay(if (attempt == 0) FIRST_DELAY_MS else POLL_INTERVAL_MS)

            scanner.latestCall(matchingNumber = hintedNumber)?.let { return it }

            // If the hinted number never matches, fall back to the newest call.
            if (hintedNumber != null && attempt >= 2) {
                scanner.latestCall(matchingNumber = null)?.let { return it }
            }
        }

        return null
    }

    companion object {
        private const val TAG = "CallCaptureWorker"
        private const val KEY_NUMBER = "number"
        private const val WORK_NAME = "capture_finished_call"

        private const val POLL_ATTEMPTS = 6
        private const val FIRST_DELAY_MS = 1_200L
        private const val POLL_INTERVAL_MS = 1_000L

        /**
         * Queue capture for a call that just ended.
         *
         * Expedited so it runs within a second or two. If the app has exhausted
         * its expedited quota it silently degrades to normal work rather than
         * failing, which is the whole point of the fallback policy.
         */
        fun enqueue(context: Context, number: String?) {
            val request = OneTimeWorkRequestBuilder<CallCaptureWorker>()
                .setInputData(Data.Builder().putString(KEY_NUMBER, number).build())
                .setExpedited(OutOfQuotaPolicy.RUN_AS_NON_EXPEDITED_WORK_REQUEST)
                .build()

            // APPEND so two calls in quick succession are both captured.
            WorkManager.getInstance(context)
                .enqueueUniqueWork(WORK_NAME, ExistingWorkPolicy.APPEND_OR_REPLACE, request)
        }
    }
}
