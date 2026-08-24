package com.agency.leadmanager.data.repo

import com.agency.leadmanager.data.ApiResult
import com.agency.leadmanager.data.apiCall
import com.agency.leadmanager.data.local.AppDatabase
import com.agency.leadmanager.data.local.CachedLeadEntity
import com.agency.leadmanager.data.local.PendingLeadEntity
import com.agency.leadmanager.data.local.PendingStatusUpdateEntity
import com.agency.leadmanager.data.remote.ApiClient
import com.agency.leadmanager.data.remote.dto.*
import com.agency.leadmanager.util.DateUtils
import com.agency.leadmanager.util.PhoneUtils

class LeadRepository(
    private val client: ApiClient,
    private val db: AppDatabase,
) {

    private val cache = db.cachedLeadDao()
    private val pendingLeads = db.pendingLeadDao()
    private val pendingStatus = db.pendingStatusUpdateDao()

    val pendingLeadCountFlow = pendingLeads.pendingCountFlow()
    val pendingStatusCountFlow = pendingStatus.pendingCountFlow()

    /**
     * Fetch a page of leads. On success the page is also written to the local
     * cache so the list survives losing signal.
     */
    suspend fun leads(
        status: String? = null,
        search: String? = null,
        followUp: String? = null,
        sort: String? = null,
        page: Int = 1,
    ): ApiResult<List<LeadDto>> {
        val result = apiCall {
            client.api.leads(
                status = status,
                search = search,
                followUp = followUp,
                sort = sort,
                page = page,
            )
        }

        if (result is ApiResult.Success) {
            cache.upsertAll(result.data.map { it.toCache() })
        }

        return result
    }

    /** Cached leads, for the offline banner state of the list screen. */
    fun cachedLeadsFlow() = cache.recentFlow()

    suspend fun cachedSearch(query: String): List<CachedLeadEntity> =
        if (query.isBlank()) emptyList() else cache.search(query.trim())

    suspend fun lead(id: Long): ApiResult<LeadDto> {
        val result = apiCall { client.api.lead(id) }
        if (result is ApiResult.Success) {
            cache.upsertAll(listOf(result.data.toCache()))
        }
        return result
    }

    /**
     * Which lead does this number belong to?
     *
     * Asks the server first (it can see leads this device has never cached),
     * and falls back to the local cache when offline - the popup must work
     * either way.
     */
    suspend fun lookupByPhone(phone: String): LeadLookupResult {
        val result = apiCall { client.api.lookupByPhone(phone) }

        if (result is ApiResult.Success) {
            val lead = result.data.lead
            if (result.data.found && lead != null) {
                cache.upsertAll(listOf(lead.toCache()))
                return LeadLookupResult.Found(
                    leadId = lead.id,
                    name = lead.name,
                    status = lead.status,
                    city = lead.city,
                    callCount = lead.callCount,
                    fromCache = false,
                )
            }
            return LeadLookupResult.NotFound
        }

        val normalized = PhoneUtils.normalize(phone) ?: return LeadLookupResult.NotFound
        val cached = cache.byPhone(normalized)

        return if (cached != null) {
            LeadLookupResult.Found(
                leadId = cached.id,
                name = cached.name,
                status = cached.status,
                city = cached.city,
                callCount = cached.callCount,
                fromCache = true,
            )
        } else {
            LeadLookupResult.Offline
        }
    }

    suspend fun createLead(request: CreateLeadRequest): ApiResult<LeadDto> {
        val result = apiCall { client.api.createLead(request) }

        if (result is ApiResult.Success) {
            cache.upsertAll(listOf(result.data.toCache()))
            return result
        }

        // Offline: keep it locally and let the sync worker post it later.
        val failure = result as ApiResult.Failure
        if (failure.isOffline) {
            pendingLeads.insert(
                PendingLeadEntity(
                    name = request.name,
                    phone = request.phone,
                    city = request.city,
                    jobCategoryId = request.jobCategoryId,
                    preferredCountry = request.preferredCountry,
                    priority = request.priority ?: "medium",
                    status = request.status,
                    notes = request.notes,
                    nextFollowUpAtMillis = DateUtils.parseMillis(request.nextFollowUpAt),
                )
            )
            return ApiResult.Failure(
                "Saved on this phone. It will be added to the server when you are back online.",
                statusCode = null,
            )
        }

        return failure
    }

    suspend fun updateLead(id: Long, request: UpdateLeadRequest): ApiResult<LeadDto> {
        val result = apiCall { client.api.updateLead(id, request) }
        if (result is ApiResult.Success) {
            cache.upsertAll(listOf(result.data.toCache()))
        }
        return result
    }

    /**
     * Change a lead's status, optionally setting the next callback.
     * Queued locally when offline so a telecaller's work is never dropped.
     */
    suspend fun updateStatus(
        leadId: Long,
        status: String,
        remarks: String? = null,
        nextFollowUpAtMillis: Long? = null,
    ): ApiResult<LeadDto> {
        val result = apiCall {
            client.api.updateLeadStatus(
                leadId,
                StatusRequest(
                    status = status,
                    remarks = remarks,
                    nextFollowUpAt = nextFollowUpAtMillis?.let { DateUtils.toApi(it) },
                )
            )
        }

        if (result is ApiResult.Success) {
            cache.upsertAll(listOf(result.data.toCache()))
            return result
        }

        val failure = result as ApiResult.Failure
        if (failure.isOffline) {
            pendingStatus.insert(
                PendingStatusUpdateEntity(
                    leadId = leadId,
                    status = status,
                    remarks = remarks,
                    nextFollowUpAtMillis = nextFollowUpAtMillis,
                )
            )
            return ApiResult.Failure(
                "Saved on this phone. It will reach the office when you are back online.",
                statusCode = null,
            )
        }

        return failure
    }

    suspend fun assign(leadId: Long, userId: Int): ApiResult<LeadDto> =
        apiCall { client.api.assignLead(leadId, AssignRequest(userId)) }

    suspend fun convert(leadId: Long, request: ConvertRequest): ApiResult<ProjectDto> =
        apiCall { client.api.convertLead(leadId, request) }

    // ------------------------------------------------------------ follow-ups
    suspend fun followUps(bucket: String): ApiResult<List<FollowUpDto>> =
        apiCall { client.api.followUps(bucket = bucket) }

    suspend fun dueFollowUps(): ApiResult<List<FollowUpDto>> =
        apiCall { client.api.dueFollowUps() }

    suspend fun scheduleFollowUp(
        leadId: Long,
        whenMillis: Long,
        remarks: String?,
    ): ApiResult<Map<String, Long>> =
        apiCall {
            client.api.createFollowUp(
                CreateFollowUpRequest(
                    leadId = leadId,
                    scheduledAt = DateUtils.toApi(whenMillis),
                    remarks = remarks,
                )
            )
        }

    suspend fun completeFollowUp(id: Long): ApiResult<Unit> =
        apiCall { client.api.updateFollowUp(id, UpdateFollowUpRequest(status = "done")) }

    // ------------------------------------------------------------ sync (called by workers)

    /** Push leads created while offline. Returns how many made it. */
    suspend fun flushPendingLeads(): Int {
        var sent = 0

        for (row in pendingLeads.oldest()) {
            val result = apiCall {
                client.api.createLead(
                    CreateLeadRequest(
                        name = row.name,
                        phone = row.phone,
                        city = row.city,
                        jobCategoryId = row.jobCategoryId,
                        preferredCountry = row.preferredCountry,
                        priority = row.priority,
                        status = row.status,
                        notes = row.notes,
                        nextFollowUpAt = row.nextFollowUpAtMillis?.let { DateUtils.toApi(it) },
                    )
                )
            }

            when {
                result is ApiResult.Success -> {
                    cache.upsertAll(listOf(result.data.toCache()))
                    pendingLeads.deleteById(row.id)
                    sent++
                }
                // 409 = the number already exists on the server. Nothing to fix,
                // so stop retrying it.
                result is ApiResult.Failure && result.statusCode == 409 ->
                    pendingLeads.deleteById(row.id)

                result is ApiResult.Failure && result.isOffline -> return sent

                else -> pendingLeads.markFailed(row.id, (result as ApiResult.Failure).message)
            }
        }

        pendingLeads.dropExhausted()
        return sent
    }

    /** Replay status changes made while offline, oldest first. */
    suspend fun flushPendingStatusUpdates(): Int {
        var sent = 0

        for (row in pendingStatus.oldest()) {
            val result = apiCall {
                client.api.updateLeadStatus(
                    row.leadId,
                    StatusRequest(
                        status = row.status,
                        remarks = row.remarks,
                        nextFollowUpAt = row.nextFollowUpAtMillis?.let { DateUtils.toApi(it) },
                    )
                )
            }

            when {
                result is ApiResult.Success -> {
                    cache.upsertAll(listOf(result.data.toCache()))
                    pendingStatus.deleteById(row.id)
                    sent++
                }
                // 404/409: the lead is gone or already converted - drop it.
                result is ApiResult.Failure &&
                    (result.statusCode == 404 || result.statusCode == 409) ->
                    pendingStatus.deleteById(row.id)

                result is ApiResult.Failure && result.isOffline -> return sent

                else -> pendingStatus.markFailed(row.id, (result as ApiResult.Failure).message)
            }
        }

        pendingStatus.dropExhausted()
        return sent
    }

    /** Warm the offline cache with the leads this user is working on. */
    suspend fun refreshCache() {
        val result = apiCall { client.api.leads(sort = "recent", page = 1, perPage = 200) }
        if (result is ApiResult.Success) {
            cache.upsertAll(result.data.map { it.toCache() })
            // Drop anything not seen for a month.
            cache.deleteOlderThan(System.currentTimeMillis() - 30L * 24 * 60 * 60 * 1000)
        }
    }
}

/** Outcome of matching a dialled number to a lead. */
sealed class LeadLookupResult {
    data class Found(
        val leadId: Long,
        val name: String,
        val status: String,
        val city: String?,
        val callCount: Int,
        val fromCache: Boolean,
    ) : LeadLookupResult()

    /** The number is definitely not a lead of this user. */
    data object NotFound : LeadLookupResult()

    /** We could not ask the server and have nothing cached. */
    data object Offline : LeadLookupResult()
}

private fun LeadDto.toCache(): CachedLeadEntity = CachedLeadEntity(
    id = id,
    name = name,
    phone = phone,
    phoneNormalized = PhoneUtils.normalize(phone) ?: phone,
    altPhoneNormalized = PhoneUtils.normalize(altPhone),
    city = city,
    status = status,
    priority = priority,
    jobCategoryName = jobCategoryName,
    preferredCountry = preferredCountry,
    assignedToName = assignedToName,
    nextFollowUpAt = nextFollowUpAt,
    lastContactedAt = lastContactedAt,
    callCount = callCount,
    totalTalkTimeSec = totalTalkTimeSec,
    notes = notes,
    projectId = projectId,
    updatedAt = updatedAt,
)
