---
inclusion: always
---

# Secrets, and the local test harness

## Never commit

`backend/config/config.php`, the signing keystore, `android/keystore.properties`,
and any `.apk`. All are gitignored — keep them that way. **This repository is public.**

Secrets live outside the repo and must be transferred out of band when handing the
project to another machine or account:

- signing keystore + its password — losing these means installed apps can never be
  updated again
- the database password, in `~/leadtrack-app/config/config.php` and `~/.my.cnf` on the
  server

## Outstanding security actions for the owner

- **Rotate the database password.** It was pasted into a chat transcript while the
  repository was public. Change it in cPanel, then update **both**
  `~/leadtrack-app/config/config.php` and `~/.my.cnf`.
- Decide whether the `dist` branch should stay public, since it serves the APK.
- `~/.my.cnf` exists on the server, so `mysql uddjzwrz_leadtrack` needs no password —
  convenient for support commands, and a reason to keep shell access tight.

## Running the backend tests locally

Both the API and panel suites need a database and a web server. In a sandbox where
background processes and `/tmp` are wiped between commands, **everything has to run in
a single shell invocation**:

```bash
setsid nohup /usr/libexec/mariadbd --user=mysql --datadir=/var/lib/mysql \
  --socket=/var/lib/mysql/mysql.sock --port=3306 </dev/null >/tmp/db.log 2>&1 &

php -S 127.0.0.1:8099 -t public_html tests/dev-router.php

BASE_URL=http://127.0.0.1:8099/api ./tests/smoke-test.sh   # 88 assertions
BASE=http://127.0.0.1:8099/admin ./tests/admin-test.sh     # 50 assertions
```

- Use host `127.0.0.1`, **not** `localhost` — the socket paths do not match.
- Port 8080 is usually taken; 8099 is free.
- APK upload tests need `php -d upload_max_filesize=32M -d post_max_size=40M`.
- If MariaDB is missing: `dnf install -y mariadb105-server mariadb105`.

## GitHub in this environment

`gh pr create` and other GraphQL-backed `gh pr` / `gh issue` subcommands **fail** —
the token has no scopes. Use the REST API instead:

```bash
gh api repos/{owner}/{repo}/pulls -f title=... -f body=... -f head=... -f base=main
```

Merging works the same way: `gh api -X PUT repos/{owner}/{repo}/pulls/{n}/merge`.
Push to a branch and open a PR; never commit straight to `main`.
