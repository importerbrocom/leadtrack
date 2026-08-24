#!/usr/bin/env bash
#
# Smoke test for the admin web panel: logs in with a cookie jar and checks
# every page renders without PHP errors, then exercises the main write actions.
#
# Usage:  BASE=http://127.0.0.1:8099/admin ./admin-test.sh
#
set -uo pipefail

BASE="${BASE:-http://127.0.0.1:8099/admin}"
JAR=$(mktemp /tmp/adminjar-XXXX)
PASS=0
FAIL=0

check() {
  if [[ "$2" == "$3" ]]; then
    printf '  \033[32mPASS\033[0m  %-46s %s\n' "$1" "$2"; PASS=$((PASS+1))
  else
    printf '  \033[31mFAIL\033[0m  %-46s got=%s want=%s\n' "$1" "$2" "$3"; FAIL=$((FAIL+1))
  fi
}

section() { printf '\n\033[1;36m== %s\033[0m\n' "$1"; }

# Writes a small valid PDF to $1. Real deployments only accept PDFs, images and
# Office files, so the tests must upload something the allowlist really permits.
make_pdf() {
  printf '%%PDF-1.4\n' > "$1"
  printf '1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n' >> "$1"
  printf '2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n' >> "$1"
  printf '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 300]>>endobj\n' >> "$1"
  printf '%% %s\n' "$2" >> "$1"
  printf 'trailer<</Root 1 0 R>>\n%%%%EOF\n' >> "$1"
}


# present "label" "count"   -> passes when the count is 1 or more
present() {
  if [[ "${2:-0}" -ge 1 ]]; then
    printf '  \033[32mPASS\033[0m  %-46s found (%s)\n' "$1" "$2"; PASS=$((PASS+1))
  else
    printf '  \033[31mFAIL\033[0m  %-46s not found\n' "$1"; FAIL=$((FAIL+1))
  fi
}

# Pull the CSRF token out of a page
csrf_of() {
  curl -s -b "$JAR" -c "$JAR" "$1" \
    | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"//'
}

# page PATH -> http status
page() { curl -s -o /tmp/admin_page.html -w '%{http_code}' -b "$JAR" -c "$JAR" "${BASE}/$1"; }

# Did the last fetched page contain a PHP error?
clean_page() {
  if grep -qiE 'Fatal error|Parse error|Warning</b>|Uncaught|Undefined (variable|array key|property)|Deprecated:' /tmp/admin_page.html; then
    grep -oiE '(Fatal error|Parse error|Uncaught|Undefined [a-z ]+|Deprecated)[^<]{0,140}' /tmp/admin_page.html | head -3
    echo "dirty"
  else
    echo "clean"
  fi
}

section "Login"
check "login page loads" "$(page login.php)" "200"
check "login page has no PHP errors" "$(clean_page)" "clean"

TOKEN=$(csrf_of "${BASE}/login.php")
[[ -n "$TOKEN" ]] && { printf '  \033[32mPASS\033[0m  %-46s %s…\n' "csrf token issued" "${TOKEN:0:12}"; PASS=$((PASS+1)); } \
                  || { printf '  \033[31mFAIL\033[0m  %-46s (empty)\n' "csrf token issued"; FAIL=$((FAIL+1)); }

check "protected page redirects when anonymous" \
  "$(curl -s -o /dev/null -w '%{http_code}' "${BASE}/leads.php")" "302"

check "bad password rejected" \
  "$(curl -s -b "$JAR" -c "$JAR" -d "_csrf=${TOKEN}&login=9999999999&password=wrong" "${BASE}/login.php" | grep -c 'Incorrect phone')" "1"

TOKEN=$(csrf_of "${BASE}/login.php")
check "admin login redirects to dashboard" \
  "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" \
     -d "_csrf=${TOKEN}&login=9999999999&password=Admin@123" "${BASE}/login.php")" "302"

check "csrf is enforced on login" \
  "$(curl -s -o /dev/null -w '%{http_code}' -d "login=9999999999&password=Admin@123" "${BASE}/login.php")" "419"

section "Every page renders"
for p in index.php leads.php followups.php projects.php documents.php templates.php calls.php users.php notifications.php profile.php settings.php; do
  code=$(page "$p")
  check "GET $p" "$code" "200"
  check "  ↳ no PHP errors in $p" "$(clean_page)" "clean"
done

section "Detail pages"
LEAD_ID=$(curl -s -b "$JAR" -c "$JAR" "${BASE}/leads.php" | grep -o 'lead\.php?id=[0-9]*' | head -1 | grep -o '[0-9]*$')
if [[ -n "$LEAD_ID" ]]; then
  check "GET lead.php?id=$LEAD_ID" "$(page "lead.php?id=${LEAD_ID}")" "200"
  check "  ↳ no PHP errors" "$(clean_page)" "clean"
else
  printf '  \033[33mSKIP\033[0m  no leads in database yet\n'
fi

PROJ_ID=$(curl -s -b "$JAR" -c "$JAR" "${BASE}/projects.php" | grep -o 'project\.php?id=[0-9]*' | head -1 | grep -o '[0-9]*$')
if [[ -n "$PROJ_ID" ]]; then
  check "GET project.php?id=$PROJ_ID" "$(page "project.php?id=${PROJ_ID}")" "200"
  check "  ↳ no PHP errors" "$(clean_page)" "clean"
else
  printf '  \033[33mSKIP\033[0m  no projects in database yet\n'
fi

check "unknown lead id redirects" "$(page 'lead.php?id=999999')" "302"

section "Write actions"
STAMP=$(date +%s)
TOKEN=$(csrf_of "${BASE}/users.php")

