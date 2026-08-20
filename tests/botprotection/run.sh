#!/usr/bin/env bash
#
# Bot-protection regression harness.
#
# Runs curl scenarios against a running ynfinite-client instance and asserts
# BOTH the HTTP response and (when the log dir is reachable) the reason line
# written to tmp/bot_protection_logs/<today>.yn-botprotection.log.
#
# Usage:
#   BASE_URL=http://localhost:8080 ./tests/botprotection/run.sh
#
# Env:
#   BASE_URL   base URL of the running client         (default http://localhost:8080)
#   PAGE_PATH  a page that renders at least one form  (default /)
#   FORM_ID    formId to submit                       (default test-form)
#   LOG_DIR    local path to the bot protection logs  (default tmp/bot_protection_logs)
#              set LOG_DIR= (empty) to skip log assertions
#   RUN_HEAVY=1  also run the 21-request reject-limiter scenario
#   HOST_HEADER  override the Host header (e.g. 'localhost' to dodge the
#                .htaccess https redirect when testing on a non-80 port)
#
# Notes:
#   - The happy-path scenario asserts the 'allowed' log line, which PHP writes
#     BEFORE forwarding to the ynfinite API - the API may still reject the
#     unknown formId afterwards; that is expected and fine.
#   - Fresh deploys are inside the 1h grace window (buildStamp), so missing
#     yn_form_method_token / yn_required_fields_token / dwell cookie are
#     allowed+logged with a _grace suffix. The dwell/strict scenarios that need
#     the window CLOSED are skipped unless the build marker is older than 1h.

set -u
cd "$(dirname "$0")/../.."

