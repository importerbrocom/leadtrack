package com.agency.leadmanager.sync

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/**
 * Re-arms the periodic work after a reboot or an app update, and immediately
 * runs a catch-up scan - calls made just before the reboot may not have been
 * captured.
 */
class BootReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        when (intent.action) {
            Intent.ACTION_BOOT_COMPLETED,
            Intent.ACTION_MY_PACKAGE_REPLACED -> {
                SyncScheduler.scheduleRecurring(context)
                SyncScheduler.catchUpNow(context)
                SyncScheduler.syncNow(context)
            }
        }
    }
}