check "create partner" \
  "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" \
     --data-urlencode "_csrf=${TOKEN}" \
     --data-urlencode "action=create" \
     --data-urlencode "role=partner" \
     --data-urlencode "name=Web Panel Partner" \
     --data-urlencode "phone=8${STAMP:1:9}" \
     --data-urlencode "password=Panel@123" \
     --data-urlencode "agency_name=Web Test Agency" \
     "${BASE}/users.php")" "302"

present "partner appears in list" \
  "$(curl -s -b "$JAR" -c "$JAR" "${BASE}/users.php" | grep -c 'Web Panel Partner')"

TOKEN=$(csrf_of "${BASE}/leads.php")
check "create lead" \
  "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" \
     --data-urlencode "_csrf=${TOKEN}" \
     --data-urlencode "action=create" \
     --data-urlencode "name=Panel Test Lead" \
     --data-urlencode "phone=9${STAMP:1:9}" \
     --data-urlencode "city=Kozhikode" \
     --data-urlencode "priority=high" \
     "${BASE}/leads.php")" "302"

present "lead appears in list" \
  "$(curl -s -b "$JAR" -c "$JAR" "${BASE}/leads.php?search=Panel+Test+Lead" | grep -c 'Panel Test Lead')"

# duplicate phone should be refused
TOKEN=$(csrf_of "${BASE}/leads.php")
curl -s -o /dev/null -b "$JAR" -c "$JAR" \
  --data-urlencode "_csrf=${TOKEN}" --data-urlencode "action=create" \
  --data-urlencode "name=Dup Lead" --data-urlencode "phone=9${STAMP:1:9}" "${BASE}/leads.php"
present "duplicate lead phone refused" \
  "$(curl -s -b "$JAR" -c "$JAR" "${BASE}/leads.php" | grep -c 'already saved as lead')"

# form template upload + download
TPL=$(mktemp /tmp/panel-form-XXXX.pdf)
make_pdf "$TPL" "PANEL UPLOADED APPLICATION FORM"
TOKEN=$(csrf_of "${BASE}/templates.php")
check "upload form template" \
  "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" \
     -F "_csrf=${TOKEN}" -F "action=upload" -F "file=@${TPL}" \
     -F "title=Panel Uploaded Form" -F "category=application" "${BASE}/templates.php")" "302"

# Take the highest id, i.e. the template we just uploaded (the list is sorted
# by category/title, so "first link on the page" is not ours).
TPL_ID=$(curl -s -b "$JAR" -c "$JAR" "${BASE}/templates.php" \
  | grep -o 'type=template&id=[0-9]*' | grep -o '[0-9]*$' | sort -n | tail -1)
DL=$(mktemp /tmp/panel-dl-XXXX.pdf)
check "download the template back" \
  "$(curl -s -o "$DL" -w '%{http_code}' -b "$JAR" -c "$JAR" "${BASE}/download.php?type=template&id=${TPL_ID}")" "200"
check "downloaded bytes match" "$(diff -q "$TPL" "$DL" >/dev/null && echo same || echo differs)" "same"

# settings
TOKEN=$(csrf_of "${BASE}/settings.php")
check "save settings" \
  "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" \
     --data-urlencode "_csrf=${TOKEN}" --data-urlencode "action=general" \
     --data-urlencode "agency_name=Kerala Overseas Manpower" \
     --data-urlencode "project_code_prefix=KOM" \
     --data-urlencode "max_upload_mb=20" \
     --data-urlencode "followup_reminder_minutes=30" \
     --data-urlencode "partner_can_convert=1" \
     "${BASE}/settings.php")" "302"
present "new agency name shows in header" \
  "$(curl -s -b "$JAR" -c "$JAR" "${BASE}/index.php" | grep -c 'Kerala Overseas Manpower')"

section "Partner login sees a restricted panel"
PJAR=$(mktemp /tmp/pjar-XXXX)
PTOKEN=$(curl -s -b "$PJAR" -c "$PJAR" "${BASE}/login.php" | grep -o 'name="_csrf" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"//')
curl -s -o /dev/null -b "$PJAR" -c "$PJAR" \
  -d "_csrf=${PTOKEN}&login=8${STAMP:1:9}&password=Panel@123" "${BASE}/login.php"

check "partner reaches dashboard" \
  "$(curl -s -o /dev/null -w '%{http_code}' -b "$PJAR" -c "$PJAR" "${BASE}/index.php")" "200"
check "partner blocked from settings" \
  "$(curl -s -o /dev/null -w '%{http_code}' -b "$PJAR" -c "$PJAR" "${BASE}/settings.php")" "403"
check "partner sees no other partner's leads" \
  "$(curl -s -b "$PJAR" -c "$PJAR" "${BASE}/leads.php" | grep -c 'Panel Test Lead')" "0"
check "partner cannot create a partner" \
  "$(curl -s -b "$PJAR" -c "$PJAR" "${BASE}/users.php" | grep -c 'Partner (franchise)')" "0"

section "Logout"
check "logout redirects" "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" "${BASE}/logout.php")" "302"
check "session is dead" "$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" "${BASE}/leads.php")" "302"

rm -f "$JAR" "$PJAR" "$TPL" "$DL" /tmp/admin_page.html

printf '\n\033[1m──────────────────────────────────────────\033[0m\n'
printf '  \033[32mPassed: %d\033[0m    \033[31mFailed: %d\033[0m\n' "$PASS" "$FAIL"
printf '\033[1m──────────────────────────────────────────\033[0m\n'

[[ "$FAIL" -eq 0 ]]
