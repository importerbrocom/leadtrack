# Recruitment Lead Management System

Lead management for an overseas recruitment agency: a PHP/MySQL backend that
runs on ordinary cPanel shared hosting, and an Android app that records calls
automatically.

## What it does

**The core idea:** a telecaller dials a candidate from their phone. The moment
the call ends, the app already knows the number and exactly how long they spoke,
and asks two questions — what happened, and when to call back. Two taps and the
lead is updated. Nothing is typed by hand and nothing is forgotten.

- **Automatic call capture** — number, direction and the platform-recorded
  duration, matched to the right lead even when the device reports `+91…` and the
  lead was saved as `98765…`
- **Post-call popup** — set the outcome, change the lead status, schedule the
  callback; unknown numbers can be saved as a new lead on the spot
- **Callback reminders** — notification when it is time to ring someone back
- **Lead → project conversion** — an interested candidate becomes a placement
  case with an overseas document checklist (passport, medical, PCC, visa, ticket…)
- **Forms both ways** — head office uploads blank forms, the field team
  downloads them, and uploads the filled/signed scans back for verification
- **Works offline** — captured calls, new leads and status changes are queued on
  the phone and sync when signal returns. A call is never lost.

## Who can do what

```
Admin (head office)
 └── Partner (franchise / sub-agent)      creates and manages its own telecallers
      └── Telecaller / Agent              works the leads assigned to them
```

- A **partner** sees only its own franchise's leads and its telecallers' leads,
  and can add/deactivate telecallers itself (up to a limit head office sets).
- A **telecaller** sees only the leads assigned to them.
- Commercial figures (agreed amount, payments, offered salary) are visible to
  **head office only** — they are not even sent to other roles over the API.
- Partners can be blocked from converting leads with one setting, if head office
  wants to own that step.

## Repository layout

```
backend/                     PHP 8 + MySQL. No composer, no CLI needed.
  app/
    Core/                    Router, PDO wrapper, auth/roles, validation, uploads
    Controllers/             REST endpoints
    Models/Lead.php          Lead status transitions and call-stat recalculation
    Admin/Session.php        Cookie-session auth for the web panel
  database/
    schema.sql               16 tables
    seed.sql                 Default admin, job categories, document checklist
  config/config.example.php  Copy to config.php on the server
  public_html/               <- everything in here goes to your public_html
    api/                     The REST API the mobile app talks to
    admin/                   Head-office web panel (Bootstrap 5)
  storage/                   Uploads + logs. Lives OUTSIDE public_html.
  tests/
    smoke-test.sh            88 API assertions
    admin-test.sh            50 web panel assertions

android/                     Kotlin + Jetpack Compose app
  app/src/main/java/com/agency/leadmanager/
    call/                    Call capture: receiver, log scanner, popup
    data/                    Retrofit API, Room outbox, repositories
    sync/                    WorkManager: outbox drain, catch-up scan, reminders
    ui/                      Compose screens
```

## Quick start

1. **Database** — in cPanel create a MySQL database and user, then import
   `backend/database/schema.sql` followed by `backend/database/seed.sql`
   through phpMyAdmin.
2. **Backend** — upload `backend/` above your web root, move the contents of
   `backend/public_html/` into your real `public_html/`, then copy
   `config.example.php` to `config.php` and fill in the database credentials.
3. **Sign in** to `https://yourdomain.com/admin/` with `9999999999` /
   `Admin@123` and **change that password immediately**.
4. **App** — set your API URL in `android/app/build.gradle.kts`, build the APK,
   and share it with your team.

Full step-by-step instructions, including the Google Play call-log restriction
and how to work around it, are in **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)**.

## Testing

The backend has an end-to-end test suite that walks the whole business flow —
login, hierarchy, lead creation, call sync (including duplicate-sync and `+91`
matching), status updates, form download, document upload and verification,
conversion, tenant isolation and dashboards.

```bash
cd backend
BASE_URL=http://127.0.0.1:8099/api ./tests/smoke-test.sh   # 88 assertions
BASE=http://127.0.0.1:8099/admin   ./tests/admin-test.sh   # 50 assertions
```

To run them locally you need MySQL/MariaDB plus PHP 8:

```bash
php -S 127.0.0.1:8099 -t public_html tests/dev-router.php
```

`tests/dev-router.php` reproduces what `public_html/api/.htaccess` does on
Apache, so local behaviour matches production.

## Requirements

- **Hosting:** PHP 8.0+ with `pdo_mysql`, `fileinfo` and `mbstring`; MySQL 5.7+
  or MariaDB 10.3+; Apache with `mod_rewrite` (standard on cPanel)
- **Phones:** Android 8.0 or newer
