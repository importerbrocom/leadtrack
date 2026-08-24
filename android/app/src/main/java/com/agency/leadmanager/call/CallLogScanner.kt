package com.agency.leadmanager.call

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.database.Cursor
import android.provider.CallLog
import android.util.Log
import androidx.core.content.ContextCompat

/**
 * Reads the platform call log.
 *
 * Why not just time the call ourselves? Because the OS is the only reliable
 * source of the *billed* duration: our own stopwatch would include ringing
 * time, and would be wrong whenever the process is killed mid-call. So we wait
 * for the call to end, then read back exactly what the platform recorded.
 */
class CallLogScanner(private val context: Context) {

    data class DeviceCall(
        val deviceCallId: String,
        val number: String,
        val type: Int,
        val startedAtMillis: Long,
        val durationSec: Int,
        val simSlot: Int?,
    ) {
        /** Map the platform's call type onto the API's direction values. */
        val direction: String
            get() = when (type) {
                CallLog.Calls.OUTGOING_TYPE -> "outgoing"
                CallLog.Calls.INCOMING_TYPE -> "incoming"
                CallLog.Calls.MISSED_TYPE -> "missed"
                CallLog.Calls.REJECTED_TYPE -> "rejected"
                CallLog.Calls.BLOCKED_TYPE -> "blocked"
                else -> "unknown"
            }
    }

    fun hasPermission(): Boolean =
        ContextCompat.checkSelfPermission(context, Manifest.permission.READ_CALL_LOG) ==
            PackageManager.PERMISSION_GRANTED

    /**
     * The most recent call, optionally restricted to a number.
     *
     * Called a moment after the call ends. The platform sometimes takes a beat
     * to write the row, which is why the caller retries.
     */
    fun latestCall(matchingNumber: String? = null): DeviceCall? {
        if (!hasPermission()) {
            Log.w(TAG, "READ_CALL_LOG not granted; cannot read call duration")
            return null
        }

        val projection = arrayOf(
            CallLog.Calls._ID,
            CallLog.Calls.NUMBER,
            CallLog.Calls.TYPE,
            CallLog.Calls.DATE,
            CallLog.Calls.DURATION,
        )

        return try {
            context.contentResolver.query(
                CallLog.Calls.CONTENT_URI,
                projection,
                null,
                null,
                "${CallLog.Calls.DATE} DESC LIMIT 5"
            )?.use { cursor ->
                while (cursor.moveToNext()) {
                    val call = cursor.readCall() ?: continue

                    if (matchingNumber == null) {
                        return@use call
                    }

                    val wanted = matchingNumber.filter { it.isDigit() }.takeLast(10)
                    val got = call.number.filter { it.isDigit() }.takeLast(10)

                    if (wanted.isNotEmpty() && wanted == got) {
                        return@use call
                    }
                }
                null
            }
        } catch (e: SecurityException) {
            Log.w(TAG, "Call log read denied", e)
            null
        } catch (e: Exception) {
            Log.e(TAG, "Failed to read the call log", e)
            null
        }
    }

    /**
     * Every call newer than [sinceMillis], newest first.
     *
     * This is the catch-up path: if the app was killed, or permissions were
     * granted late, or the phone was rebooted mid-shift, the periodic worker
     * uses this to backfill anything the live receiver missed.
     */
    fun callsSince(sinceMillis: Long, limit: Int = 200): List<DeviceCall> {
        if (!hasPermission()) return emptyList()

        val projection = arrayOf(
            CallLog.Calls._ID,
            CallLog.Calls.NUMBER,
            CallLog.Calls.TYPE,
            CallLog.Calls.DATE,
            CallLog.Calls.DURATION,
        )

        val calls = mutableListOf<DeviceCall>()

        try {
            context.contentResolver.query(
                CallLog.Calls.CONTENT_URI,
                projection,
                "${CallLog.Calls.DATE} > ?",
                arrayOf(sinceMillis.toString()),
                "${CallLog.Calls.DATE} DESC LIMIT $limit"
            )?.use { cursor ->
                while (cursor.moveToNext()) {
                    cursor.readCall()?.let { calls += it }
                }
            }
        } catch (e: SecurityException) {
            Log.w(TAG, "Call log read denied", e)
        } catch (e: Exception) {
            Log.e(TAG, "Failed to scan the call log", e)
        }

        return calls
    }

    private fun Cursor.readCall(): DeviceCall? {
        val idIndex = getColumnIndex(CallLog.Calls._ID)
        val numberIndex = getColumnIndex(CallLog.Calls.NUMBER)
        val typeIndex = getColumnIndex(CallLog.Calls.TYPE)
        val dateIndex = getColumnIndex(CallLog.Calls.DATE)
        val durationIndex = getColumnIndex(CallLog.Calls.DURATION)

        if (idIndex < 0 || numberIndex < 0 || dateIndex < 0) return null

        val number = getString(numberIndex).orEmpty()
        if (number.isBlank()) return null

        return DeviceCall(
            deviceCallId = getString(idIndex).orEmpty().ifBlank { return null },
            number = number,
            type = if (typeIndex >= 0) getInt(typeIndex) else 0,
            startedAtMillis = getLong(dateIndex),
            durationSec = if (durationIndex >= 0) getInt(durationIndex) else 0,
            simSlot = null,
        )
    }

    companion object {
        private const val TAG = "CallLogScanner"
    }
}
