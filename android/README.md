# Lead Manager — Android app

Kotlin + Jetpack Compose. Records calls automatically and works offline.

## Build

```bash
echo "sdk.dir=/path/to/Android/sdk" > local.properties
./gradlew assembleDebug      # app/build/outputs/apk/debug/
./gradlew assembleRelease    # app/build/outputs/apk/release/
```

Set your server before building a release APK, in `app/build.gradle.kts`:

```kotlin
buildConfigField("String", "DEFAULT_API_BASE_URL", "\"https://yourdomain.com/api/\"")
```

**Signing.** An unsigned APK will not install. Copy
`keystore.properties.example` to `keystore.properties` (git-ignored) and point it
at your keystore; `assembleRelease` then produces a signed
`leadtrack-<version>-release.apk`. Without that file the build still succeeds but
the APK is unsigned.

Back the keystore up: lose it and you can never update the app on phones that
already have it installed.

Full instructions and distribution options: see
[../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md).

## Requirements

- Android 8.0 (API 26) or newer
- compileSdk 35, AGP 8.7.3, Kotlin 2.1.0

## How it is put together

```
call/                     Automatic call tracking
  CallStateReceiver       PHONE_STATE + NEW_OUTGOING_CALL -> "a call just ended"
  CallCaptureService      Foreground service: polls the call log, queues the call
  CallLogScanner          Reads the platform call log (the source of truth for duration)
  PostCallActivity        The popup: outcome, status, callback time, save-as-new-lead

data/
  remote/                 Retrofit interface + DTOs + auth interceptor
  local/                  Room: the outbox (pending calls / leads / status changes)
                          plus a lead cache so the popup works with no signal
  prefs/SessionStore       DataStore: token, current user, server URL
  repo/                   Repositories. All offline queuing lives here.

sync/
  SyncWorker              Drains the outbox
  CallCatchUpWorker       Every 15 min: backfills calls the receiver missed
  ReminderWorker          Raises callback reminders
  SyncScheduler           All the WorkManager scheduling in one place

ui/                       Compose screens (login, home, leads, callbacks,
                          projects, forms, team, settings)
ServiceLocator.kt         Hand-rolled DI - one graph, no annotation processor
```

## Design decisions worth knowing

**Why read the call log instead of timing the call ourselves?** Our own
stopwatch would count ringing time and would be wrong whenever the process is
killed mid-call. The platform's `CallLog.Calls.DURATION` is the billed duration,
and it is what the office should see.

**Why a foreground service for something so small?** The call log row is written
asynchronously, so we must wait and retry. A `BroadcastReceiver` gets only a few
seconds and the app is in the background at that moment (the user was on a call),
so a plain coroutine could be killed halfway.

**Why write to Room before the network?** Because calls happen in basements and
lifts. The row is saved first and deleted only when the server confirms it.

**Why is syncing twice safe?** Every queued call carries Android's own
`CallLog._ID`. The server upserts on `(user_id, device_call_id)` and recomputes
each lead's call count and talk time from the call table, so a duplicate sync
cannot inflate anyone's numbers.

**Why match on the last 10 digits?** Devices report numbers inconsistently
(`+919876543210`, `09876543210`, `98765 43210`). The last 10 digits are stable
for Indian numbers, so both the app and the server normalise to that.

## Known constraints

- **`READ_CALL_LOG` is restricted on Google Play.** Distribute the APK directly
  or via Managed Google Play. See the deployment guide.
- **OEM battery managers** (Xiaomi, Oppo, Vivo, Realme, Samsung) may kill the
  capture service. Exclude the app from battery optimisation. The 15-minute
  catch-up scan covers anything that still slips through.
- **Dual SIM:** the SIM slot is not reported by the public call-log API on all
  devices, so `sim_slot` is often null. Number and duration are unaffected.
- `ACTION_NEW_OUTGOING_CALL` and `EXTRA_INCOMING_NUMBER` are deprecated but are
  still the only way for a non-dialler app to see the number as a call starts.
  If they fail, the post-call log read still captures everything.
