package com.agency.leadmanager.data.remote.dto

import com.google.gson.annotations.SerializedName

/**
 * Every API reply is wrapped:
 *   { "success": bool, "message": string?, "data": ?, "meta": {...}?, "errors": {...}? }
 */
data class ApiEnvelope<T>(
    val success: Boolean = false,
    val message: String? = null,
    val data: T? = null,
    val meta: PageMeta? = null,
    val errors: Map<String, String>? = null,
)

data class PageMeta(
    val total: Int = 0,
    val page: Int = 1,
    @SerializedName("per_page") val perPage: Int = 25,
    @SerializedName("total_pages") val totalPages: Int = 0,
    @SerializedName("has_more") val hasMore: Boolean = false,
)

// ------------------------------------------------------------------ auth

data class LoginRequest(
    val login: String,
    val password: String,
    @SerializedName("device_id") val deviceId: String? = null,
    @SerializedName("device_name") val deviceName: String? = null,
    @SerializedName("app_version") val appVersion: String? = null,
)

data class LoginResponse(
    val token: String = "",
    @SerializedName("expires_at") val expiresAt: String? = null,
    val user: UserDto? = null,
)

data class MeResponse(
    val user: UserDto? = null,
    val counts: Map<String, Int>? = null,
)

data class UserDto(
    val id: Int = 0,
    @SerializedName("parent_id") val parentId: Int? = null,
    val role: String = "telecaller",
    val name: String = "",
    val phone: String = "",
    val email: String? = null,
    @SerializedName("agency_name") val agencyName: String? = null,
    val city: String? = null,
    val state: String? = null,
    @SerializedName("is_active") val isActive: Boolean = true,
    @SerializedName("telecaller_count") val telecallerCount: Int? = null,
    @SerializedName("assigned_leads") val assignedLeads: Int? = null,
    @SerializedName("last_login_at") val lastLoginAt: String? = null,
) {
    val isAdmin: Boolean get() = role == "admin"
    val isPartner: Boolean get() = role == "partner"
    val isTelecaller: Boolean get() = role == "telecaller"
}

data class ChangePasswordRequest(
    @SerializedName("current_password") val currentPassword: String,
    @SerializedName("new_password") val newPassword: String,
)

data class CreateUserRequest(
    val role: String,
    val name: String,
    val phone: String,
    val password: String,
    val email: String? = null,
    val city: String? = null,
    @SerializedName("parent_id") val parentId: Int? = null,
)

data class UpdateUserRequest(
    val name: String? = null,
    val phone: String? = null,
    val email: String? = null,
    val city: String? = null,
    val password: String? = null,
    @SerializedName("is_active") val isActive: Boolean? = null,
)

data class AssignableUserDto(
    val id: Int = 0,
    val name: String = "",
    val role: String = "",
    val phone: String? = null,
)

// ------------------------------------------------------------------ leads

data class LeadDto(
    val id: Long = 0,
    val name: String = "",
    val phone: String = "",
    @SerializedName("alt_phone") val altPhone: String? = null,
    val whatsapp: String? = null,
    val email: String? = null,
    val city: String? = null,
    val district: String? = null,
    val state: String? = null,
    @SerializedName("source_id") val sourceId: Int? = null,
    @SerializedName("source_name") val sourceName: String? = null,
    @SerializedName("job_category_id") val jobCategoryId: Int? = null,
    @SerializedName("job_category_name") val jobCategoryName: String? = null,
    @SerializedName("preferred_country") val preferredCountry: String? = null,
    val qualification: String? = null,
    @SerializedName("experience_years") val experienceYears: Double? = null,
    @SerializedName("expected_salary") val expectedSalary: Double? = null,
    @SerializedName("passport_status") val passportStatus: String? = null,
    val status: String = "new",
    val priority: String = "medium",
    @SerializedName("partner_id") val partnerId: Int? = null,
    @SerializedName("partner_name") val partnerName: String? = null,
    @SerializedName("assigned_to") val assignedTo: Int? = null,
    @SerializedName("assigned_to_name") val assignedToName: String? = null,
    @SerializedName("next_follow_up_at") val nextFollowUpAt: String? = null,
    @SerializedName("last_contacted_at") val lastContactedAt: String? = null,
    @SerializedName("call_count") val callCount: Int = 0,
    @SerializedName("total_talk_time_sec") val totalTalkTimeSec: Int = 0,
    @SerializedName("talk_time_display") val talkTimeDisplay: String? = null,
    val notes: String? = null,
    @SerializedName("project_id") val projectId: Long? = null,
    @SerializedName("converted_at") val convertedAt: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
    @SerializedName("updated_at") val updatedAt: String? = null,
    // present on GET /leads/{id}
    val calls: List<CallDto>? = null,
    @SerializedName("status_history") val statusHistory: List<HistoryDto>? = null,
    @SerializedName("follow_ups") val followUps: List<LeadFollowUpDto>? = null,
    val documents: List<DocumentDto>? = null,
) {
    val isConverted: Boolean get() = status == "converted"
}

