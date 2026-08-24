package com.agency.leadmanager.data.local

import androidx.room.Dao
import androidx.room.Delete
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import androidx.room.Upsert
import kotlinx.coroutines.flow.Flow

@Dao
interface PendingCallDao {

    /** IGNORE: if this device call was already queued, keep the first row. */
    @Insert(onConflict = OnConflictStrategy.IGNORE)
    suspend fun insert(call: PendingCallEntity): Long

    @Upsert
    suspend fun upsert(call: PendingCallEntity)

    @Query("SELECT * FROM pending_calls ORDER BY startedAtMillis ASC LIMIT :limit")
    suspend fun oldest(limit: Int = 100): List<PendingCallEntity>

    @Query("SELECT * FROM pending_calls WHERE deviceCallId = :deviceCallId LIMIT 1")
    suspend fun byDeviceCallId(deviceCallId: String): PendingCallEntity?

    @Query("SELECT * FROM pending_calls WHERE id = :id")
    suspend fun byId(id: Long): PendingCallEntity?

    @Query("SELECT COUNT(*) FROM pending_calls")
    fun pendingCountFlow(): Flow<Int>

    @Query("SELECT COUNT(*) FROM pending_calls")
    suspend fun pendingCount(): Int

    @Query(
        """
        UPDATE pending_calls
           SET disposition = :disposition,
               notes = :notes,
               statusSet = :statusSet,
               nextFollowUpAtMillis = :nextFollowUpAtMillis,
               leadId = :leadId
         WHERE id = :id
        """
    )
    suspend fun attachOutcome(
        id: Long,
        disposition: String?,
        notes: String?,
        statusSet: String?,
        nextFollowUpAtMillis: Long?,
        leadId: Long?,
    )

    @Query("UPDATE pending_calls SET attempts = attempts + 1, lastError = :error WHERE id = :id")
    suspend fun markFailed(id: Long, error: String?)

    @Delete
    suspend fun delete(call: PendingCallEntity)

    @Query("DELETE FROM pending_calls WHERE id IN (:ids)")
    suspend fun deleteByIds(ids: List<Long>)

    /** Give up on calls the server keeps rejecting, so the queue cannot wedge. */
    @Query("DELETE FROM pending_calls WHERE attempts >= :maxAttempts")
    suspend fun dropExhausted(maxAttempts: Int = 12)
}

@Dao
interface CachedLeadDao {

    @Upsert
    suspend fun upsertAll(leads: List<CachedLeadEntity>)

    @Query("SELECT * FROM cached_leads WHERE id = :id")
    suspend fun byId(id: Long): CachedLeadEntity?

    /** Used by the post-call popup when there is no signal. */
    @Query(
        """
        SELECT * FROM cached_leads
         WHERE phoneNormalized = :normalized OR altPhoneNormalized = :normalized
         LIMIT 1
        """
    )
    suspend fun byPhone(normalized: String): CachedLeadEntity?

    @Query("SELECT * FROM cached_leads ORDER BY cachedAtMillis DESC LIMIT :limit")
    fun recentFlow(limit: Int = 200): Flow<List<CachedLeadEntity>>

    @Query(
        """
        SELECT * FROM cached_leads
         WHERE name LIKE '%' || :query || '%' OR phone LIKE '%' || :query || '%'
         ORDER BY name LIMIT 100
        """
    )
    suspend fun search(query: String): List<CachedLeadEntity>

    @Query("SELECT COUNT(*) FROM cached_leads")
    suspend fun count(): Int

    @Query("DELETE FROM cached_leads")
    suspend fun clear()

    /** Keep the cache from growing without bound. */
    @Query("DELETE FROM cached_leads WHERE cachedAtMillis < :before")
    suspend fun deleteOlderThan(before: Long)
}

@Dao
interface PendingLeadDao {

    @Insert
    suspend fun insert(lead: PendingLeadEntity): Long

    @Query("SELECT * FROM pending_leads ORDER BY createdAtMillis ASC LIMIT :limit")
    suspend fun oldest(limit: Int = 50): List<PendingLeadEntity>

    @Query("SELECT COUNT(*) FROM pending_leads")
    fun pendingCountFlow(): Flow<Int>

    @Query("UPDATE pending_leads SET attempts = attempts + 1, lastError = :error WHERE id = :id")
    suspend fun markFailed(id: Long, error: String?)

    @Query("DELETE FROM pending_leads WHERE id = :id")
    suspend fun deleteById(id: Long)

    @Query("DELETE FROM pending_leads WHERE attempts >= :maxAttempts")
    suspend fun dropExhausted(maxAttempts: Int = 12)
}

@Dao
interface PendingStatusUpdateDao {

    @Insert
    suspend fun insert(update: PendingStatusUpdateEntity): Long

    @Query("SELECT * FROM pending_status_updates ORDER BY createdAtMillis ASC LIMIT :limit")
    suspend fun oldest(limit: Int = 100): List<PendingStatusUpdateEntity>

    @Query("SELECT COUNT(*) FROM pending_status_updates")
    fun pendingCountFlow(): Flow<Int>

    @Query("UPDATE pending_status_updates SET attempts = attempts + 1, lastError = :error WHERE id = :id")
    suspend fun markFailed(id: Long, error: String?)

    @Query("DELETE FROM pending_status_updates WHERE id = :id")
    suspend fun deleteById(id: Long)

    @Query("DELETE FROM pending_status_updates WHERE attempts >= :maxAttempts")
    suspend fun dropExhausted(maxAttempts: Int = 12)
}