BASE_URL=${BASE_URL:-http://localhost:8080}
PAGE_PATH=${PAGE_PATH:-/}
FORM_ID=${FORM_ID:-test-form}
LOG_DIR=${LOG_DIR-tmp/bot_protection_logs}
RUN_HEAVY=${RUN_HEAVY:-0}
HOST_HEADER=${HOST_HEADER:-}

# curl wrapper: applies the optional Host override to every request
req() { curl -sS ${HOST_HEADER:+-H "Host: $HOST_HEADER"} "$@"; }

PASS=0; FAIL=0; SKIP=0
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

say()  { printf '%s\n' "$*"; }
pass() { PASS=$((PASS+1)); say "  ✅ $1"; }
fail() { FAIL=$((FAIL+1)); say "  ❌ $1"; }
skip() { SKIP=$((SKIP+1)); say "  ⏭️  $1"; }

log_file() { [ -n "$LOG_DIR" ] && echo "$LOG_DIR/$(date +%Y-%m-%d).yn-botprotection.log" || echo ""; }

# assert_log <label> <reason>  - the last log line for our FORM_ID has this reason
assert_log() {
	local label="$1" reason="$2" lf; lf=$(log_file)
	if [ -z "$lf" ] || [ ! -f "$lf" ]; then
		skip "$label: log file not reachable, skipping log assertion"
		return
	fi
	local line
	line=$(grep "\"formId\":\"$FORM_ID\"" "$lf" | tail -1)
	if printf '%s' "$line" | grep -q "\"reason\":\"$reason\""; then
		pass "$label: log reason '$reason'"
	else
		fail "$label: expected log reason '$reason', last line: ${line:-<none>}"
	fi
}

# mint_jar <jarfile> - GET the page, collect cookies, extract csrf + page html
mint_jar() {
	req -c "$1" -o "$TMP/page.html" "$BASE_URL$PAGE_PATH"
}
jar_cookie() { awk -v n="$2" '$6==n {print $7}' "$1" | tail -1; }

# post_form <jar> <extra -F args...> - POST /yn-form/send, echo response body
post_form() {
	local jar="$1"; shift
	local csrf; csrf=$(jar_cookie "$jar" ynfinite-csrf-protection)
	req -b "$jar" -c "$jar" \
		-F "method=post" \
		-F "formId=$FORM_ID" \
		-F "formLanguage=de" \
		-F "_csrf_token=$csrf" \
		-F "yn_confirm_email=$csrf" \
		"$@" \
		"$BASE_URL/yn-form/send"
}

solve_pow() { # <jar> [timestampMs] -> sets POW_HASH POW_NONCE POW_PREV POW_TS
	local csrf; csrf=$(jar_cookie "$1" ynfinite-csrf-protection)
	local json; json=$(node tests/botprotection/pow.mjs "$csrf" "$FORM_ID" 0 "${2:-}")
	POW_HASH=$(printf '%s' "$json" | python3 -c 'import json,sys;print(json.load(sys.stdin)["hash"])')
	POW_NONCE=$(printf '%s' "$json" | python3 -c 'import json,sys;print(json.load(sys.stdin)["nonce"])')
	POW_PREV=$(printf '%s' "$json" | python3 -c 'import json,sys;print(json.load(sys.stdin)["prevHash"])')
	POW_TS=$(printf '%s' "$json" | python3 -c 'import json,sys;print(json.load(sys.stdin)["timestamp"])')
}

pow_args() { echo "-F hasProof=true -F proofenHash=$POW_HASH -F proofenNonce=$POW_NONCE -F proofenPrevHash=$POW_PREV -F proofenTimestamp=$POW_TS"; }

say "── bot-protection harness against $BASE_URL$PAGE_PATH (formId: $FORM_ID)"
if ! req -f -o /dev/null "$BASE_URL$PAGE_PATH"; then
	say "❌ $BASE_URL$PAGE_PATH is not reachable - start the local stack first."
	exit 1
fi

# ─────────────────────────────────── cookies on every response (incl. cache hits)
JAR_A="$TMP/a.jar"; JAR_B="$TMP/b.jar"
mint_jar "$JAR_A"
say "▶ cookie minting"
[ -n "$(jar_cookie "$JAR_A" ynfinite-csrf-protection)" ] && pass "csrf cookie set on GET" || fail "csrf cookie missing on GET"
[ -n "$(jar_cookie "$JAR_A" ynfinite-form-ts)" ]        && pass "dwell cookie set on GET" || fail "dwell cookie (ynfinite-form-ts) missing on GET"

# ───────────────────────────────────────────────── dwell: too fast (fresh cookie)
say "▶ dwell"
RESP=$(post_form "$JAR_A" -F "hasProof=false" -F "proofenHash=")
if printf '%s' "$RESP" | grep -q "Security check failed"; then pass "dwell_too_fast rejected"; else fail "dwell_too_fast: unexpected response: $RESP"; fi
assert_log "dwell_too_fast" "dwell_too_fast"

say "  … sleeping 6s so the dwell check passes from here on"
sleep 6

# tampered dwell cookie (separate jar, valid csrf, forged ts)
mint_jar "$JAR_B"; sleep 6
python3 - "$JAR_B" << 'PY'
import sys
p = sys.argv[1]
lines = [l for l in open(p) if 'ynfinite-form-ts' not in l]
lines.append("localhost\tFALSE\t/\tFALSE\t0\tynfinite-form-ts\t1000000000.deadbeefdeadbeefdeadbeefdeadbeef\n")
open(p, 'w').writelines(lines)
PY
RESP=$(post_form "$JAR_B" -F "hasProof=false" -F "proofenHash=")
if printf '%s' "$RESP" | grep -q "Security check failed"; then pass "dwell_tampered rejected"; else fail "dwell_tampered: unexpected response: $RESP"; fi
assert_log "dwell_tampered" "dwell_tampered"

# ──────────────────────────────────────────────────────────────── PoW sub-reasons
say "▶ proof-of-work"
CSRF_A=$(jar_cookie "$JAR_A" ynfinite-csrf-protection)

RESP=$(post_form "$JAR_A" -F "hasProof=false" -F "proofenHash=")
printf '%s' "$RESP" | grep -q "Security check failed" && pass "missing proof rejected" || fail "missing proof: $RESP"
assert_log "pow_missing" "pow_missing"

RESP=$(post_form "$JAR_A" -F "hasProof=true" -F "proofenHash=true" -F "proofenNonce=")
assert_log "pow_forged_sentinel" "pow_forged_sentinel"

RESP=$(post_form "$JAR_A" -F "hasProof=true" -F "proofenHash=undefined" -F "proofenNonce=undefined")
assert_log "pow_bad_format" "pow_bad_format"

FAKE_HASH="00000$(printf 'a%.0s' $(seq 1 59))"  # exactly 64 hex chars
RESP=$(post_form "$JAR_A" -F "hasProof=true" -F "proofenHash=$FAKE_HASH" -F "proofenNonce=")
assert_log "pow_no_nonce" "pow_no_nonce"

RESP=$(post_form "$JAR_A" -F "hasProof=true" -F "proofenHash=$FAKE_HASH" -F "proofenNonce=1" -F "proofenPrevHash=0" -F "proofenTimestamp=123")
assert_log "pow_mismatch" "pow_mismatch"

# correct recompute but wrong prefix -> difficulty
TS_NOW=$(($(date +%s) * 1000))
LOWHASH=$(python3 -c "
import hashlib,sys
csrf, formid, ts = sys.argv[1], sys.argv[2], sys.argv[3]
n = 0
while True:
    n += 1
    h = hashlib.sha256((csrf + '0' + ts + formid + str(n)).encode()).hexdigest()
    if h[:5] not in ('00000','11111'):
        print(h, n); break
" "$CSRF_A" "$FORM_ID" "$TS_NOW")
RESP=$(post_form "$JAR_A" -F "hasProof=true" -F "proofenHash=${LOWHASH%% *}" -F "proofenNonce=${LOWHASH##* }" -F "proofenPrevHash=0" -F "proofenTimestamp=$TS_NOW")
assert_log "pow_difficulty" "pow_difficulty"

# happy path: PHP layers all pass -> 'allowed' logged before the API call
say "▶ happy path + replay"
solve_pow "$JAR_A"
RESP=$(post_form "$JAR_A" $(pow_args))
assert_log "happy path" "allowed"

# replay: identical proof again
RESP=$(post_form "$JAR_A" $(pow_args))
printf '%s' "$RESP" | grep -q "Security check failed" && pass "replayed proof rejected" || fail "replay: $RESP"
assert_log "pow_replay" "pow_replay"

# ──────────────────────────────────────────────────────────── timestamp freshness
say "▶ freshness"
# 25h-old timestamp: outside the 24h hard bound -> reject (cookie only lives 24h,
# so no legitimate proof can be this old)
solve_pow "$JAR_A" "$(( ($(date +%s) - 90000) * 1000 ))"
RESP=$(post_form "$JAR_A" $(pow_args))
printf '%s' "$RESP" | grep -q "Security check failed" && pass "25h-old proof rejected" || fail "pow_stale: $RESP"
assert_log "pow_stale" "pow_stale"

# 31min-old timestamp: telemetry window only -> allowed, softlog line written
solve_pow "$JAR_A" "$(( ($(date +%s) - 1860) * 1000 ))"
RESP=$(post_form "$JAR_A" $(pow_args))
assert_log "31min-old proof still allowed" "allowed"
lf=$(log_file)
if [ -n "$lf" ] && [ -f "$lf" ]; then
	grep -q '"reason":"pow_stale_softlog"' "$lf" && pass "pow_stale_softlog telemetry written" || fail "no pow_stale_softlog line"
fi

# ─────────────────────────────────────────────────────────────────────── honeypot
say "▶ honeypot"
solve_pow "$JAR_A"
RESP=$(req -b "$JAR_A" -c "$JAR_A" \
	-F "method=post" -F "formId=$FORM_ID" -F "formLanguage=de" \
	-F "_csrf_token=$CSRF_A" -F "yn_confirm_email=my@email.com" \
	$(pow_args) "$BASE_URL/yn-form/send")
printf '%s' "$RESP" | grep -q "Security check failed" && pass "honeypot mismatch rejected" || fail "honeypot: $RESP"
assert_log "honeypot" "honeypot"

# ──────────────────────────────────────────────────────── shared-cache invariants
say "▶ shared cache (multi-user)"
mint_jar "$JAR_B"
HTML="$TMP/page.html"
if [ -n "$(jar_cookie "$JAR_B" ynfinite-csrf-protection)" ] && [ -n "$(jar_cookie "$JAR_B" ynfinite-form-ts)" ]; then
	pass "second visitor (empty jar) receives both cookies"
else
	fail "second visitor did not receive fresh cookies"
fi
CSRF_B=$(jar_cookie "$JAR_B" ynfinite-csrf-protection)
if grep -q "$CSRF_B" "$HTML" || { [ -n "$CSRF_A" ] && grep -q "$CSRF_A" "$HTML"; }; then
	fail "cache purity: a visitor's cookie value appears in the served HTML"
else
	pass "cache purity: no per-visitor cookie value in the HTML"
fi
if grep -q "yn_form_method_token" "$HTML"; then
	pass "shared HMAC tokens present in served HTML"
else
	skip "yn_form_method_token not in $PAGE_PATH (no POST form on this page?)"
fi

# ──────────────────────────────────────────────── reject limiter (heavy, opt-in)
if [ "$RUN_HEAVY" = "1" ]; then
	say "▶ rejected-attempt lockout (21 requests)"
	JAR_H="$TMP/h.jar"; mint_jar "$JAR_H"; sleep 6
	CSRF_H=$(jar_cookie "$JAR_H" ynfinite-csrf-protection)
	for i in $(seq 1 20); do
		req -o /dev/null -b "$JAR_H" -c "$JAR_H" \
			-F "method=post" -F "formId=$FORM_ID" -F "formLanguage=de" \
			-F "_csrf_token=$CSRF_H" -F "yn_confirm_email=wrong" \
			-F "hasProof=false" -F "proofenHash=" "$BASE_URL/yn-form/send"
	done
	RESP=$(post_form "$JAR_H" -F "hasProof=false" -F "proofenHash=")
	printf '%s' "$RESP" | grep -q "Too many form submissions" && pass "21st attempt locked out" || fail "reject limiter: $RESP"
	assert_log "rate_limit_rejected" "rate_limit_rejected"
else
	skip "reject limiter (set RUN_HEAVY=1 to run 21 requests)"
fi

say ""
say "── done: $PASS passed, $FAIL failed, $SKIP skipped"
[ "$FAIL" -eq 0 ]
