package com.agency.leadmanager.data.local

import androidx.room.Entity
import androidx.room.Index
import androidx.room.PrimaryKey

/**
 * A call captured on the device but not yet accepted by the server.
 *
 * This is the safety net for the whole feature: calls happen in basements, in
 * lifts, on the road. The row is written the moment the call ends and only
 * deleted once the server confirms it, so nothing is ever lost.
 */
@Entity(
    tableName = "pending_calls",
    indices = [Index(value = ["deviceCallId"], unique = true)]
)
data class PendingCallEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,

    /** Android's CallLog._ID - makes the server-side upsert idempotent. */
    val deviceCallId: String?,

    val phoneNumber: String,
    val phoneNormalized: String,
    val direction: String,
    val startedAtMillis: Long,
    val durationSec: Int,
    val simSlot: Int? = null,

    /** Filled in by the post-call popup; null when the user dismissed it. */
    val disposition: String? = null,
    val notes: String? = null,
    val statusSet: String? = null,
    val nextFollowUpAtMillis: Long? = null,
    val leadId: Long? = null,

    val attempts: Int = 0,
    val lastError: String? = null,
    val createdAtMillis: Long = System.currentTimeMillis(),
)

/**
 * Cached lead, so the list and the post-call popup still work with no signal.
 * Refreshed from the server; never the source of truth.
 */
@Entity(
    tableName = "cached_leads",
    indices = [Index(value = ["phoneNormalized"])]
)
data class CachedLeadEntity(
    @PrimaryKey val id: Long,
    val name: String,
    val phone: String,
    val phoneNormalized: String,
    val altPhoneNormalized: String? = null,
    val city: String? = null,
    val status: String,
    val priority: String,
    val jobCategoryName: String? = null,
    val preferredCountry: String? = null,
    val assignedToName: String? = null,
    val nextFollowUpAt: String? = null,
    val lastContactedAt: String? = null,
    val callCount: Int = 0,
    val totalTalkTimeSec: Int = 0,
    val notes: String? = null,
    val projectId: Long? = null,
    val updatedAt: String? = null,
    val cachedAtMillis: Long = System.currentTimeMillis(),
)

/**
 * A lead created while offline. Sent to the server on the next sync.
 */
@Entity(tableName = "pending_leads")
data class PendingLeadEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val name: String,
    val phone: String,
    val city: String? = null,
    val jobCategoryId: Int? = null,
    val preferredCountry: String? = null,
    val priority: String = "medium",
    val status: String? = null,
    val notes: String? = null,
    val nextFollowUpAtMillis: Long? = null,
    val attempts: Int = 0,
    val lastError: String? = null,
    val createdAtMillis: Long = System.currentTimeMillis(),
)

/**
 * A status change made offline, replayed in order once back online.
 */
@Entity(tableName = "pending_status_updates")
data class PendingStatusUpdateEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val leadId: Long,
    val status: String,
    val remarks: String? = null,
    val nextFollowUpAtMillis: Long? = null,
    val attempts: Int = 0,
    val lastError: String? = null,
    val createdAtMillis: Long = System.currentTimeMillis(),
)
