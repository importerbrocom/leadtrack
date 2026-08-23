package com.agency.leadmanager.data.repo

import android.util.Log
import com.agency.leadmanager.data.ApiResult
import com.agency.leadmanager.data.apiCall
import com.agency.leadmanager.data.local.AppDatabase
import com.agency.leadmanager.data.local.PendingCallEntity
import com.agency.leadmanager.data.remote.ApiClient
import com.agency.leadmanager.data.remote.dto.CallSyncItem
import com.agency.leadmanager.data.remote.dto.CallSyncRequest
import com.agency.leadmanager.data.remote.dto.CallSyncResponse
import com.agency.leadmanager.util.DateUtils
import com.agency.leadmanager.util.PhoneUtils

/**
 * Owns the captured-call outbox.
 *
 * Every call goes into Room first and is only removed once the server has
 * accepted it. Because each row carries the device's own CallLog id, sending
 * the same call twice is harmless.
 */
class CallRepository(
    private val client: ApiClient,
    private val db: AppDatabase,
) {

    private val dao = db.pendingCallDao()

    val pendingCountFlow = dao.pendingCountFlow()

    suspend fun pendingCount(): Int = dao.pendingCount()

    /**
     * Record a call the moment it ends. Returns the local row id so the popup
     * can attach the outcome to it a few seconds later.
     */
    suspend fun recordCall(
        deviceCallId: String?,
        phoneNumber: String,
        direction: String,
        startedAtMillis: Long,
        durationSec: Int,
        simSlot: Int? = null,
        leadId: Long? = null,
    ): Long {
        val normalized = PhoneUtils.normalize(phoneNumber) ?: phoneNumber

        // Already queued? Keep the existing row (and its outcome, if any).
        if (deviceCallId != null) {
            dao.byDeviceCallId(deviceCallId)?.let { return it.id }
        }

        val entity = PendingCallEntity(
            deviceCallId = deviceCallId,
            phoneNumber = phoneNumber,
            phoneNormalized = normalized,
            direction = direction,
            startedAtMillis = startedAtMillis,
            durationSec = durationSec,
            simSlot = simSlot,
            leadId = leadId,
        )

        val rowId = dao.insert(entity)

        // insert() returns -1 when the unique deviceCallId collided.
        return if (rowId > 0) {
            rowId
        } else {
            deviceCallId?.let { dao.byDeviceCallId(it)?.id } ?: -1L
        }
    }

    /** Attach what the telecaller chose in the post-call popup. */
    suspend fun attachOutcome(
        pendingCallId: Long,
        leadId: Long?,
        disposition: String?,
        notes: String?,
        statusSet: String?,
        nextFollowUpAtMillis: Long?,
    ) {
        dao.attachOutcome(
            id = pendingCallId,
            disposition = disposition,
            notes = notes,
            statusSet = statusSet,
            nextFollowUpAtMillis = nextFollowUpAtMillis,
            leadId = leadId,
        )
    }

    suspend fun byId(id: Long): PendingCallEntity? = dao.byId(id)

    /**
     * Push the queue to the server in one batch.
     *
     * @return number of calls accepted, or -1 when the network was unavailable.
     */
    suspend fun syncPending(batchSize: Int = 100): Int {
        val batch = dao.oldest(batchSize)
        if (batch.isEmpty()) return 0

        val items = batch.map { row ->
            CallSyncItem(
                deviceCallId = row.deviceCallId,
                phoneNumber = row.phoneNumber,
                direction = row.direction,
                startedAt = DateUtils.toApi(row.startedAtMillis),
                durationSec = row.durationSec,
                simSlot = row.simSlot,
                disposition = row.disposition,
                notes = row.notes,
                statusSet = row.statusSet,
                nextFollowUpAt = row.nextFollowUpAtMillis?.let { DateUtils.toApi(it) },
                leadId = row.leadId,
            )
        }

        val result = apiCall { client.api.syncCalls(CallSyncRequest(items)) }

        return when (result) {
            is ApiResult.Success -> handleSyncResponse(batch, result.data)

            is ApiResult.Failure -> {
                if (result.isOffline) {
                    -1
                } else {
                    // A real server rejection: count the attempt so a single bad
                    // row cannot block the queue for ever.
                    batch.forEach { dao.markFailed(it.id, result.message) }
                    dao.dropExhausted()
                    Log.w(TAG, "Call sync rejected: ${result.message}")
                    0
                }
            }
        }
    }

    /**
     * The server answers per call, in the order we sent them. Anything it
     * accepted is deleted locally; anything it refused is counted and retried.
     */
    private suspend fun handleSyncResponse(
        batch: List<PendingCallEntity>,
        response: CallSyncResponse,
    ): Int {
        val results = response.results

        if (results.isNullOrEmpty()) {
            // No detail: trust the summary count.
            if (response.synced >= batch.size) {
                dao.deleteByIds(batch.map { it.id })
                return batch.size
            }
            return response.synced
        }

        val accepted = mutableListOf<Long>()

        results.forEach { item ->
            val row = batch.getOrNull(item.index) ?: return@forEach

            if (item.status == "error") {
                dao.markFailed(row.id, item.message)
            } else {
                accepted += row.id
            }
        }

        if (accepted.isNotEmpty()) {
            dao.deleteByIds(accepted)
        }

        dao.dropExhausted()

        return accepted.size
    }

    suspend fun callStats(from: String? = null, to: String? = null) =
        apiCall { client.api.callStats(from, to) }

    companion object {
        private const val TAG = "CallRepository"
    }
}
