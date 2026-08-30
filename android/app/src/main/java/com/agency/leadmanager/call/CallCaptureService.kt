package com.agency.leadmanager.call

import android.app.Service
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.IBinder
import android.util.Log
import com.agency.leadmanager.ServiceLocator
import com.agency.leadmanager.data.repo.LeadLookupResult
import com.agency.leadmanager.notif.Notifier
import com.agency.leadmanager.sync.SyncScheduler
import com.agency.leadmanager.ui.AppVisibility
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * Does the real work after a call ends.
 *
 * A short-lived foreground service, because:
 *  - the platform needs a moment to write the call-log row, so we have to wait
 *    and retry, which a BroadcastReceiver is not allowed to do;
 *  - the app is usually in the background at this point (the user was on the
 *    phone), so a plain background coroutine could be killed mid-write.
 */
class CallCaptureService : Service() {

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        startForegroundCompat()

        val number = intent?.getStringExtra(EXTRA_NUMBER)

        scope.launch {
            try {
                handleFinishedCall(number)
            } catch (e: Exception) {
                Log.e(TAG, "Failed to capture the call", e)
            } finally {
                stopSelf(startId)
            }
        }

        return START_NOT_STICKY
    }

    private fun startForegroundCompat() {
        val notification = Notifier(this).callCaptureNotification()

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(
                Notifier.ID_CALL_CAPTURE,
                notification,
                android.content.pm.ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC
            )
        } else {
            startForeground(Notifier.ID_CALL_CAPTURE, notification)
        }
    }

    /**
     * Read the finished call, queue it, and decide whether to show the popup.
     */
    private suspend fun handleFinishedCall(hintedNumber: String?) {
        val locator = ServiceLocator.from(this)

        if (!locator.session.callTrackingEnabled()) {
            Log.d(TAG, "Call tracking is switched off in settings")
            return
        }

        if (locator.session.token().isNullOrBlank()) {
            Log.d(TAG, "Nobody is signed in; ignoring the call")
            return
        }

        val scanner = CallLogScanner(this)

        if (!scanner.hasPermission()) {
            Log.w(TAG, "No call-log permission, cannot record duration")
            return
        }

        // The row is written asynchronously by the platform. Poll briefly.
        val call = awaitCallLogRow(scanner, hintedNumber) ?: run {
            Log.w(TAG, "No call-log row appeared for this call")
            return
        }

        // Ignore our own outgoing "call" events with no number at all.
        if (call.number.isBlank()) return

        val lookup = locator.leadRepository.lookupByPhone(call.number)
        val leadId = (lookup as? LeadLookupResult.Found)?.leadId

        // If the number is in the phonebook, use that name so the prompt can say
        // "Is Rajesh a lead?" and saving becomes one tap.
        val contactName = ContactLookup(this).nameFor(call.number)

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
        val previous = locator.session.lastCallScanAt()
        if (call.startedAtMillis > previous) {
            locator.session.setLastCallScanAt(call.startedAtMillis)
        }

        Log.d(TAG, "Captured ${call.direction} call, ${call.durationSec}s, lead=$leadId")

        // Should we prompt at all?
        //
        // Dialling someone is intent, so every outgoing call is worth asking
        // about even if they did not pick up. Incoming calls only count when
        // answered, otherwise every spam call would raise a prompt.
        val worthAsking = when {
            lookup is LeadLookupResult.Found -> true
            call.direction == "outgoing" -> true
            call.direction == "incoming" && call.durationSec > 0 -> true
            else -> false
        }

        if (worthAsking && pendingId > 0) {
            // A NOTIFICATION, not a window.
            //
            // Since Android 10 an app with no visible window may not start an
            // activity from the background, and a call ending is precisely that
            // situation - the popup was being silently discarded. The
            // notification always arrives, and tapping it opens the full sheet
            // (a user-initiated start, which is permitted).
            Notifier(this).showPostCallPrompt(
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

            // If the app happens to be in the foreground the sheet can also be
            // shown directly, which is a nicer experience. This is a bonus, not
            // the mechanism we rely on.
            if (AppVisibility.isForeground) {
                PostCallActivity.launch(
                    context = this,
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

        // Either way, get it to the server as soon as the network allows.
        SyncScheduler.syncNow(this)
    }

    /**
     * Wait for the platform to write the call-log row (usually well under a
     * second, occasionally a few seconds on budget phones).
     */
    private suspend fun awaitCallLogRow(
        scanner: CallLogScanner,
        hintedNumber: String?,
    ): CallLogScanner.DeviceCall? {
        repeat(POLL_ATTEMPTS) { attempt ->
            delay(if (attempt == 0) FIRST_DELAY_MS else POLL_INTERVAL_MS)

            // Prefer a row matching the number we were told about; fall back to
            // "whatever the newest call is" if the hint does not match.
            scanner.latestCall(matchingNumber = hintedNumber)?.let { return it }

            if (hintedNumber != null && attempt >= 2) {
                scanner.latestCall(matchingNumber = null)?.let { return it }
            }
        }

        return null
    }

    override fun onDestroy() {
        scope.cancel()
        super.onDestroy()
    }

    companion object {
        private const val TAG = "CallCaptureService"
        private const val EXTRA_NUMBER = "extra_number"

        private const val POLL_ATTEMPTS = 6
        private const val FIRST_DELAY_MS = 1_200L
        private const val POLL_INTERVAL_MS = 1_000L

        fun captureFinishedCall(context: Context, number: String?) {
            val intent = Intent(context, CallCaptureService::class.java).apply {
                putExtra(EXTRA_NUMBER, number)
            }

            try {
                context.startForegroundService(intent)
            } catch (e: Exception) {
                // Some OEMs refuse a foreground service start from the
                // background; the periodic catch-up scan covers this case.
                Log.w(TAG, "Could not start the capture service", e)
            }
        }
    }
}
