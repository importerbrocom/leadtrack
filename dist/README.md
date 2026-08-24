# Built APKs

Signed release builds, kept on this `dist` branch so `main` stays source-only.

They live here rather than as GitHub release assets because this environment
cannot reach `uploads.github.com`.

## Pull one straight onto the server

```bash
curl -sL -o ~/leadtrack.nokkoo.in/app/leadtrack-1.0.0.apk \
  "https://raw.githubusercontent.com/importerbrocom/leadtrack/dist/dist/leadtrack-1.0.0.apk"
```

That is the folder `/app/` serves from, so the install page picks it up straight
away.

## Verify what you downloaded

```bash
sha256sum leadtrack-1.0.0.apk
# 830a28db1e8c46b763cb9949b9d193575f28e774c06acc96b55014995a3d842f
```

## v1.0.0

| | |
|---|---|
| Package | `com.agency.leadmanager` |
| Version | 1.0.0 (versionCode 1) |
| Server | `https://leadtrack.nokkoo.in/api/` |
| Minimum Android | 8.0 (API 26) |
| Signing | APK Signature Scheme v2 + v3 |
| Size | 1.8 MB |

Signed with `leadtrack-release.keystore`. Every future version must use the same
keystore or phones will refuse the update.

> This branch is public, as is the repository. The APK holds no credentials — the
> API base URL is a public hostname and every request needs a login. If you would
> rather it not be public, delete this branch once the file is on your server.
