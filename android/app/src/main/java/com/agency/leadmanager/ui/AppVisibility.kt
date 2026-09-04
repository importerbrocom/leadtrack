package com.agency.leadmanager.ui

/**
 * Tracks whether any of our activities is currently on screen.
 *
 * Used to decide whether the post-call sheet can be shown directly. Since
 * Android 10, launching an activity from the background is blocked, so the
 * notification is the mechanism we rely on - this only lets us offer the nicer
 * experience when the app already has a visible window.
 */
object AppVisibility {

    @Volatile
    private var resumedCount: Int = 0

    val isForeground: Boolean get() = resumedCount > 0

    fun onResumed() {
        resumedCount++
    }

    fun onPaused() {
        resumedCount = (resumedCount - 1).coerceAtLeast(0)
    }
}
