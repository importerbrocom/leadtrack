package com.agency.leadmanager.data.prefs

import android.content.Context
import androidx.datastore.preferences.core.booleanPreferencesKey
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.intPreferencesKey
import androidx.datastore.preferences.core.longPreferencesKey
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import com.agency.leadmanager.BuildConfig
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.runBlocking

private val Context.dataStore by preferencesDataStore(name = "lead_manager_session")

/**
 * Holds the bearer token, who is signed in, and the server address.
 *
 * The token is read synchronously by the OkHttp interceptor, so it is also
 * cached in memory after the first read.
 */
class SessionStore(private val context: Context) {

    private object Keys {
        val TOKEN = stringPreferencesKey("token")
        val USER_ID = intPreferencesKey("user_id")
        val USER_NAME = stringPreferencesKey("user_name")
        val USER_ROLE = stringPreferencesKey("user_role")
        val USER_PHONE = stringPreferencesKey("user_phone")
        val AGENCY_NAME = stringPreferencesKey("agency_name")
        val BASE_URL = stringPreferencesKey("base_url")
        val PARTNER_CAN_CONVERT = booleanPreferencesKey("partner_can_convert")
        val LAST_CALL_SCAN_AT = longPreferencesKey("last_call_scan_at")
        val CALL_TRACKING_ENABLED = booleanPreferencesKey("call_tracking_enabled")
    }

    @Volatile
    private var cachedToken: String? = null

    val tokenFlow: Flow<String?> = context.dataStore.data.map { it[Keys.TOKEN] }

    val isLoggedInFlow: Flow<Boolean> = tokenFlow.map { !it.isNullOrBlank() }

    val userFlow: Flow<StoredUser?> = context.dataStore.data.map { prefs ->
        val id = prefs[Keys.USER_ID] ?: return@map null
        StoredUser(
            id = id,
            name = prefs[Keys.USER_NAME].orEmpty(),
            role = prefs[Keys.USER_ROLE] ?: "telecaller",
            phone = prefs[Keys.USER_PHONE].orEmpty(),
            agencyName = prefs[Keys.AGENCY_NAME],
        )
    }

    val callTrackingEnabledFlow: Flow<Boolean> =
        context.dataStore.data.map { it[Keys.CALL_TRACKING_ENABLED] ?: true }

    suspend fun save(token: String, user: StoredUser) {
        cachedToken = token
        context.dataStore.edit { prefs ->
            prefs[Keys.TOKEN] = token
            prefs[Keys.USER_ID] = user.id
            prefs[Keys.USER_NAME] = user.name
            prefs[Keys.USER_ROLE] = user.role
            prefs[Keys.USER_PHONE] = user.phone
            user.agencyName?.let { prefs[Keys.AGENCY_NAME] = it }
        }
    }

    suspend fun updateUser(user: StoredUser) {
        context.dataStore.edit { prefs ->
            prefs[Keys.USER_ID] = user.id
            prefs[Keys.USER_NAME] = user.name
            prefs[Keys.USER_ROLE] = user.role
            prefs[Keys.USER_PHONE] = user.phone
            user.agencyName?.let { prefs[Keys.AGENCY_NAME] = it }
        }
    }

    suspend fun clear() {
        cachedToken = null
        context.dataStore.edit { prefs ->
            val baseUrl = prefs[Keys.BASE_URL]
            prefs.clear()
            // Keep the server address so the user does not retype it after logout.
            baseUrl?.let { prefs[Keys.BASE_URL] = it }
        }
    }

    /** Read by the auth interceptor on a background thread. */
    fun tokenBlocking(): String? {
        cachedToken?.let { return it }
        val token = runBlocking { context.dataStore.data.first()[Keys.TOKEN] }
        cachedToken = token
        return token
    }

    suspend fun token(): String? = context.dataStore.data.first()[Keys.TOKEN]

    suspend fun user(): StoredUser? = userFlow.first()

    // ---------------------------------------------------------- server address
    fun baseUrlBlocking(): String =
        runBlocking { context.dataStore.data.first()[Keys.BASE_URL] }
            ?.takeIf { it.isNotBlank() }
            ?: BuildConfig.DEFAULT_API_BASE_URL

    suspend fun baseUrl(): String =
        context.dataStore.data.first()[Keys.BASE_URL]?.takeIf { it.isNotBlank() }
            ?: BuildConfig.DEFAULT_API_BASE_URL

    suspend fun setBaseUrl(url: String) {
        val normalised = url.trim().let { if (it.endsWith("/")) it else "$it/" }
        context.dataStore.edit { it[Keys.BASE_URL] = normalised }
    }

    // ---------------------------------------------------------- misc flags
    suspend fun setPartnerCanConvert(value: Boolean) {
        context.dataStore.edit { it[Keys.PARTNER_CAN_CONVERT] = value }
    }

    suspend fun partnerCanConvert(): Boolean =
        context.dataStore.data.first()[Keys.PARTNER_CAN_CONVERT] ?: true

    suspend fun setCallTrackingEnabled(value: Boolean) {
        context.dataStore.edit { it[Keys.CALL_TRACKING_ENABLED] = value }
    }

    suspend fun callTrackingEnabled(): Boolean =
        context.dataStore.data.first()[Keys.CALL_TRACKING_ENABLED] ?: true

    /**
     * Watermark for the call-log scanner: only calls newer than this are
     * considered, so we never re-import the user's whole call history.
     */
    suspend fun lastCallScanAt(): Long =
        context.dataStore.data.first()[Keys.LAST_CALL_SCAN_AT] ?: 0L

    suspend fun setLastCallScanAt(millis: Long) {
        context.dataStore.edit { it[Keys.LAST_CALL_SCAN_AT] = millis }
    }
}

data class StoredUser(
    val id: Int,
    val name: String,
    val role: String,
    val phone: String,
    val agencyName: String? = null,
) {
    val isAdmin: Boolean get() = role == "admin"
    val isPartner: Boolean get() = role == "partner"
    val isTelecaller: Boolean get() = role == "telecaller"
}
