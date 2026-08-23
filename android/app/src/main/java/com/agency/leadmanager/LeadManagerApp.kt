package com.agency.leadmanager

import android.app.Application
import android.provider.Settings
import com.agency.leadmanager.data.repo.AuthRepository
import com.agency.leadmanager.notif.Notifier
import com.agency.leadmanager.sync.SyncScheduler

class LeadManagerApp : Application() {

    override fun onCreate() {
        super.onCreate()

        Notifier.createChannels(this)

        // Stable per-install identifier, so the server can keep one active
        // session per physical device.
        AuthRepository.ANDROID_ID = try {
            Settings.Secure.getString(contentResolver, Settings.Secure.ANDROID_ID)
        } catch (e: Exception) {
            null
        }

        val locator = ServiceLocator.from(this)

        // A 401 from anywhere means the token is dead (revoked by head office,
        // password changed, expired). Drop the local session so the UI returns
        // to the login screen.
        locator.apiClient.onUnauthorized = {
            SessionInvalidation.markInvalid()
        }

        SyncScheduler.scheduleRecurring(this)
        SyncScheduler.syncNow(this)
    }
}

/**
 * Tiny signal between the network layer and the UI. The interceptor runs on an
 * OkHttp thread and cannot suspend, so it just raises a flag that MainActivity
 * observes.
 */
object SessionInvalidation {

    @Volatile
    private var invalid: Boolean = false

    fun markInvalid() {
        invalid = true
    }

    /** Returns true once, then resets. */
    fun consume(): Boolean {
        if (!invalid) return false
        invalid = false
        return true
    }
}
