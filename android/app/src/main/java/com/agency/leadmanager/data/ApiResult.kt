package com.agency.leadmanager.data

import com.agency.leadmanager.data.remote.dto.ApiEnvelope
import com.google.gson.Gson
import retrofit2.Response
import java.io.IOException
import java.net.SocketTimeoutException
import java.net.UnknownHostException

/**
 * A small result type so screens can show a real message instead of a stack
 * trace. Network failures are turned into plain-English text.
 */
sealed class ApiResult<out T> {
    data class Success<T>(val data: T, val message: String? = null) : ApiResult<T>()

    data class Failure(
        val message: String,
        val statusCode: Int? = null,
        val fieldErrors: Map<String, String> = emptyMap(),
    ) : ApiResult<Nothing>() {
        val isUnauthorized: Boolean get() = statusCode == 401
        val isOffline: Boolean get() = statusCode == null
    }

    val successOrNull: T? get() = (this as? Success)?.data
}

/**
 * Unwrap an API envelope, mapping HTTP and transport problems onto Failure.
 */
suspend fun <T> apiCall(block: suspend () -> Response<ApiEnvelope<T>>): ApiResult<T> {
    return try {
        val response = block()
        val body = response.body()

        if (response.isSuccessful && body != null && body.success) {
            val data = body.data
            if (data != null) {
                ApiResult.Success(data, body.message)
            } else {
                // 200 with a null payload (e.g. logout) - treat Unit as success.
                @Suppress("UNCHECKED_CAST")
                ApiResult.Success(Unit as T, body.message)
            }
        } else {
            val envelope = body ?: parseErrorBody<T>(response)
            ApiResult.Failure(
                message = envelope?.message?.takeIf { it.isNotBlank() }
                    ?: defaultHttpMessage(response.code()),
                statusCode = response.code(),
                fieldErrors = envelope?.errors ?: emptyMap(),
            )
        }
    } catch (e: UnknownHostException) {
        ApiResult.Failure("No internet connection. Your work is saved and will sync automatically.")
    } catch (e: SocketTimeoutException) {
        ApiResult.Failure("The server took too long to respond. Please try again.")
    } catch (e: IOException) {
        ApiResult.Failure("Could not reach the server. Check your connection.")
    } catch (e: Exception) {
        ApiResult.Failure(e.message ?: "Something went wrong")
    }
}

private fun <T> parseErrorBody(response: Response<ApiEnvelope<T>>): ApiEnvelope<T>? {
    val raw = try {
        response.errorBody()?.string()
    } catch (e: Exception) {
        null
    } ?: return null

    return try {
        @Suppress("UNCHECKED_CAST")
        Gson().fromJson(raw, ApiEnvelope::class.java) as ApiEnvelope<T>
    } catch (e: Exception) {
        null
    }
}

private fun defaultHttpMessage(code: Int): String = when (code) {
    401 -> "Your session has expired. Please sign in again."
    403 -> "You do not have permission to do that."
    404 -> "Not found."
    409 -> "That conflicts with existing data."
    422 -> "Please check the details you entered."
    in 500..599 -> "The server had a problem. Please try again shortly."
    else -> "Request failed ($code)."
}
