package com.agency.leadmanager.util

import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Date
import java.util.Locale

/**
 * The API speaks MySQL DATETIME ("yyyy-MM-dd HH:mm:ss") in the agency's own
 * timezone. Everything here converts between that and what a human reads.
 */
object DateUtils {

    private const val API_PATTERN = "yyyy-MM-dd HH:mm:ss"
    private const val API_DATE_PATTERN = "yyyy-MM-dd"

    private fun apiFormat() = SimpleDateFormat(API_PATTERN, Locale.US)
    private fun apiDateFormat() = SimpleDateFormat(API_DATE_PATTERN, Locale.US)

    fun toApi(millis: Long): String = apiFormat().format(Date(millis))

    fun toApiDate(millis: Long): String = apiDateFormat().format(Date(millis))

    fun parse(value: String?): Date? {
        if (value.isNullOrBlank()) return null
        return try {
            apiFormat().parse(value)
        } catch (e: Exception) {
            try {
                apiDateFormat().parse(value)
            } catch (e2: Exception) {
                null
            }
        }
    }

    fun parseMillis(value: String?): Long? = parse(value)?.time

    /** "23 Aug, 6:30 pm" */
    fun pretty(value: String?): String {
        val date = parse(value) ?: return "—"
        return SimpleDateFormat("d MMM, h:mm a", Locale.getDefault()).format(date)
    }

    /** "23 Aug 2026" */
    fun prettyDate(value: String?): String {
        val date = parse(value) ?: return "—"
        return SimpleDateFormat("d MMM yyyy", Locale.getDefault()).format(date)
    }

    fun prettyTime(millis: Long): String =
        SimpleDateFormat("d MMM, h:mm a", Locale.getDefault()).format(Date(millis))

    /** "in 2h" / "3d ago" / "just now" */
    fun relative(value: String?): String {
        val date = parse(value) ?: return ""
        val diff = date.time - System.currentTimeMillis()
        val past = diff < 0
        val abs = kotlin.math.abs(diff)

        val text = when {
            abs < 60_000L -> return "just now"
            abs < 3_600_000L -> "${abs / 60_000L}m"
            abs < 86_400_000L -> "${abs / 3_600_000L}h"
            abs < 2_592_000_000L -> "${abs / 86_400_000L}d"
            else -> "${abs / 2_592_000_000L}mo"
        }

        return if (past) "$text ago" else "in $text"
    }

    fun isOverdue(value: String?): Boolean {
        val millis = parseMillis(value) ?: return false
        return millis < System.currentTimeMillis()
    }

    fun isToday(value: String?): Boolean {
        val millis = parseMillis(value) ?: return false
        val then = Calendar.getInstance().apply { timeInMillis = millis }
        val now = Calendar.getInstance()
        return then.get(Calendar.YEAR) == now.get(Calendar.YEAR) &&
            then.get(Calendar.DAY_OF_YEAR) == now.get(Calendar.DAY_OF_YEAR)
    }

    /** 143 -> "2m 23s" (matches the server's humanDuration). */
    fun duration(seconds: Int): String {
        if (seconds < 60) return "${seconds}s"

        var minutes = seconds / 60
        val secs = seconds % 60

        if (minutes < 60) {
            return if (secs == 0) "${minutes}m" else "${minutes}m ${secs}s"
        }

        val hours = minutes / 60
        minutes %= 60
        return "${hours}h ${minutes}m"
    }

    /** Handy presets for the "call me back at…" picker. */
    fun inHours(hours: Int): Long =
        Calendar.getInstance().apply { add(Calendar.HOUR_OF_DAY, hours) }.timeInMillis

    fun tomorrowAt(hour: Int, minute: Int = 0): Long =
        Calendar.getInstance().apply {
            add(Calendar.DAY_OF_YEAR, 1)
            set(Calendar.HOUR_OF_DAY, hour)
            set(Calendar.MINUTE, minute)
            set(Calendar.SECOND, 0)
        }.timeInMillis

    fun inDays(days: Int, hour: Int = 10, minute: Int = 0): Long =
        Calendar.getInstance().apply {
            add(Calendar.DAY_OF_YEAR, days)
            set(Calendar.HOUR_OF_DAY, hour)
            set(Calendar.MINUTE, minute)
            set(Calendar.SECOND, 0)
        }.timeInMillis
}
