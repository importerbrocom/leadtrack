package com.agency.leadmanager.data.remote

import com.agency.leadmanager.data.remote.dto.*
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.ResponseBody
import retrofit2.Response
import retrofit2.http.*

interface LeadApi {

    // ------------------------------------------------------------ auth
    @POST("auth/login")
    suspend fun login(@Body body: LoginRequest): Response<ApiEnvelope<LoginResponse>>

    @GET("auth/me")
    suspend fun me(): Response<ApiEnvelope<MeResponse>>

    @POST("auth/logout")
    suspend fun logout(): Response<ApiEnvelope<Unit>>

    @POST("auth/change-password")
    suspend fun changePassword(@Body body: ChangePasswordRequest): Response<ApiEnvelope<Unit>>

    // ------------------------------------------------------------ lookups & dashboard
    @GET("lookups")
    suspend fun lookups(): Response<ApiEnvelope<LookupsDto>>

    @GET("dashboard")
    suspend fun dashboard(): Response<ApiEnvelope<DashboardDto>>

    @GET("notifications")
    suspend fun notifications(
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 30,
    ): Response<ApiEnvelope<List<NotificationDto>>>

    @POST("notifications/read")
    suspend fun markNotificationsRead(): Response<ApiEnvelope<Unit>>

    // ------------------------------------------------------------ leads
    @GET("leads")
    suspend fun leads(
        @Query("status") status: String? = null,
        @Query("search") search: String? = null,
        @Query("assigned_to") assignedTo: Int? = null,
        @Query("follow_up") followUp: String? = null,
        @Query("sort") sort: String? = null,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 30,
    ): Response<ApiEnvelope<List<LeadDto>>>

    @GET("leads/{id}")
    suspend fun lead(@Path("id") id: Long): Response<ApiEnvelope<LeadDto>>

    /** Called the instant a call ends, to decide which popup to show. */
    @GET("leads/lookup")
    suspend fun lookupByPhone(@Query("phone") phone: String): Response<ApiEnvelope<LeadLookupResponse>>

    @POST("leads")
    suspend fun createLead(@Body body: CreateLeadRequest): Response<ApiEnvelope<LeadDto>>

    @PATCH("leads/{id}")
    suspend fun updateLead(
        @Path("id") id: Long,
        @Body body: UpdateLeadRequest,
    ): Response<ApiEnvelope<LeadDto>>

    @POST("leads/{id}/status")
    suspend fun updateLeadStatus(
        @Path("id") id: Long,
        @Body body: StatusRequest,
    ): Response<ApiEnvelope<LeadDto>>

    @POST("leads/{id}/assign")
    suspend fun assignLead(
        @Path("id") id: Long,
        @Body body: AssignRequest,
    ): Response<ApiEnvelope<LeadDto>>

    @POST("leads/{id}/convert")
    suspend fun convertLead(
        @Path("id") id: Long,
        @Body body: ConvertRequest,
    ): Response<ApiEnvelope<ProjectDto>>

    // ------------------------------------------------------------ calls
    @POST("calls/sync")
    suspend fun syncCalls(@Body body: CallSyncRequest): Response<ApiEnvelope<CallSyncResponse>>

    @GET("calls")
    suspend fun calls(
        @Query("lead_id") leadId: Long? = null,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 50,
    ): Response<ApiEnvelope<List<CallDto>>>

    @GET("calls/stats")
    suspend fun callStats(
        @Query("from") from: String? = null,
        @Query("to") to: String? = null,
    ): Response<ApiEnvelope<CallStatsDto>>

    // ------------------------------------------------------------ follow-ups
    @GET("follow-ups")
    suspend fun followUps(
        @Query("bucket") bucket: String = "today",
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 50,
    ): Response<ApiEnvelope<List<FollowUpDto>>>

    @GET("follow-ups/due")
    suspend fun dueFollowUps(): Response<ApiEnvelope<List<FollowUpDto>>>

    @POST("follow-ups")
    suspend fun createFollowUp(@Body body: CreateFollowUpRequest): Response<ApiEnvelope<Map<String, Long>>>

    @PATCH("follow-ups/{id}")
    suspend fun updateFollowUp(
        @Path("id") id: Long,
        @Body body: UpdateFollowUpRequest,
    ): Response<ApiEnvelope<Unit>>

    // ------------------------------------------------------------ projects
    @GET("projects")
    suspend fun projects(
        @Query("status") status: String? = null,
        @Query("search") search: String? = null,
        @Query("page") page: Int = 1,
        @Query("per_page") perPage: Int = 30,
    ): Response<ApiEnvelope<List<ProjectDto>>>

    @GET("projects/{id}")
    suspend fun project(@Path("id") id: Long): Response<ApiEnvelope<ProjectDto>>

    @POST("projects/{id}/status")
    suspend fun updateProjectStatus(
        @Path("id") id: Long,
        @Body body: StatusRequest,
    ): Response<ApiEnvelope<ProjectDto>>

    // ------------------------------------------------------------ forms & documents
    @GET("form-templates")
    suspend fun formTemplates(): Response<ApiEnvelope<List<FormTemplateDto>>>

    @GET("form-templates/{id}/download")
    @Streaming
    suspend fun downloadFormTemplate(@Path("id") id: Int): Response<ResponseBody>

    @GET("document-types")
    suspend fun documentTypes(): Response<ApiEnvelope<List<DocumentTypeDto>>>

    @GET("documents")
    suspend fun documents(
        @Query("project_id") projectId: Long? = null,
        @Query("lead_id") leadId: Long? = null,
    ): Response<ApiEnvelope<List<DocumentDto>>>

    @Multipart
    @POST("documents")
    suspend fun uploadDocument(
        @Part file: MultipartBody.Part,
        @Part("project_id") projectId: RequestBody? = null,
        @Part("lead_id") leadId: RequestBody? = null,
        @Part("document_type_id") documentTypeId: RequestBody? = null,
        @Part("title") title: RequestBody? = null,
        @Part("document_number") documentNumber: RequestBody? = null,
        @Part("expiry_date") expiryDate: RequestBody? = null,
    ): Response<ApiEnvelope<DocumentDto>>

    @GET("documents/{id}/download")
    @Streaming
    suspend fun downloadDocument(@Path("id") id: Long): Response<ResponseBody>

    @DELETE("documents/{id}")
    suspend fun deleteDocument(@Path("id") id: Long): Response<ApiEnvelope<Unit>>

    // ------------------------------------------------------------ team (partner creates telecallers)
    @GET("users")
    suspend fun users(
        @Query("role") role: String? = null,
        @Query("search") search: String? = null,
        @Query("is_active") isActive: Boolean? = null,
    ): Response<ApiEnvelope<List<UserDto>>>

    @GET("users/assignable")
    suspend fun assignableUsers(): Response<ApiEnvelope<List<AssignableUserDto>>>

    @POST("users")
    suspend fun createUser(@Body body: CreateUserRequest): Response<ApiEnvelope<UserDto>>

    @PATCH("users/{id}")
    suspend fun updateUser(
        @Path("id") id: Int,
        @Body body: UpdateUserRequest,
    ): Response<ApiEnvelope<UserDto>>
}
