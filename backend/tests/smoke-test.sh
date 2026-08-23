#!/usr/bin/env bash
#
# End-to-end smoke test for the Lead Management API.
#
# Walks the whole business flow against a running API:
#   admin login -> create partner -> partner creates telecaller ->
#   telecaller adds a lead -> automatic call sync -> status + callback ->
#   form template download -> document upload -> convert to project ->
#   admin verifies document -> dashboards -> permission checks
#
# Usage:  BASE_URL=http://127.0.0.1:8080/api ./smoke-test.sh
#
set -uo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8080/api}"
PASS=0
FAIL=0

# ---------------------------------------------------------------- helpers
jsonget() { python3 -c "
import json,sys
try:
    d=json.load(sys.stdin)
except Exception:
    print(''); sys.exit(0)
for k in sys.argv[1].split('.'):
    if d is None: break
    if isinstance(d,list):
        try: d=d[int(k)]
        except Exception: d=None
    else: d=d.get(k) if isinstance(d,dict) else None
if d is None: print('')
elif isinstance(d,bool): print('true' if d else 'false')
elif isinstance(d,(dict,list)): print(json.dumps(d))
else: print(d)
" "$1"; }

# call METHOD PATH [JSON_BODY] [TOKEN]
call() {
  local method="$1" path="$2" body="${3:-}" token="${4:-}"
  local args=(-s -X "$method" "${BASE_URL}${path}" -H 'Accept: application/json')
  [[ -n "$token" ]] && args+=(-H "Authorization: Bearer ${token}")
  [[ -n "$body"  ]] && args+=(-H 'Content-Type: application/json' -d "$body")
  curl "${args[@]}"
}

# status METHOD PATH [BODY] [TOKEN] -> prints http code
status() {
  local method="$1" path="$2" body="${3:-}" token="${4:-}"
  local args=(-s -o /dev/null -w '%{http_code}' -X "$method" "${BASE_URL}${path}")
  [[ -n "$token" ]] && args+=(-H "Authorization: Bearer ${token}")
  [[ -n "$body"  ]] && args+=(-H 'Content-Type: application/json' -d "$body")
  curl "${args[@]}"
}

check() { # check "label" "actual" "expected"
  if [[ "$2" == "$3" ]]; then
    printf '  \033[32mPASS\033[0m  %-52s %s\n' "$1" "$2"; PASS=$((PASS+1))
  else
    printf '  \033[31mFAIL\033[0m  %-52s got=%s want=%s\n' "$1" "$2" "$3"; FAIL=$((FAIL+1))
  fi
}

checkne() { # non-empty
  if [[ -n "$2" && "$2" != "null" ]]; then
    printf '  \033[32mPASS\033[0m  %-52s %s\n' "$1" "$2"; PASS=$((PASS+1))
  else
    printf '  \033[31mFAIL\033[0m  %-52s (empty)\n' "$1"; FAIL=$((FAIL+1))
  fi
}

section() { printf '\n\033[1;36m== %s\033[0m\n' "$1"; }

STAMP=$(date +%s)

# ---------------------------------------------------------------- 1. health
section "Health & auth"
check "GET /health" "$(status GET /health)" "200"

ADMIN_TOKEN=$(call POST /auth/login '{"login":"9999999999","password":"Admin@123","device_id":"test-admin"}' | jsonget data.token)
checkne "admin login returns token" "$ADMIN_TOKEN"

check "wrong password rejected"   "$(status POST /auth/login '{"login":"9999999999","password":"nope"}')" "401"
check "no token rejected"         "$(status GET /leads)" "401"
ADMIN_ROLE=$(call GET /auth/me '' "$ADMIN_TOKEN" | jsonget data.user.role)
check "GET /auth/me role"         "$ADMIN_ROLE" "admin"

# ---------------------------------------------------------------- 2. users
section "User hierarchy: admin -> partner -> telecaller"
PARTNER_PHONE="90000${STAMP:5:5}"
PARTNER_ID=$(call POST /users "{\"role\":\"partner\",\"name\":\"Kochi Franchise\",\"phone\":\"$PARTNER_PHONE\",\"password\":\"Partner@123\",\"agency_name\":\"Kochi Manpower\",\"city\":\"Kochi\",\"max_telecallers\":5}" "$ADMIN_TOKEN" | jsonget data.id)
checkne "admin created partner" "$PARTNER_ID"

check "duplicate phone rejected" "$(status POST /users "{\"role\":\"partner\",\"name\":\"Dup\",\"phone\":\"$PARTNER_PHONE\",\"password\":\"Partner@123\"}" "$ADMIN_TOKEN")" "409"

PARTNER_TOKEN=$(call POST /auth/login "{\"login\":\"$PARTNER_PHONE\",\"password\":\"Partner@123\",\"device_id\":\"test-partner\"}" | jsonget data.token)
checkne "partner login" "$PARTNER_TOKEN"

TC_PHONE="80000${STAMP:5:5}"
TC_ID=$(call POST /users "{\"role\":\"telecaller\",\"name\":\"Anu Telecaller\",\"phone\":\"$TC_PHONE\",\"password\":\"Tele@123\"}" "$PARTNER_TOKEN" | jsonget data.id)
checkne "partner created own telecaller" "$TC_ID"

check "partner cannot create partner" "$(status POST /users "{\"role\":\"partner\",\"name\":\"X\",\"phone\":\"7000012345\",\"password\":\"Xx@12345\"}" "$PARTNER_TOKEN")" "403"

TC_PARENT=$(call GET "/users/$TC_ID" '' "$PARTNER_TOKEN" | jsonget data.parent_id)
check "telecaller parent = partner" "$TC_PARENT" "$PARTNER_ID"

TC_TOKEN=$(call POST /auth/login "{\"login\":\"$TC_PHONE\",\"password\":\"Tele@123\",\"device_id\":\"test-tc\"}" | jsonget data.token)
checkne "telecaller login" "$TC_TOKEN"

check "telecaller cannot list users" "$(status GET /users '' "$TC_TOKEN")" "403"

# ---------------------------------------------------------------- 3. leads
section "Leads"
LEAD_PHONE="9876${STAMP:4:6}"
LEAD_ID=$(call POST /leads "{\"name\":\"Rajesh Kumar\",\"phone\":\"$LEAD_PHONE\",\"city\":\"Aluva\",\"preferred_country\":\"UAE\",\"qualification\":\"ITI Electrician\",\"priority\":\"high\"}" "$TC_TOKEN" | jsonget data.id)
checkne "telecaller created lead" "$LEAD_ID"

LEAD_PARTNER=$(call GET "/leads/$LEAD_ID" '' "$TC_TOKEN" | jsonget data.partner_id)
check "lead inherits partner ownership" "$LEAD_PARTNER" "$PARTNER_ID"

LEAD_ASSIGNED=$(call GET "/leads/$LEAD_ID" '' "$TC_TOKEN" | jsonget data.assigned_to)
check "lead auto-assigned to creator" "$LEAD_ASSIGNED" "$TC_ID"

check "duplicate lead phone rejected" "$(status POST /leads "{\"name\":\"Dup\",\"phone\":\"$LEAD_PHONE\"}" "$TC_TOKEN")" "409"
check "lead requires valid phone"     "$(status POST /leads '{"name":"Bad","phone":"123"}' "$TC_TOKEN")" "422"

PARTNER_SEES=$(call GET "/leads/$LEAD_ID" '' "$PARTNER_TOKEN" | jsonget data.id)
check "partner sees telecaller's lead" "$PARTNER_SEES" "$LEAD_ID"

# ---------------------------------------------------------------- 4. automatic call capture
section "Automatic call capture"
CALL_START=$(date -d '-10 minutes' '+%Y-%m-%d %H:%M:%S')

# The device reports +91 prefixed; the lead was saved without it.
SYNC=$(call POST /calls/sync "{\"calls\":[{\"device_call_id\":\"dev-1-$STAMP\",\"phone_number\":\"+91$LEAD_PHONE\",\"direction\":\"outgoing\",\"started_at\":\"$CALL_START\",\"duration_sec\":143,\"sim_slot\":1}]}" "$TC_TOKEN")
check "call synced"                    "$(echo "$SYNC" | jsonget data.synced)" "1"
check "call matched lead despite +91"  "$(echo "$SYNC" | jsonget data.results.0.matched)" "true"
check "matched lead id"                "$(echo "$SYNC" | jsonget data.results.0.lead_id)" "$LEAD_ID"

LEAD_JSON=$(call GET "/leads/$LEAD_ID" '' "$TC_TOKEN")
check "duration recorded on lead"      "$(echo "$LEAD_JSON" | jsonget data.total_talk_time_sec)" "143"
check "call_count = 1"                 "$(echo "$LEAD_JSON" | jsonget data.call_count)" "1"
check "talk time humanised"            "$(echo "$LEAD_JSON" | jsonget data.talk_time_display)" "2m 23s"
check "status auto-moved to contacted" "$(echo "$LEAD_JSON" | jsonget data.status)" "contacted"
checkne "last_contacted_at set"        "$(echo "$LEAD_JSON" | jsonget data.last_contacted_at)"

# Re-syncing the same device call must not double-count.
call POST /calls/sync "{\"calls\":[{\"device_call_id\":\"dev-1-$STAMP\",\"phone_number\":\"+91$LEAD_PHONE\",\"direction\":\"outgoing\",\"started_at\":\"$CALL_START\",\"duration_sec\":143}]}" "$TC_TOKEN" > /dev/null
LEAD_JSON=$(call GET "/leads/$LEAD_ID" '' "$TC_TOKEN")
check "re-sync is idempotent (calls)"  "$(echo "$LEAD_JSON" | jsonget data.call_count)" "1"
check "re-sync is idempotent (secs)"   "$(echo "$LEAD_JSON" | jsonget data.total_talk_time_sec)" "143"

# Unknown number -> no match, so the app offers "save as new lead".
UNKNOWN=$(call POST /calls/sync "{\"calls\":[{\"device_call_id\":\"dev-2-$STAMP\",\"phone_number\":\"9111100000\",\"direction\":\"incoming\",\"started_at\":\"$CALL_START\",\"duration_sec\":30}]}" "$TC_TOKEN")
check "unknown number is unmatched"    "$(echo "$UNKNOWN" | jsonget data.results.0.matched)" "false"

# Lookup endpoint the app calls the moment a call ends
LOOKUP=$(call GET "/leads/lookup?phone=%2B91$LEAD_PHONE" '' "$TC_TOKEN")
check "lookup finds lead by phone"     "$(echo "$LOOKUP" | jsonget data.found)" "true"
check "lookup returns lead name"       "$(echo "$LOOKUP" | jsonget data.lead.name)" "Rajesh Kumar"
check "lookup misses unknown number"   "$(call GET '/leads/lookup?phone=9000000001' '' "$TC_TOKEN" | jsonget data.found)" "false"

# ---------------------------------------------------------------- 5. status + callback
section "Status update & scheduled callback"
NEXT_CALL=$(date -d '+1 day' '+%Y-%m-%d 10:30:00')
ST=$(call POST "/leads/$LEAD_ID/status" "{\"status\":\"interested\",\"remarks\":\"Wants UAE electrician job\",\"next_follow_up_at\":\"$NEXT_CALL\"}" "$TC_TOKEN")
check "status -> interested"           "$(echo "$ST" | jsonget data.status)" "interested"
check "next callback stored"           "$(echo "$ST" | jsonget data.next_follow_up_at)" "$NEXT_CALL"

HIST=$(call GET "/leads/$LEAD_ID" '' "$TC_TOKEN" | jsonget data.status_history.0.to_status)
check "status history written"          "$HIST" "interested"

check "invalid status rejected"        "$(status POST "/leads/$LEAD_ID/status" '{"status":"banana"}' "$TC_TOKEN")" "422"
check "convert blocked via status api" "$(status POST "/leads/$LEAD_ID/status" '{"status":"converted"}' "$TC_TOKEN")" "400"

FU_TOTAL=$(call GET '/follow-ups?bucket=upcoming' '' "$TC_TOKEN" | jsonget meta.total)
checkne "callback appears in follow-ups" "$FU_TOTAL"

# ---------------------------------------------------------------- 6. form templates
section "Form templates (admin uploads, field team downloads)"
TPL_FILE=$(mktemp /tmp/application-form-XXXX.txt)
echo "CANDIDATE APPLICATION FORM - name, passport, job preference" > "$TPL_FILE"

TPL_ID=$(curl -s -X POST "${BASE_URL}/form-templates" \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -F "file=@${TPL_FILE}" \
  -F "title=Candidate Application Form" \
  -F "category=application" \
  -F "version=2.0" | jsonget data.id)
checkne "admin uploaded template" "$TPL_ID"

check "telecaller cannot upload template" \
  "$(curl -s -o /dev/null -w '%{http_code}' -X POST "${BASE_URL}/form-templates" -H "Authorization: Bearer $TC_TOKEN" -F "file=@${TPL_FILE}" -F "title=Nope")" "403"

DL=$(mktemp /tmp/downloaded-XXXX.txt)
DL_CODE=$(curl -s -o "$DL" -w '%{http_code}' "${BASE_URL}/form-templates/${TPL_ID}/download" -H "Authorization: Bearer $TC_TOKEN")
check "telecaller downloaded template" "$DL_CODE" "200"
check "downloaded content matches"     "$(diff -q "$TPL_FILE" "$DL" >/dev/null && echo same || echo differs)" "same"

# ---------------------------------------------------------------- 7. documents
section "Documents (field team uploads, admin verifies)"
DOC_FILE=$(mktemp /tmp/filled-form-XXXX.txt)
echo "FILLED FORM: Rajesh Kumar / Passport M1234567 / Electrician" > "$DOC_FILE"

APP_FORM_TYPE=$(call GET /document-types '' "$TC_TOKEN" | python3 -c "
import json,sys
for t in json.load(sys.stdin)['data']:
    if t['code']=='APPLICATION_FORM': print(t['id']); break
")
checkne "found APPLICATION_FORM type" "$APP_FORM_TYPE"

DOC_ID=$(curl -s -X POST "${BASE_URL}/documents" \
  -H "Authorization: Bearer $TC_TOKEN" \
  -F "file=@${DOC_FILE}" \
  -F "lead_id=${LEAD_ID}" \
  -F "document_type_id=${APP_FORM_TYPE}" \
  -F "title=Filled application form" | jsonget data.id)
checkne "telecaller uploaded document" "$DOC_ID"

DOC_STATUS=$(call GET "/documents?lead_id=$LEAD_ID" '' "$TC_TOKEN" | jsonget data.0.verification_status)
check "document starts pending" "$DOC_STATUS" "pending"

ADMIN_DL=$(mktemp /tmp/admin-doc-XXXX.txt)
ADMIN_DL_CODE=$(curl -s -o "$ADMIN_DL" -w '%{http_code}' "${BASE_URL}/documents/${DOC_ID}/download" -H "Authorization: Bearer $ADMIN_TOKEN")
check "admin downloaded document"  "$ADMIN_DL_CODE" "200"
check "admin got the right bytes"  "$(diff -q "$DOC_FILE" "$ADMIN_DL" >/dev/null && echo same || echo differs)" "same"

check "telecaller cannot verify"   "$(status POST "/documents/$DOC_ID/verify" '{"verification_status":"verified"}' "$TC_TOKEN")" "403"
check "reject needs a reason"      "$(status POST "/documents/$DOC_ID/verify" '{"verification_status":"rejected"}' "$ADMIN_TOKEN")" "422"

VERIFIED=$(call POST "/documents/$DOC_ID/verify" '{"verification_status":"verified"}' "$ADMIN_TOKEN" | jsonget data.verification_status)
check "admin verified document" "$VERIFIED" "verified"

# ---------------------------------------------------------------- 8. convert to project
section "Convert lead -> project"
CONV=$(call POST "/leads/$LEAD_ID/convert" '{"position":"Electrician","employer_name":"Al Futtaim LLC","destination_country":"UAE","passport_no":"M1234567","agreed_amount":85000,"paid_amount":25000}' "$PARTNER_TOKEN")
PROJECT_ID=$(echo "$CONV" | jsonget data.id)
checkne "lead converted to project" "$PROJECT_ID"
checkne "project code generated"    "$(echo "$CONV" | jsonget data.project_code)"
check  "project candidate name"     "$(echo "$CONV" | jsonget data.candidate_name)" "Rajesh Kumar"
check  "money hidden from partner"  "$(echo "$CONV" | jsonget data.agreed_amount)" ""

LEAD_AFTER=$(call GET "/leads/$LEAD_ID" '' "$TC_TOKEN")
check "lead status = converted"     "$(echo "$LEAD_AFTER" | jsonget data.status)" "converted"
check "lead links to project"       "$(echo "$LEAD_AFTER" | jsonget data.project_id)" "$PROJECT_ID"
check "callback cleared on convert" "$(echo "$LEAD_AFTER" | jsonget data.next_follow_up_at)" ""

check "double convert rejected"     "$(status POST "/leads/$LEAD_ID/convert" '{}' "$PARTNER_TOKEN")" "409"
check "converted lead cannot revert" "$(status POST "/leads/$LEAD_ID/status" '{"status":"interested"}' "$TC_TOKEN")" "409"

PROJ=$(call GET "/projects/$PROJECT_ID" '' "$ADMIN_TOKEN")
check "lead-stage doc carried over" "$(echo "$PROJ" | jsonget data.documents.0.id)" "$DOC_ID"
checkne "checklist built"           "$(echo "$PROJ" | jsonget data.checklist.0.name)"
checkne "document progress present" "$(echo "$PROJ" | jsonget data.document_progress.required)"
check "admin sees agreed amount"    "$(echo "$PROJ" | jsonget data.agreed_amount)" "85000"
check "admin sees balance"          "$(echo "$PROJ" | jsonget data.balance_amount)" "60000"

# project status progression
PS=$(call POST "/projects/$PROJECT_ID/status" '{"status":"medical_pending","remarks":"Medical booked at Aster"}' "$PARTNER_TOKEN" | jsonget data.status)
check "partner advanced project"    "$PS" "medical_pending"
check "partner cannot mark deployed" "$(status POST "/projects/$PROJECT_ID/status" '{"status":"deployed"}' "$PARTNER_TOKEN")" "403"
check "admin can mark deployed"      "$(status POST "/projects/$PROJECT_ID/status" '{"status":"deployed"}' "$ADMIN_TOKEN")" "200"

# ---------------------------------------------------------------- 9. isolation between partners
section "Tenant isolation"
OTHER_PHONE="91000${STAMP:5:5}"
OTHER_ID=$(call POST /users "{\"role\":\"partner\",\"name\":\"Kollam Franchise\",\"phone\":\"$OTHER_PHONE\",\"password\":\"Other@123\"}" "$ADMIN_TOKEN" | jsonget data.id)
OTHER_TOKEN=$(call POST /auth/login "{\"login\":\"$OTHER_PHONE\",\"password\":\"Other@123\"}" | jsonget data.token)
checkne "second partner created" "$OTHER_ID"

check "other partner cannot read lead"    "$(status GET "/leads/$LEAD_ID" '' "$OTHER_TOKEN")" "403"
check "other partner cannot read project" "$(status GET "/projects/$PROJECT_ID" '' "$OTHER_TOKEN")" "403"
check "other partner lead list empty"     "$(call GET /leads '' "$OTHER_TOKEN" | jsonget meta.total)" "0"
check "other partner lookup finds nothing" "$(call GET "/leads/lookup?phone=$LEAD_PHONE" '' "$OTHER_TOKEN" | jsonget data.found)" "false"

# ---------------------------------------------------------------- 10. dashboard & reports
section "Dashboard, stats & lookups"
DASH=$(call GET /dashboard '' "$ADMIN_TOKEN")
checkne "dashboard total leads"     "$(echo "$DASH" | jsonget data.leads.total)"
check  "dashboard converted count"  "$(echo "$DASH" | jsonget data.leads.converted)" "1"
checkne "dashboard partners count"  "$(echo "$DASH" | jsonget data.team.partners)"
checkne "conversion rate present"   "$(echo "$DASH" | jsonget data.conversion_rate)"

TC_DASH=$(call GET /dashboard '' "$TC_TOKEN")
check "telecaller sees only own leads" "$(echo "$TC_DASH" | jsonget data.leads.total)" "1"

STATS=$(call GET "/calls/stats?from=$(date -d '-1 day' '+%Y-%m-%d')&to=$(date -d '+1 day' '+%Y-%m-%d')" '' "$TC_TOKEN")
check "call stats total"      "$(echo "$STATS" | jsonget data.total_calls)" "2"
check "call stats talk time"  "$(echo "$STATS" | jsonget data.total_seconds)" "173"

LOOKUPS=$(call GET /lookups '' "$TC_TOKEN")
checkne "lookups: job categories" "$(echo "$LOOKUPS" | jsonget data.job_categories.0.name)"
checkne "lookups: lead sources"   "$(echo "$LOOKUPS" | jsonget data.lead_sources.0.name)"
checkne "lookups: doc types"      "$(echo "$LOOKUPS" | jsonget data.document_types.0.name)"

NOTIF=$(call GET /notifications '' "$ADMIN_TOKEN" | jsonget meta.total)
checkne "admin got notifications" "$NOTIF"

# ---------------------------------------------------------------- 11. misc
section "Misc"
check "404 for unknown route"  "$(status GET /nope '' "$ADMIN_TOKEN")" "404"
check "405 for wrong verb"     "$(status DELETE /dashboard '' "$ADMIN_TOKEN")" "405"
check "import creates leads"   "$(call POST /leads/import "{\"leads\":[{\"name\":\"Bulk One\",\"phone\":\"9700000001\"},{\"name\":\"Bulk Two\",\"phone\":\"9700000002\"}],\"assigned_to\":$TC_ID}" "$PARTNER_TOKEN" | jsonget data.created)" "2"
check "logout revokes token"   "$(call POST /auth/logout '' "$OTHER_TOKEN" > /dev/null; status GET /leads '' "$OTHER_TOKEN")" "401"

rm -f "$TPL_FILE" "$DOC_FILE" "$DL" "$ADMIN_DL"

# ---------------------------------------------------------------- summary
printf '\n\033[1m──────────────────────────────────────────\033[0m\n'
printf '  \033[32mPassed: %d\033[0m    \033[31mFailed: %d\033[0m\n' "$PASS" "$FAIL"
printf '\033[1m──────────────────────────────────────────\033[0m\n'

[[ "$FAIL" -eq 0 ]]