data class HistoryDto(
    @SerializedName("from_status") val fromStatus: String? = null,
    @SerializedName("to_status") val toStatus: String = "",
    val remarks: String? = null,
    @SerializedName("user_name") val userName: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class LeadFollowUpDto(
    val id: Long = 0,
    @SerializedName("scheduled_at") val scheduledAt: String? = null,
    val remarks: String? = null,
    val status: String = "pending",
    @SerializedName("user_name") val userName: String? = null,
)

data class LeadLookupResponse(
    val found: Boolean = false,
    val lead: LeadDto? = null,
)

data class CreateLeadRequest(
    val name: String,
    val phone: String,
    @SerializedName("alt_phone") val altPhone: String? = null,
    val email: String? = null,
    val city: String? = null,
    val district: String? = null,
    @SerializedName("source_id") val sourceId: Int? = null,
    @SerializedName("job_category_id") val jobCategoryId: Int? = null,
    @SerializedName("preferred_country") val preferredCountry: String? = null,
    val qualification: String? = null,
    @SerializedName("experience_years") val experienceYears: Double? = null,
    @SerializedName("expected_salary") val expectedSalary: Double? = null,
    @SerializedName("passport_status") val passportStatus: String? = null,
    val priority: String? = "medium",
    val status: String? = null,
    @SerializedName("next_follow_up_at") val nextFollowUpAt: String? = null,
    val notes: String? = null,
    @SerializedName("assigned_to") val assignedTo: Int? = null,
)

data class UpdateLeadRequest(
    val name: String? = null,
    @SerializedName("alt_phone") val altPhone: String? = null,
    val whatsapp: String? = null,
    val email: String? = null,
    val city: String? = null,
    @SerializedName("job_category_id") val jobCategoryId: Int? = null,
    @SerializedName("preferred_country") val preferredCountry: String? = null,
    val qualification: String? = null,
    @SerializedName("passport_status") val passportStatus: String? = null,
    val priority: String? = null,
    val notes: String? = null,
)

data class StatusRequest(
    val status: String,
    val remarks: String? = null,
    @SerializedName("next_follow_up_at") val nextFollowUpAt: String? = null,
    @SerializedName("clear_follow_up") val clearFollowUp: Boolean? = null,
)

data class AssignRequest(
    @SerializedName("assigned_to") val assignedTo: Int,
)

// ------------------------------------------------------------------ calls

data class CallDto(
    val id: Long = 0,
    @SerializedName("lead_id") val leadId: Long? = null,
    @SerializedName("lead_name") val leadName: String? = null,
    @SerializedName("phone_number") val phoneNumber: String = "",
    val direction: String = "outgoing",
    @SerializedName("started_at") val startedAt: String? = null,
    @SerializedName("duration_sec") val durationSec: Int = 0,
    val duration: String? = null,
    val answered: Boolean = false,
    val disposition: String? = null,
    @SerializedName("status_set") val statusSet: String? = null,
    val notes: String? = null,
    @SerializedName("user_name") val userName: String? = null,
)

/** One captured call, as posted to /calls/sync. */
data class CallSyncItem(
    @SerializedName("device_call_id") val deviceCallId: String?,
    @SerializedName("phone_number") val phoneNumber: String,
    val direction: String,
    @SerializedName("started_at") val startedAt: String,
    @SerializedName("duration_sec") val durationSec: Int,
    @SerializedName("sim_slot") val simSlot: Int? = null,
    val disposition: String? = null,
    val notes: String? = null,
    @SerializedName("status_set") val statusSet: String? = null,
    @SerializedName("next_follow_up_at") val nextFollowUpAt: String? = null,
    @SerializedName("lead_id") val leadId: Long? = null,
)

data class CallSyncRequest(val calls: List<CallSyncItem>)

data class CallSyncResponse(
    val synced: Int = 0,
    val failed: Int = 0,
    val results: List<CallSyncResult>? = null,
)

data class CallSyncResult(
    val index: Int = 0,
    val status: String = "",
    val message: String? = null,
    @SerializedName("call_id") val callId: Long? = null,
    @SerializedName("lead_id") val leadId: Long? = null,
    @SerializedName("lead_name") val leadName: String? = null,
    val matched: Boolean = false,
)

data class CallStatsDto(
    @SerializedName("total_calls") val totalCalls: Int = 0,
    @SerializedName("connected_calls") val connectedCalls: Int = 0,
    @SerializedName("total_seconds") val totalSeconds: Int = 0,
    @SerializedName("total_talk_time") val totalTalkTime: String? = null,
    @SerializedName("leads_touched") val leadsTouched: Int = 0,
)

// ------------------------------------------------------------------ follow-ups

data class FollowUpDto(
    val id: Long = 0,
    @SerializedName("lead_id") val leadId: Long = 0,
    @SerializedName("lead_name") val leadName: String = "",
    @SerializedName("lead_phone") val leadPhone: String = "",
    @SerializedName("lead_status") val leadStatus: String? = null,
    @SerializedName("lead_priority") val leadPriority: String? = null,
    @SerializedName("lead_city") val leadCity: String? = null,
    @SerializedName("scheduled_at") val scheduledAt: String? = null,
    val remarks: String? = null,
    val status: String = "pending",
    @SerializedName("is_overdue") val isOverdue: Boolean = false,
    @SerializedName("user_name") val userName: String? = null,
)

data class CreateFollowUpRequest(
    @SerializedName("lead_id") val leadId: Long,
    @SerializedName("scheduled_at") val scheduledAt: String,
    val remarks: String? = null,
)

data class UpdateFollowUpRequest(
    val status: String? = null,
    @SerializedName("scheduled_at") val scheduledAt: String? = null,
    val remarks: String? = null,
)

// ------------------------------------------------------------------ projects

data class ProjectDto(
    val id: Long = 0,
    @SerializedName("lead_id") val leadId: Long = 0,
    @SerializedName("project_code") val projectCode: String = "",
    @SerializedName("candidate_name") val candidateName: String = "",
    @SerializedName("candidate_phone") val candidatePhone: String = "",
    @SerializedName("candidate_email") val candidateEmail: String? = null,
    val dob: String? = null,
    val gender: String? = null,
    @SerializedName("passport_no") val passportNo: String? = null,
    @SerializedName("passport_expiry") val passportExpiry: String? = null,
    @SerializedName("job_category_name") val jobCategoryName: String? = null,
    val position: String? = null,
    @SerializedName("employer_name") val employerName: String? = null,
    @SerializedName("destination_country") val destinationCountry: String? = null,
    @SerializedName("visa_type") val visaType: String? = null,
    @SerializedName("visa_number") val visaNumber: String? = null,
    val status: String = "initiated",
    @SerializedName("interview_date") val interviewDate: String? = null,
    @SerializedName("medical_date") val medicalDate: String? = null,
    @SerializedName("deployment_date") val deploymentDate: String? = null,
    val remarks: String? = null,
    @SerializedName("partner_name") val partnerName: String? = null,
    @SerializedName("assigned_to_name") val assignedToName: String? = null,
    @SerializedName("document_count") val documentCount: Int = 0,
    @SerializedName("pending_document_count") val pendingDocumentCount: Int = 0,
    @SerializedName("agreed_amount") val agreedAmount: Double? = null,
    @SerializedName("paid_amount") val paidAmount: Double? = null,
    @SerializedName("balance_amount") val balanceAmount: Double? = null,
    @SerializedName("updated_at") val updatedAt: String? = null,
    // GET /projects/{id}
    val documents: List<DocumentDto>? = null,
    val checklist: List<ChecklistItemDto>? = null,
    @SerializedName("document_progress") val documentProgress: DocumentProgressDto? = null,
    @SerializedName("status_history") val statusHistory: List<HistoryDto>? = null,
)

data class ChecklistItemDto(
    @SerializedName("document_type_id") val documentTypeId: Int = 0,
    val name: String = "",
    val code: String = "",
    @SerializedName("is_required") val isRequired: Boolean = false,
    val uploaded: Boolean = false,
    @SerializedName("document_id") val documentId: Long? = null,
    val status: String = "missing",
)

data class DocumentProgressDto(
    val required: Int = 0,
    val verified: Int = 0,
    val uploaded: Int = 0,
    @SerializedName("percent_complete") val percentComplete: Int = 0,
)

data class ConvertRequest(
    @SerializedName("candidate_name") val candidateName: String? = null,
    val position: String? = null,
    @SerializedName("employer_name") val employerName: String? = null,
    @SerializedName("destination_country") val destinationCountry: String? = null,
    @SerializedName("passport_no") val passportNo: String? = null,
    val remarks: String? = null,
)

// ------------------------------------------------------------------ documents & forms

data class DocumentDto(
    val id: Long = 0,
    @SerializedName("project_id") val projectId: Long? = null,
    @SerializedName("project_code") val projectCode: String? = null,
    @SerializedName("lead_id") val leadId: Long? = null,
    @SerializedName("candidate_name") val candidateName: String? = null,
    @SerializedName("document_type_id") val documentTypeId: Int? = null,
    @SerializedName("document_type_name") val documentTypeName: String? = null,
    val title: String? = null,
    @SerializedName("file_name") val fileName: String = "",
    @SerializedName("mime_type") val mimeType: String? = null,
    @SerializedName("file_size") val fileSize: Long = 0,
    @SerializedName("file_size_display") val fileSizeDisplay: String? = null,
    @SerializedName("expiry_date") val expiryDate: String? = null,
    @SerializedName("verification_status") val verificationStatus: String = "pending",
    @SerializedName("reject_reason") val rejectReason: String? = null,
    @SerializedName("uploaded_by_name") val uploadedByName: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
)

data class FormTemplateDto(
    val id: Int = 0,
    val title: String = "",
    val description: String? = null,
    val category: String? = null,
    @SerializedName("file_name") val fileName: String = "",
    @SerializedName("mime_type") val mimeType: String? = null,
    @SerializedName("file_size") val fileSize: Long = 0,
    @SerializedName("file_size_display") val fileSizeDisplay: String? = null,
    val version: String? = null,
    @SerializedName("download_count") val downloadCount: Int = 0,
)

data class DocumentTypeDto(
    val id: Int = 0,
    val name: String = "",
    val code: String = "",
    @SerializedName("applies_to") val appliesTo: String = "project",
    @SerializedName("is_required") val isRequired: Boolean = false,
    @SerializedName("has_expiry") val hasExpiry: Boolean = false,
)

// ------------------------------------------------------------------ dashboard & lookups

data class DashboardDto(
    val leads: LeadCountsDto? = null,
    @SerializedName("follow_ups") val followUps: FollowUpCountsDto? = null,
    val calls: CallCountsDto? = null,
    val projects: ProjectCountsDto? = null,
    @SerializedName("conversion_rate") val conversionRate: Double = 0.0,
    val team: Map<String, Int>? = null,
    val documents: Map<String, Int>? = null,
    @SerializedName("unread_notifications") val unreadNotifications: Int = 0,
)

data class LeadCountsDto(
    val total: Int = 0,
    val new: Int = 0,
    val contacted: Int = 0,
    val interested: Int = 0,
    @SerializedName("follow_up") val followUp: Int = 0,
    @SerializedName("documents_pending") val documentsPending: Int = 0,
    val converted: Int = 0,
    @SerializedName("not_interested") val notInterested: Int = 0,
    val lost: Int = 0,
    @SerializedName("new_this_month") val newThisMonth: Int = 0,
)

data class FollowUpCountsDto(
    val today: Int = 0,
    val overdue: Int = 0,
    @SerializedName("this_week") val thisWeek: Int = 0,
)

data class CallCountsDto(
    val today: Int = 0,
    @SerializedName("today_connected") val todayConnected: Int = 0,
    @SerializedName("today_talk_time") val todayTalkTime: String? = null,
    val month: Int = 0,
    @SerializedName("month_talk_time") val monthTalkTime: String? = null,
)

data class ProjectCountsDto(
    val total: Int = 0,
    val active: Int = 0,
    val deployed: Int = 0,
    val completed: Int = 0,
    @SerializedName("converted_this_month") val convertedThisMonth: Int = 0,
)

data class LookupsDto(
    @SerializedName("lead_sources") val leadSources: List<NamedIdDto>? = null,
    @SerializedName("job_categories") val jobCategories: List<NamedIdDto>? = null,
    @SerializedName("document_types") val documentTypes: List<DocumentTypeDto>? = null,
    @SerializedName("lead_statuses") val leadStatuses: List<String>? = null,
    @SerializedName("project_statuses") val projectStatuses: List<String>? = null,
    @SerializedName("call_dispositions") val callDispositions: List<String>? = null,
    val settings: SettingsDto? = null,
)

data class NamedIdDto(val id: Int = 0, val name: String = "")

data class SettingsDto(
    @SerializedName("partner_can_convert") val partnerCanConvert: Boolean = true,
    @SerializedName("max_upload_mb") val maxUploadMb: Int = 15,
    @SerializedName("followup_reminder_minutes") val followupReminderMinutes: Int = 15,
    @SerializedName("agency_name") val agencyName: String? = null,
)

data class NotificationDto(
    val id: Long = 0,
    val title: String = "",
    val body: String? = null,
    val type: String = "general",
    @SerializedName("ref_type") val refType: String? = null,
    @SerializedName("ref_id") val refId: Long? = null,
    @SerializedName("is_read") val isRead: Boolean = false,
    @SerializedName("created_at") val createdAt: String? = null,
)
