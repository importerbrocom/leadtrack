package com.agency.leadmanager.data.repo

import android.os.Build
import com.agency.leadmanager.BuildConfig
import com.agency.leadmanager.data.ApiResult
import com.agency.leadmanager.data.apiCall
import com.agency.leadmanager.data.local.AppDatabase
import com.agency.leadmanager.data.prefs.SessionStore
import com.agency.leadmanager.data.prefs.StoredUser
import com.agency.leadmanager.data.remote.ApiClient
import com.agency.leadmanager.data.remote.dto.ChangePasswordRequest
import com.agency.leadmanager.data.remote.dto.LoginRequest
import com.agency.leadmanager.data.remote.dto.LoginResponse
import com.agency.leadmanager.data.remote.dto.UserDto
import java.util.UUID

class AuthRepository(
    private val client: ApiClient,
    private val session: SessionStore,
    private val db: AppDatabase,
) {

    val isLoggedInFlow = session.isLoggedInFlow
    val userFlow = session.userFlow

    suspend fun login(login: String, password: String, serverUrl: String?): ApiResult<UserDto> {
        // Let the user point the app at their own server on the login screen.
        if (!serverUrl.isNullOrBlank()) {
            session.setBaseUrl(serverUrl)
            client.invalidate()
        }

        val result = apiCall {
            client.api.login(
                LoginRequest(
                    login = login.trim(),
                    password = password,
                    deviceId = deviceId(),
                    deviceName = "${Build.MANUFACTURER} ${Build.MODEL}".trim(),
                    appVersion = BuildConfig.VERSION_NAME,
                )
            )
        }

        return when (result) {
            is ApiResult.Failure -> result
            is ApiResult.Success -> {
                val body: LoginResponse = result.data
                val user = body.user

                if (body.token.isBlank() || user == null) {
                    ApiResult.Failure("The server did not return a valid session")
                } else {
                    session.save(
                        token = body.token,
                        user = StoredUser(
                            id = user.id,
                            name = user.name,
                            role = user.role,
                            phone = user.phone,
                            agencyName = user.agencyName,
                        )
                    )
                    ApiResult.Success(user, result.message)
                }
            }
        }
    }

    /** Refresh who we are; also confirms the token is still good. */
    suspend fun refreshMe(): ApiResult<UserDto> {
        val result = apiCall { client.api.me() }

        return when (result) {
            is ApiResult.Failure -> result
            is ApiResult.Success -> {
                val user = result.data.user
                    ?: return ApiResult.Failure("Could not read your account")

                session.updateUser(
                    StoredUser(
                        id = user.id,
                        name = user.name,
                        role = user.role,
                        phone = user.phone,
                        agencyName = user.agencyName,
                    )
                )
                ApiResult.Success(user)
            }
        }
    }

    /** Cache the agency's workflow settings for offline use. */
    suspend fun refreshLookups() {
        val result = apiCall { client.api.lookups() }
        if (result is ApiResult.Success) {
            result.data.settings?.let { session.setPartnerCanConvert(it.partnerCanConvert) }
        }
    }

    suspend fun changePassword(current: String, new: String): ApiResult<Unit> =
        apiCall { client.api.changePassword(ChangePasswordRequest(current, new)) }

    /**
     * Sign out. Tries to revoke the token server-side, but always clears local
     * state - the user asked to leave, so leaving must not depend on a network.
     */
    suspend fun logout(clearQueue: Boolean = false) {
        try {
            apiCall { client.api.logout() }
        } catch (e: Exception) {
            // ignored on purpose
        }

        session.clear()
        db.cachedLeadDao().clear()

        // Unsent calls are kept by default: they belong to the agency, not the
        // session, and will go up when someone signs in again.
        if (clearQueue) {
            db.pendingCallDao().dropExhausted(0)
        }

        client.invalidate()
    }

    suspend fun setServerUrl(url: String) {
        session.setBaseUrl(url)
        client.invalidate()
    }

    suspend fun serverUrl(): String = session.baseUrl()

    private fun deviceId(): String = ANDROID_ID ?: UUID.randomUUID().toString()

    companion object {
        /**
         * Stable per-install id. Set once by the Application so the repository
         * does not need a Context.
         */
        @Volatile
        var ANDROID_ID: String? = null
    }
}
