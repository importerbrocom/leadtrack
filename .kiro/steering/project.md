---
inclusion: always
---

# Lead management system — orientation

Lead management for a Kerala overseas-recruitment agency (Income Inn Technologies).
PHP/MySQL API + admin panel on cPanel shared hosting, plus an Android app that
captures calls automatically.

`README.md` covers what the product does and the repo layout. `docs/DEPLOYMENT.md`
covers a first-time cPanel install. This file covers the things that only exist in
the running deployment and are not obvious from the code.

## Live deployment

- Site: `https://leadtrack.nokkoo.in` (API under `/api`, admin panel under `/admin`,
  APK download page under `/app`)
- Host: cPanel shared hosting, user `uddjzwrz`, PHP 8.3, MariaDB 10.6
- DNS on Cloudflare, **DNS-only (grey cloud)** — proxying breaks cPanel AutoSSL
- Let's Encrypt cert auto-renews

Server paths — note the app code sits **outside** the web root on purpose:

| Path | Holds |
| --- | --- |
| `~/leadtrack-app/{app,config,database,storage}` | application code, config, uploads |
| `~/leadtrack.nokkoo.in/` | web root: `api/`, `admin/`, `app/`, `assets/` |
| `~/leadtrack-src` | unpacked source tarball, **not** a git checkout |

`bootstrap-locator.php` finds `app/bootstrap.php` on its own: explicit
`app-path.php` first, then the repo layout, then walking up and globbing siblings.
Do not ask anyone to hand-edit `require` paths.

Deploying an update means downloading a tarball of the branch and copying
`backend/public_html/` into the web root and `backend/app/` into `~/leadtrack-app/app/`.
`config/` and `storage/` must never be overwritten.

## Roles

Admin > Partner (a franchise / sub-agent, who can create telecallers) > Telecaller.
Call tracking is a telecaller feature — leads created by an admin have no owner, so
always test capture with a telecaller account.

## Accounts on the live system

`admin` 9999999999 · `hari` (partner) 8888888888 · `Amal` (telecaller) 7777777777.
The admin password was changed by the owner; the seeded default no longer works.

## Conventions that are easy to break

- **Phone matching is on the last 10 digits.** Handles `+91…`, `0…` and spaced forms.
- **Call sync is idempotent** on `(user_id, device_call_id)` — an upsert plus
  `Lead::refreshCallStats()` recompute, so a repeated sync cannot inflate talk time.
- **History ordering is `(created_at DESC, id DESC)`.** Rows written in the same
  second came back in arbitrary order and made a test flaky.
- **The validator decides min/max by declared rule type.** `"9999999999"` is numeric,
  so `max:160` was compared as a number and broke every login. Check the rules when
  touching validation.
- **`HEAD` is treated as `GET`** in `Router::dispatch` — uptime monitors send HEAD and
  were getting 405.

## Working with the owner

Respond in English. The owner is not a developer: they run commands over SSH and
install APKs by hand, they cannot read logcat, and they have no Android tooling
unless walked through it. Give copy-pasteable commands and say what output to expect.
