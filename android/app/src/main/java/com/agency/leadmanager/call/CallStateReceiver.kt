package com.agency.leadmanager.call

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.telephony.TelephonyManager
import android.util.Log

/**
 * Watches the phone state so we know when a call starts and, more importantly,
 * when it ends.
 *
 * Sequence for an outgoing call:
 *   NEW_OUTGOING_CALL  -> we learn the dialled number
 *   OFFHOOK            -> the call is live
 *   IDLE               -> the call ended; now go read its duration
 *
 * For an incoming call: RINGING -> OFFHOOK (answered) or straight to IDLE
 * (missed). We deliberately do not care which: whatever the platform recorded
 * in the call log is the truth.
 *
 * The actual work happens in [CallCaptureService], because a receiver only gets
 * a few seconds of runtime and the call log row may not be written yet.
 */
class CallStateReceiver : BroadcastReceiver() {

    /**
     * ACTION_NEW_OUTGOING_CALL and EXTRA_INCOMING_NUMBER are formally
     * deprecated, but they are still the only way for a non-default-dialer app
     * to learn the number of a call as it starts. Google's replacements
     * (CallRedirectionService / CallScreeningService) require being the default
     * dialler or call-screening app, which this app deliberately is not.
     *
     * If the number is missed, nothing breaks: the call-log read after the call
     * ends is what actually records the number and duration.
     */
    @Suppress("DEPRECATION")
    override fun onReceive(context: Context, intent: Intent) {
        when (intent.action) {
            Intent.ACTION_NEW_OUTGOING_CALL -> {
                val number = intent.getStringExtra(Intent.EXTRA_PHONE_NUMBER)
                if (!number.isNullOrBlank()) {
                    lastNumber = number
                    Log.d(TAG, "Outgoing call starting")
                }
            }

            TelephonyManager.ACTION_PHONE_STATE_CHANGED -> {
                val state = intent.getStringExtra(TelephonyManager.EXTRA_STATE)
                val incoming = intent.getStringExtra(TelephonyManager.EXTRA_INCOMING_NUMBER)

                if (!incoming.isNullOrBlank()) {
                    lastNumber = incoming
                }

                when (state) {
                    TelephonyManager.EXTRA_STATE_RINGING,
                    TelephonyManager.EXTRA_STATE_OFFHOOK -> {
                        // A call is in progress. Remember it so the IDLE that
                        // follows is recognised as "a call just ended" rather
                        // than an unrelated state broadcast.
                        wasInCall = true
                    }

                    TelephonyManager.EXTRA_STATE_IDLE -> {
                        if (!wasInCall) return
                        wasInCall = false

                        val number = lastNumber
                        lastNumber = null

                        Log.d(TAG, "Call ended, handing over to the capture service")
                        CallCaptureService.captureFinishedCall(context, number)
                    }
                }
            }
        }
    }

    companion object {
        private const val TAG = "CallStateReceiver"

        /**
         * PHONE_STATE for outgoing calls does not carry the number, so we hold
         * the one from NEW_OUTGOING_CALL (or the incoming extra) until IDLE.
         * Static because the receiver instance is recreated per broadcast.
         */
        @Volatile
        private var lastNumber: String? = null

        @Volatile
        private var wasInCall: Boolean = false
    }
}
