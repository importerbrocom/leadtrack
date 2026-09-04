package com.agency.leadmanager.call

import android.app.job.JobInfo
import android.app.job.JobParameters
import android.app.job.JobScheduler
import android.content.ComponentName
import android.content.Context
import android.provider.CallLog
import android.util.Log

/**
 * Watches the call log itself, rather than waiting to be told about calls.
 *
 * ## Why this exists
 *
 * Capture used to depend solely on the PHONE_STATE broadcast. That is precisely
 * what Xiaomi/MIUI's "Autostart" setting disables: with it off, a manifest
 * receiver is never delivered, so nothing is ever captured and there is no error
 * anywhere. Oppo, Vivo and Realme behave similarly.
 *
 * JobScheduler content-URI triggers are the platform's own answer. The system
 * watches the call log for us and runs this job when a row appears - no
 * broadcast, no foreground service, no battery cost while idle, and it keeps
 * working when the app has been swiped away.
 *
 * Three independent paths now lead to a capture, so one being blocked no longer
 * loses the call:
 *   1. this content trigger  (primary)
 *   2. the PHONE_STATE broadcast  (secondary)
 *   3. the 15-minute catch-up scan  (backstop)
 *
 * Content-trigger jobs fire once and are not kept across reboots, so the job
 * re-registers itself after every run, and [BootReceiver] re-registers on boot.
 */
class CallLogTriggerJobService : android.app.job.JobService() {

    override fun onStartJob(params: JobParameters?): Boolean {
        Log.d(TAG, "Call log changed, queueing capture")

        // Re-arm immediately: a content trigger is single-shot.
        schedule(applicationContext)

        // The heavy lifting (polling the log, matching the lead, notifying) is
        // the worker's job, so this returns straight away.
        CallCaptureWorker.enqueue(applicationContext, null)

        return false // nothing still running on this thread
    }

    override fun onStopJob(params: JobParameters?): Boolean = false

    companion object {
        private const val TAG = "CallLogTrigger"
        private const val JOB_ID = 8801

        /**
         * Ask the system to watch the call log. Safe to call repeatedly.
         */
        fun schedule(context: Context) {
            val scheduler = context.getSystemService(Context.JOB_SCHEDULER_SERVICE) as? JobScheduler
                ?: return

            try {
                val job = JobInfo.Builder(
                    JOB_ID,
                    ComponentName(context, CallLogTriggerJobService::class.java)
                )
                    .addTriggerContentUri(
                        JobInfo.TriggerContentUri(
                            CallLog.Calls.CONTENT_URI,
                            JobInfo.TriggerContentUri.FLAG_NOTIFY_FOR_DESCENDANTS
                        )
                    )
                    // Wait a moment after the change so the row is fully written,
                    // and coalesce the several updates a single call produces.
                    .setTriggerContentUpdateDelay(1_000)
                    .setTriggerContentMaxDelay(5_000)
                    .build()

                val result = scheduler.schedule(job)

                if (result != JobScheduler.RESULT_SUCCESS) {
                    Log.w(TAG, "Could not register the call-log watcher")
                }
            } catch (e: Exception) {
                // Some heavily modified ROMs reject content triggers. The
                // broadcast and the periodic scan still cover us.
                Log.w(TAG, "Content trigger unavailable on this device", e)
            }
        }

        fun cancel(context: Context) {
            (context.getSystemService(Context.JOB_SCHEDULER_SERVICE) as? JobScheduler)
                ?.cancel(JOB_ID)
        }

        fun isScheduled(context: Context): Boolean {
            val scheduler = context.getSystemService(Context.JOB_SCHEDULER_SERVICE) as? JobScheduler
                ?: return false

            return scheduler.allPendingJobs.any { it.id == JOB_ID }
        }
    }
}
