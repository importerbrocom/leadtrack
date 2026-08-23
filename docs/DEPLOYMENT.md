# Deployment guide

Two things to set up: the backend on your cPanel hosting, and the Android APK
for your team.

---

## Part 1 — Backend on cPanel

### 1. Create the database

cPanel → **MySQL Databases**

1. Create a database, e.g. `leadmgr`. cPanel will prefix it, so the real name
   becomes something like `youracct_leadmgr`.
2. Create a user, e.g. `leadmgr`, with a strong password. The real name becomes
   `youracct_leadmgr`.
3. Add the user to the database with **ALL PRIVILEGES**.
4. Write down all three values — you need them in step 3.

### 2. Import the tables

cPanel → **phpMyAdmin** → select your database → **Import**

1. Import `backend/database/schema.sql` (creates 16 tables).
2. Import `backend/database/seed.sql` (creates the admin login, job categories
   and the overseas document checklist).

If phpMyAdmin refuses because the file is too large, import via cPanel's
Terminal instead:

```bash
mysql -u youracct_leadmgr -p youracct_leadmgr < schema.sql
```

### 3. Upload the files

The layout matters: application code and uploaded documents must sit **outside**
`public_html`, so nobody can reach a passport scan by guessing a URL.

Upload so your account looks like this:

```
/home/youracct/
├── leadmgr/                  <- from backend/ (NOT web-accessible)
│   ├── app/
│   ├── config/
│   ├── database/
│   ├── storage/
│   └── tests/
└── public_html/
    ├── api/                  <- from backend/public_html/api/
    └── admin/                <- from backend/public_html/admin/
```

Then fix the two include paths. The shipped files assume `public_html` is a
sibling of `app/`; because you moved `api/` and `admin/` into the real
`public_html`, tell them where the code lives:

- In `public_html/api/index.php`, change:

  ```php
  require dirname(__DIR__, 2) . '/app/bootstrap.php';
  ```

  to

  ```php
  require '/home/youracct/leadmgr/app/bootstrap.php';
  ```

- In `public_html/admin/_init.php`, change:

  ```php
  require dirname(__DIR__, 2) . '/app/bootstrap.php';
  ```

  to

  ```php
  require '/home/youracct/leadmgr/app/bootstrap.php';
  ```

> Prefer not to edit anything? Then upload `backend/` as-is (including its own
> `public_html`) and point your domain's document root at
> `/home/youracct/leadmgr/public_html` in cPanel → **Domains**. Both approaches
> work; this one needs no code changes.

### 4. Configure

Copy `config/config.example.php` to `config/config.php` and fill it in:

```php
'app' => [
    'base_url' => 'https://yourdomain.com/api',   // no trailing slash
    'debug'    => false,                          // keep false in production
    'timezone' => 'Asia/Kolkata',
],
'db' => [
    'host'     => 'localhost',
    'database' => 'youracct_leadmgr',
    'username' => 'youracct_leadmgr',
    'password' => 'the password you created',
],
```

### 5. Permissions

```
storage/              755
storage/uploads/      755
storage/logs/         755
config/config.php     600
```

### 6. Get an SSL certificate

cPanel → **SSL/TLS Status** → **Run AutoSSL**. It is free and takes a minute.

The app refuses plain HTTP by default, because candidate data and passport
scans travel over this connection. If you genuinely cannot get SSL yet, see the
commented-out block in
`android/app/src/main/res/xml/network_security_config.xml` — but treat that as
temporary.

### 7. Check it works

Open `https://yourdomain.com/api/health` — you should see:

```json
{"success":true,"message":null,"data":{"database":"connected"}}
```

If you get a 500, check `storage/logs/php-error.log`. If you get a 404, your
host may not have `mod_rewrite`; in that case the API also answers at
`https://yourdomain.com/api/index.php?_route=/health`, and you can set the app's
server URL to `https://yourdomain.com/api/index.php?_route=`.

### 8. First sign-in

Go to `https://yourdomain.com/admin/`

- Phone: `9999999999`
- Password: `Admin@123`

**Change this password immediately** (top-right menu → Change password).

Then:
1. **Settings** — set your agency name, project code prefix and whether partners
   may convert leads.
2. **Partners & Team** — add your franchises. Each partner then adds their own
   telecallers, either from the panel or from the app.

---

## Part 2 — The Android app

### 1. Point the app at your server

