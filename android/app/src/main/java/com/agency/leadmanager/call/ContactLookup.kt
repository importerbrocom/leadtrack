package com.agency.leadmanager.call

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.net.Uri
import android.provider.ContactsContract
import android.util.Log
import androidx.core.content.ContextCompat

/**
 * Resolves a phone number to the name saved in the phone's contacts.
 *
 * Why this matters: when a telecaller rings someone already in their phonebook,
 * we can ask "Is Rajesh a lead?" instead of "Is +91 98765 43210 a lead?" - and
 * saving it becomes a single tap because the name is already known.
 *
 * The permission is optional. Without it everything still works, just with the
 * number instead of a name.
 */
class ContactLookup(private val context: Context) {

    fun hasPermission(): Boolean =
        ContextCompat.checkSelfPermission(context, Manifest.permission.READ_CONTACTS) ==
            PackageManager.PERMISSION_GRANTED

    /** Display name for this number, or null if it is not in the phonebook. */
    fun nameFor(phoneNumber: String?): String? {
        if (phoneNumber.isNullOrBlank() || !hasPermission()) {
            return null
        }

        // PhoneLookup does the fuzzy number matching for us, so +91/0/spacing
        // variants all resolve to the same contact.
        val uri: Uri = Uri.withAppendedPath(
            ContactsContract.PhoneLookup.CONTENT_FILTER_URI,
            Uri.encode(phoneNumber)
        )

        return try {
            context.contentResolver.query(
                uri,
                arrayOf(ContactsContract.PhoneLookup.DISPLAY_NAME),
                null,
                null,
                null
            )?.use { cursor ->
                if (cursor.moveToFirst()) {
                    val index = cursor.getColumnIndex(ContactsContract.PhoneLookup.DISPLAY_NAME)
                    if (index >= 0) cursor.getString(index)?.takeIf { it.isNotBlank() } else null
                } else {
                    null
                }
            }
        } catch (e: SecurityException) {
            Log.w(TAG, "Contacts permission was revoked")
            null
        } catch (e: Exception) {
            Log.e(TAG, "Contact lookup failed", e)
            null
        }
    }

    companion object {
        private const val TAG = "ContactLookup"
    }
}
