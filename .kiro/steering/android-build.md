---
inclusion: fileMatch
fileMatchPattern: 'android/**'
---

# Building and shipping the Android app

## The build command

```bash
cd android
export ANDROID_HOME=/path/to/android-sdk
export ANDROID_SDK_ROOT="$ANDROID_HOME"
echo "sdk.dir=$ANDROID_HOME" > local.properties
printf "storeFile=%s\nstorePassword=%s\nkeyAlias=leadtrack\nkeyPassword=%s\n" \
  "$KEYSTORE" "$PW" "$PW" > keystore.properties

gradle --no-daemon -Dorg.gradle.java.home=/path/to/java17 clean :app:assembleRelease

rm -f keystore.properties
```

Three things bite every time:

- **`clean` is mandatory.** An incremental build skips shrinking and silently
  produces a ~3.8 MB APK instead of ~1.9 MB.
- **`-Dorg.gradle.java.home` pointing at Java 17 is mandatory.** Otherwise Gradle
  picks a newer JDK and fails with a bare, meaningless `25.0.2`.
- **Delete `keystore.properties` afterwards.** It is gitignored, but do not leave
  passwords on disk.

## Signing

Every build must be signed with the same key or phones refuse the update as a
different app. Verify before publishing:

```bash
apksigner verify --print-certs <apk> | grep -i "SHA-256 digest"
# must be cc368522807ef3764d7ac386dbd30631f48bb865e4be7ad4d25c03d445e61828
```

The keystore and its password are **not in this repo** and must not be. Losing them
means never being able to update an installed app again — confirm they are backed up
somewhere durable.

## Publishing

APKs go to the **`dist` branch** under `dist/`. `*.apk` is gitignored, so it needs
`git add -f`. Download URL:

```
https://raw.githubusercontent.com/importerbrocom/leadtrack/dist/dist/leadtrack-<version>.apk
```

Rejected alternatives: GitHub release assets (the build sandbox cannot reach
`uploads.github.com`, returns HTTP 400) and the workspace file explorer download.

The owner then mirrors it onto the server so `https://leadtrack.nokkoo.in/app/`
serves it, and the admin panel has a **Mobile App** page for uploads. Always give
the owner the expected `sha256sum` so a truncated download is caught immediately.

Bump both `versionCode` and `versionName` in `app/build.gradle.kts` for every build,
and always tell the owner the version — the `auth_tokens.app_version` column is only
written at login and goes stale, so **Settings → About → App version** on the phone
is the only trustworthy report of what is running.

## APK signature detection in the admin panel

Reads the ZIP end-of-central-directory, finds the central-directory offset and looks
for the `APK Sig Block 42` magic, falling back to `META-INF` for v1. A `META-INF`-only
check was rejected because it rejects valid v2/v3-signed APKs (v1 is disabled at
minSdk 26). Inconclusive detection **allows** the upload — the phone refuses an
unsigned APK anyway.