In `android/app/build.gradle.kts`:

```kotlin
buildConfigField("String", "DEFAULT_API_BASE_URL", "\"https://yourdomain.com/api/\"")
```

Keep the trailing slash. (Users can also change the server from the login
screen, but setting it here means nobody has to.)

### 2. Build the APK

**With Android Studio** — open the `android/` folder, wait for the Gradle sync,
then **Build → Build App Bundle(s) / APK(s) → Build APK(s)**.

**From the command line:**

```bash
cd android
echo "sdk.dir=/path/to/your/Android/sdk" > local.properties
./gradlew assembleRelease
```

The APK lands in `app/build/outputs/apk/release/`.

### 3. Sign it for real distribution

The release build is unsigned. Create a keystore once:

```bash
keytool -genkey -v -keystore leadmanager.keystore \
        -alias leadmanager -keyalg RSA -keysize 2048 -validity 10000
```

Keep that file and its password safe — you need the same key to ship updates.
Then add to `android/app/build.gradle.kts`:

```kotlin
android {
    signingConfigs {
        create("release") {
            storeFile = file("/absolute/path/leadmanager.keystore")
            storePassword = "…"
            keyAlias = "leadmanager"
            keyPassword = "…"
        }
    }
    buildTypes {
        getByName("release") {
            signingConfig = signingConfigs.getByName("release")
        }
    }
}
```

### 4. Get it onto your team's phones

⚠️ **Read this before planning a Play Store release.**

The app needs `READ_CALL_LOG` to know how long each call lasted. Google Play
treats that as a restricted permission and normally only allows it for apps that
are the user's **default phone or dialler app**. A lead-management app will not
be approved for it.

Your practical options:

| Option | Works? | Notes |
|---|---|---|
| **Share the APK directly** (WhatsApp, Drive, your website) | ✅ Recommended | No restrictions at all. Staff enable "Install unknown apps" once. |
| **Managed Google Play private app** | ✅ | Needs Google Workspace + managed devices. Good for larger teams. |
| **Public Play Store listing** | ❌ for auto-capture | You would have to drop call-log access and have telecallers type the duration by hand. |

For a recruitment agency's own staff, sharing the APK directly is the normal and
correct answer.

### 5. First run on a phone

The app asks for four permissions. All of them matter:

| Permission | Why |
|---|---|
| Phone state | Detect when a call starts and ends |
| Call log | Read the number and the real duration |
| Make calls | Dial a lead from inside the app |
| Notifications | Callback reminders |

**Then turn off battery optimisation for the app.** This is the single most
common reason call tracking "stops working": aggressive OEM battery managers
(Xiaomi, Oppo, Vivo, Realme, Samsung) kill background services.

Settings → Apps → Lead Manager → Battery → **Unrestricted / Don't optimise**.

Even if the phone does kill the service, nothing is permanently lost: a
catch-up scan runs every 15 minutes and backfills any calls that were missed.

---

## How the automatic capture actually works

Worth understanding, because it explains the app's behaviour:

1. `CallStateReceiver` sees the phone go off-hook and then idle — a call ended.
2. It starts `CallCaptureService`, a short foreground service. A broadcast
   receiver alone would not survive long enough for the next step.
3. The service **waits and polls the call log**, because Android writes that row
   asynchronously. We use the platform's duration rather than our own stopwatch:
   ours would include ringing time and would be wrong if the process were killed
   mid-call.
4. The number's last 10 digits are matched against the leads, so `+91 98765
   43210`, `098765 43210` and `9876543210` all resolve to the same person.
5. The call is written to a local Room queue **before** any network call.
6. The popup appears for the outcome and callback time.
7. `SyncWorker` pushes the queue. Each row carries Android's own call-log id, so
   the server upserts on `(user_id, device_call_id)` — syncing twice cannot
   double-count a call or inflate anyone's talk time.

---

## Routine maintenance

- **Backups:** cPanel → Backup Wizard. Back up both the database and
  `storage/uploads/` (that is where every passport scan and signed form lives).
- **Logs:** `storage/logs/php-error.log`. Safe to delete when large.
- **Upload limit:** raise `max_upload_mb` in Settings, but it can never exceed
  your host's own `upload_max_filesize` / `post_max_size` (cPanel → MultiPHP INI
  Editor).
- **A telecaller leaves:** deactivate them in the panel. Their sessions are
  revoked immediately and their leads stay with the franchise.
