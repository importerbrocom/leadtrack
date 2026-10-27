---
inclusion: always
---

# Automatic call capture — design and open investigation

This is the feature the whole product was asked for: after a call ends, the number and
duration are recorded automatically and a notification asks *"Is this a lead?"*, with
one tap to create the lead.

**Status: still not working on the owner's phone as of v1.0.5.** `call_logs` on the
server has 0 rows. Read this before changing anything in `android/.../call/`.

## Test device

POCO X6 Pro, model `2311DRK48I`, HyperOS (Android 14). `Build.MANUFACTURER` is
`Xiaomi` on POCO, so the Xiaomi branch of the diagnostics covers it.

## How capture is meant to work

Three independent triggers, so one being blocked does not lose the call:

1. `CallLogTriggerJobService` — JobScheduler `addTriggerContentUri(CallLog.Calls.CONTENT_URI)`.
   **Primary.** Single-shot, so it re-arms itself in `onStartJob`, and `BootReceiver`
   re-registers it on boot. Needs `android:permission="android.permission.BIND_JOB_SERVICE"`
   in the manifest.
2. `CallStateReceiver` — the `PHONE_STATE` broadcast. Secondary.
3. `CallCatchUpWorker` — a 15-minute periodic scan. Backstop.

All three funnel into `CallCaptureWorker`, which polls the platform call log for the
row (the platform writes it asynchronously), matches an existing lead by phone,
records it locally, and raises the notification.

## Fixes already shipped, and why

Each of these was a real bug that failed **silently**:

- **1.0.1** — `PostCallActivity` was launched with `context.startActivity()` from the
  background. Android 10+ blocks background activity starts, so the popup was
  discarded with no error. Replaced by a high-priority notification
  (`Notifier.showPostCallPrompt`) plus `PostCallActionReceiver` for one-tap
  "Yes, add lead".
- **1.0.2** — added the diagnostics screen (Settings → "Calls not being recorded?").
- **1.0.3** — `CallStateReceiver` called `startForegroundService()` from the background.
  Android 12+ throws `ForegroundServiceStartNotAllowedException` (`PHONE_STATE` is not
  an exemption); it was caught and logged at warn level, so capture looked healthy and
  did nothing. `CallCaptureService` deleted, replaced by expedited WorkManager with
  `OutOfQuotaPolicy.RUN_AS_NON_EXPEDITED_WORK_REQUEST`.
- **1.0.4** — the only trigger was still the `PHONE_STATE` broadcast, which Xiaomi
  suppresses entirely when Autostart is off. Added the content-URI trigger.
- **1.0.5** — diagnostics only. Reports the AppOps mode for `READ_CALL_LOG` and the
  raw-versus-usable row counts, to end the guessing (see below).

## What has been eliminated by evidence

From the owner's diagnostics screenshots on v1.0.4, everything green:

signed in as a telecaller · `READ_CALL_LOG` granted (Android **and** HyperOS, which
also reports "Accessed in past 24 hours") · phone and contacts granted · notifications
allowed · battery unrestricted · call log watcher **active** · tracking switch on ·
queue empty · server reachable. The synthetic test prompt renders and behaves perfectly.

So the notification pipeline is proven working end to end. The failure is that the
call log **reads back empty with no exception**: "Calls visible to the app: 0 in the
last 7 days", the unfiltered `latestCall()` query also returns nothing, and
"Scan my recent calls" reports `Found 0 call(s) on the phone`. The dialer clearly has
history, so the rows exist and the app is being handed a filtered view.

## The two remaining candidates

`CallLogScanner.probe()` was added in 1.0.5 to tell them apart:

1. **Provider returns 0 rows.** `READ_CALL_LOG` is a *hard-restricted* permission.
   A sideloaded install can hold the permission while the AppOps op is `MODE_IGNORED`,
   and the provider returns an empty cursor rather than throwing. Not fixable in app
   code. Remedies: install via `adb install` (which whitelists restricted permissions),
   or `adb shell appops set com.agency.leadmanager READ_CALL_LOG allow`, or ship
   through Play Store with a declared call-log use case, or take the default-dialer
   role exemption.
2. **Provider returns rows with `NUMBER` blanked.** Some Xiaomi builds redact the
   column for non-dialer apps. `readCall()` drops any row with a blank number, giving
   an identical symptom. **This one is ours to fix** — fall back to
   `CallLog.Calls.CACHED_NUMBER` and tolerate nulls.

The diagnostics screen now names which it is. Get that screenshot before writing code.

## Design decisions to preserve

- **Notification, not an overlay.** `SYSTEM_ALERT_WINDOW` was rejected: it needs an
  extra permission, and the owner explicitly asked for a notification.
- **Expedited WorkManager, not a foreground service.** `goAsync()` in the receiver was
  rejected too — roughly 10 seconds and vulnerable to process death.
- **Content-URI trigger, not a persistent foreground service + ContentObserver.**
  Android 15 caps `dataSync` at 6h/day and an FGS needs a permanent notification.
  Content triggers are system-managed, cost nothing while idle, and survive the app
  being swiped away.
- **Duration comes from the platform call log, never our own stopwatch.** A stopwatch
  would count ringing time and break if the process died mid-call.
- **Outgoing calls prompt even at 0 duration** (dialling is intent); incoming only when
  answered, otherwise spam calls nag.
- **An empty name box falls back to the formatted phone number.** It used to mean
  "do not create a lead", which silently discarded numbers.
- `recordCall()` returns the **existing** row id on a duplicate `device_call_id`, so
  a repeat trigger must never suppress the prompt.

## Diagnosing without a device

There is no Android device in the build sandbox and every failure so far has been
OEM-specific, so **a build cannot be verified here**. Do not ship a speculative fix.
Add a check to the diagnostics screen that distinguishes the hypotheses, have the
owner screenshot it, and then fix what it names.
