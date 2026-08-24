package com.agency.leadmanager.util

/**
 * The API stores statuses as snake_case enums. This turns them into the words
 * a telecaller actually reads, in one place.
 */
object Labels {

    fun pretty(value: String?): String {
        if (value.isNullOrBlank()) return "—"
        return value.split('_').joinToString(" ") { part ->
            part.lowercase().replaceFirstChar { it.uppercase() }
        }
    }

    /** Lead statuses a telecaller can pick from the post-call popup. */
    val leadStatusChoices = listOf(
        "interested" to "Interested",
        "follow_up" to "Call back later",
        "contacted" to "Contacted",
        "documents_pending" to "Documents pending",
        "not_interested" to "Not interested",
        "invalid" to "Wrong number",
        "dnd" to "Do not call",
        "lost" to "Lost",
    )

    val callDispositions = listOf(
        "connected" to "Spoke to them",
        "no_answer" to "No answer",
        "busy" to "Busy",
        "switched_off" to "Switched off",
        "not_reachable" to "Not reachable",
        "wrong_number" to "Wrong number",
        "call_back_later" to "Asked to call later",
        "other" to "Other",
    )

    val priorities = listOf("high" to "High", "medium" to "Medium", "low" to "Low")

    val projectStatuses = listOf(
        "initiated", "documents_pending", "documents_verified", "interview_scheduled",
        "selected", "medical_pending", "medical_cleared", "pcc_pending",
        "visa_processing", "visa_approved", "ticket_booked", "deployed",
        "on_hold", "cancelled", "completed",
    )

    /** Stages only head office may set. */
    val adminOnlyProjectStatuses = setOf("deployed", "completed", "cancelled")
}
