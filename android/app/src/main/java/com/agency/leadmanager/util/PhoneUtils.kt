package com.agency.leadmanager.util

/**
 * Phone numbers arrive in a dozen shapes: +919876543210, 0091 98765 43210,
 * 09876543210, "98765 43210". The server matches leads on the last 10 digits,
 * so the app normalises the same way before comparing anything locally.
 */
object PhoneUtils {

    fun normalize(raw: String?): String? {
        if (raw.isNullOrBlank()) return null

        val digits = raw.filter { it.isDigit() }
        if (digits.isEmpty()) return null

        return if (digits.length > 10) digits.takeLast(10) else digits
    }

    fun isValid(raw: String?): Boolean {
        val digits = raw?.filter { it.isDigit() } ?: return false
        return digits.length in 10..15
    }

    /** "98765 43210" - easier to read out loud than a 10-digit run. */
    fun formatForDisplay(raw: String?): String {
        val digits = normalize(raw) ?: return raw.orEmpty()
        return if (digits.length == 10) {
            "${digits.substring(0, 5)} ${digits.substring(5)}"
        } else {
            digits
        }
    }

    /** WhatsApp needs a country code; assume India when one is missing. */
    fun toWhatsAppNumber(raw: String?, defaultCountryCode: String = "91"): String? {
        val digits = raw?.filter { it.isDigit() } ?: return null
        return when {
            digits.isEmpty() -> null
            digits.length > 10 -> digits
            else -> defaultCountryCode + digits
        }
    }

    fun sameNumber(a: String?, b: String?): Boolean {
        val na = normalize(a) ?: return false
        val nb = normalize(b) ?: return false
        return na == nb
    }
}
