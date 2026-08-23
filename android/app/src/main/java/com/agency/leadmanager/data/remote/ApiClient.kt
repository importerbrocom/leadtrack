package com.agency.leadmanager.data.remote

import com.agency.leadmanager.BuildConfig
import com.agency.leadmanager.data.prefs.SessionStore
import okhttp3.Interceptor
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

/**
 * Builds the Retrofit client.
 *
 * The base URL can be changed at runtime (a franchise might be moved to another
 * domain), so the client is rebuilt whenever it changes rather than being a
 * hard singleton.
 */
class ApiClient(private val session: SessionStore) {

    @Volatile
    private var currentBaseUrl: String? = null

    @Volatile
    private var cachedApi: LeadApi? = null

    /** Called when the token becomes invalid, so the UI can bounce to login. */
    var onUnauthorized: (() -> Unit)? = null

    val api: LeadApi
        get() {
            val baseUrl = session.baseUrlBlocking()
            val existing = cachedApi

            if (existing != null && baseUrl == currentBaseUrl) {
                return existing
            }

            return synchronized(this) {
                val again = cachedApi
                if (again != null && baseUrl == currentBaseUrl) {
                    again
                } else {
                    val built = build(baseUrl)
                    currentBaseUrl = baseUrl
                    cachedApi = built
                    built
                }
            }
        }

    /** Force a rebuild, e.g. right after the server address is changed. */
    fun invalidate() {
        synchronized(this) {
            cachedApi = null
            currentBaseUrl = null
        }
    }

    private fun build(baseUrl: String): LeadApi {
        val authInterceptor = Interceptor { chain ->
            val builder = chain.request().newBuilder()
                .header("Accept", "application/json")

            session.tokenBlocking()?.let { token ->
                builder.header("Authorization", "Bearer $token")
            }

            val response = chain.proceed(builder.build())

            // 401 means the token was revoked or expired server-side.
            if (response.code == 401) {
                onUnauthorized?.invoke()
            }

            response
        }

        val clientBuilder = OkHttpClient.Builder()
            .addInterceptor(authInterceptor)
            .connectTimeout(20, TimeUnit.SECONDS)
            .readTimeout(45, TimeUnit.SECONDS)
            .writeTimeout(60, TimeUnit.SECONDS) // document uploads can be slow
            .retryOnConnectionFailure(true)

        if (BuildConfig.DEBUG) {
            clientBuilder.addInterceptor(
                HttpLoggingInterceptor().apply { level = HttpLoggingInterceptor.Level.BODY }
            )
        }

        return Retrofit.Builder()
            .baseUrl(baseUrl)
            .client(clientBuilder.build())
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(LeadApi::class.java)
    }
}
