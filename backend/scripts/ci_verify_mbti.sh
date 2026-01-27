#!/usr/bin/env bash
set -euo pipefail

# -----------------------------
# Auth header holder (must be defined under -u)
# -----------------------------
CURL_AUTH=()

# -----------------------------
# Paths
# -----------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
REPO_DIR="$(cd "$BACKEND_DIR/.." && pwd)"
cd "$BACKEND_DIR"

# -----------------------------
# Defaults (override via env)
# -----------------------------
HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-18000}"                      # ✅ avoid 8000 collisions by default
API="http://${HOST}:${PORT}"

REGION="${REGION:-CN_MAINLAND}"
LOCALE="${LOCALE:-zh-CN}"

# Your stable pack identifiers
PACK_ID="${PACK_ID:-MBTI.cn-mainland.zh-CN.v0.2.1-TEST}"
LEGACY_DIR="${LEGACY_DIR:-MBTI-CN-v0.2.1-TEST}"

# Artifacts
RUN_DIR="${RUN_DIR:-$BACKEND_DIR/artifacts/verify_mbti}"
ARTIFACT_DIR="$RUN_DIR"                    # alias (for docs/consistency)
LOG_DIR="$RUN_DIR/logs"
mkdir -p "$LOG_DIR"

SERVE_LOG="$LOG_DIR/artisan_serve.log"
SELF_CHECK_LOG="$LOG_DIR/self_check.log"
SMOKE_Q_LOG="$LOG_DIR/smoke_questions.json"
MVP_LOG="$LOG_DIR/mvp_check.log"

# ----------------------------
# Phase B: phone OTP acceptance script
# ----------------------------
ACCEPT_PHONE_SH="$SCRIPT_DIR/accept_auth_phone.sh"
ACCEPT_PHONE="${ACCEPT_PHONE:-1}"  # 1=run, 0=skip

if [[ "$ACCEPT_PHONE" == "1" ]]; then
  if [[ ! -x "$ACCEPT_PHONE_SH" ]]; then
    echo "[CI][FAIL] missing or not executable: $ACCEPT_PHONE_SH" >&2
    exit 14
  fi
fi

# ----------------------------
# Phase C-1: email claim acceptance (default off)
# ----------------------------
ACCEPT_EMAIL_SH="$SCRIPT_DIR/accept_email_claim.sh"
ACCEPT_EMAIL_DEDUP_SH="$SCRIPT_DIR/accept_email_outbox_dedup.sh"
ACCEPT_EMAIL="${ACCEPT_EMAIL:-0}"  # 1=run, 0=skip

if [[ "$ACCEPT_EMAIL" == "1" ]]; then
  if [[ ! -f "$ACCEPT_EMAIL_SH" ]]; then
    echo "[CI][FAIL] missing: $ACCEPT_EMAIL_SH" >&2
    exit 14
  fi
  if [[ ! -f "$ACCEPT_EMAIL_DEDUP_SH" ]]; then
    echo "[CI][FAIL] missing: $ACCEPT_EMAIL_DEDUP_SH" >&2
    exit 14
  fi
fi

# ----------------------------
# Phase C-2: identities bind acceptance (default off)
# ----------------------------
ACCEPT_IDENTITIES_SH="$SCRIPT_DIR/accept_identities_bind.sh"
ACCEPT_IDENTITIES="${ACCEPT_IDENTITIES:-0}"  # 1=run, 0=skip

if [[ "$ACCEPT_IDENTITIES" == "1" ]]; then
  if [[ ! -f "$ACCEPT_IDENTITIES_SH" ]]; then
    echo "[CI][FAIL] missing: $ACCEPT_IDENTITIES_SH" >&2
    exit 14
  fi
fi

# ----------------------------
# Phase C-3: abuse audit acceptance (default off)
# ----------------------------
ACCEPT_ABUSE_SH="$SCRIPT_DIR/accept_abuse_audit.sh"
ACCEPT_ABUSE="${ACCEPT_ABUSE:-0}"  # 1=run, 0=skip

if [[ "$ACCEPT_ABUSE" == "1" ]]; then
  if [[ ! -f "$ACCEPT_ABUSE_SH" ]]; then
    echo "[CI][FAIL] missing: $ACCEPT_ABUSE_SH" >&2
    exit 14
  fi
fi

# ----------------------------
# Phase C-4: lookup/order acceptance (default off)
# ----------------------------
ACCEPT_ORDER_SH="$SCRIPT_DIR/accept_lookup_order.sh"
ACCEPT_ORDER="${ACCEPT_ORDER:-0}"  # 1=run, 0=skip

if [[ "$ACCEPT_ORDER" == "1" ]]; then
  if [[ ! -f "$ACCEPT_ORDER_SH" ]]; then
    echo "[CI][FAIL] missing: $ACCEPT_ORDER_SH" >&2
    exit 14
  fi
fi

# MVP hard gate toggle:
# - MVP_STRICT=1 (default): fail CI if MVP thresholds not met
# - MVP_STRICT=0: only log, do not fail
MVP_STRICT="${MVP_STRICT:-1}"

echo "[CI] backend_dir=$BACKEND_DIR"
echo "[CI] repo_dir=$REPO_DIR"
echo "[CI] API=$API"
echo "[CI] defaults: region=$REGION locale=$LOCALE pack_id=$PACK_ID legacy_dir=$LEGACY_DIR"
echo "[CI] artifacts=$RUN_DIR"
echo "[CI] mvp_strict=$MVP_STRICT"

# -----------------------------
# Helpers
# -----------------------------
need_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "[CI][FAIL] missing cmd: $1" >&2; exit 2; }; }
need_cmd curl
need_cmd jq
need_cmd php
need_cmd grep
need_cmd sed
need_cmd lsof
need_cmd cmp

fail() { echo "[CI][FAIL] $*" >&2; exit 1; }

set_curl_auth() {
  CURL_AUTH=()
  if [[ -n "${FM_TOKEN:-}" && "${FM_TOKEN}" != "null" ]]; then
    CURL_AUTH=(-H "Authorization: Bearer ${FM_TOKEN}")
  fi
}

wait_health() {
  local url="$1"
  local tries="${2:-80}"   # ~16s if sleep 0.2
  for i in $(seq 1 "$tries"); do
    if curl -fsS "$url" >/dev/null 2>&1; then
      return 0
    fi
    sleep 0.2
  done
  return 1
}

fetch_authed_json() {
  local url="$1"
  local out="$2"
  local http
  http="$(curl -sS -L -o "$out" -w "%{http_code}" "${CURL_AUTH[@]}" "$url" || true)"
  if [[ "$http" != "200" && "$http" != "201" ]]; then
    echo "[CI][FAIL] fetch failed: HTTP=$http url=$url" >&2
    echo "---- body (first 800 bytes) ----" >&2
    head -c 800 "$out" 2>/dev/null || true
    echo >&2
    exit 16
  fi
  if [[ ! -s "$out" ]]; then
    echo "[CI][FAIL] empty response body: $url" >&2
    exit 16
  fi
}

# -----------------------------
# Ensure legacy alias exists (CI runner usually doesn't have it)
#   ../content_packages/MBTI-CN-v0.2.1-TEST -> default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST
# -----------------------------
CONTENT_ROOT="$REPO_DIR/content_packages"
CANON_REL="default/${REGION}/${LOCALE}/${LEGACY_DIR}"
CANON_ABS="$CONTENT_ROOT/$CANON_REL"
ALIAS_ABS="$CONTENT_ROOT/$LEGACY_DIR"

if [[ -d "$CANON_ABS" ]]; then
  if [[ ! -e "$ALIAS_ABS" ]]; then
    echo "[CI] create legacy alias: $ALIAS_ABS -> $CANON_REL"
    (cd "$CONTENT_ROOT" && ln -s "$CANON_REL" "$LEGACY_DIR")
  else
    echo "[CI] legacy alias exists: $ALIAS_ABS"
  fi
else
  echo "[CI][WARN] canonical pack dir not found: $CANON_ABS"
  echo "[CI][WARN] CI may fail if app still tries to load legacy dir."
fi

# Prefer canonical pack dir; fall back to alias if needed
PACK_DIR=""
if [[ -d "$CANON_ABS" ]]; then
  PACK_DIR="$CANON_ABS"
elif [[ -d "$ALIAS_ABS" ]]; then
  PACK_DIR="$ALIAS_ABS"
fi

if [[ -z "$PACK_DIR" ]]; then
  echo "[CI][WARN] PACK_DIR not found (both canonical and legacy alias missing). MVP check will be skipped."
else
  echo "[CI] PACK_DIR=$PACK_DIR"
fi

# -----------------------------
# ContentGraph inventory (role_cards / strategy_cards / reads)
# -----------------------------
if [[ -z "${PACK_DIR:-}" ]]; then
  echo "[CI][FAIL] PACK_DIR missing; cannot compute content graph inventory" >&2
  exit 40
fi

INVENTORY_JSON="$RUN_DIR/content_graph_inventory.json"
ROLE_DIR="$PACK_DIR/role_cards"
STRATEGY_DIR="$PACK_DIR/strategy_cards"
READS_DIR="$PACK_DIR/reads"

for d in "$ROLE_DIR" "$STRATEGY_DIR" "$READS_DIR"; do
  [[ -d "$d" ]] || fail "missing content dir: $d"
done

# counts（不使用 mapfile/数组，兼容 bash 3.x）
role_count="$(find "$ROLE_DIR" -maxdepth 1 -type f -name '*.json' | wc -l | tr -d ' ')"
strategy_count="$(find "$STRATEGY_DIR" -maxdepth 1 -type f -name '*.json' | wc -l | tr -d ' ')"
reads_count="$(find "$READS_DIR" -maxdepth 1 -type f -name '*.json' | wc -l | tr -d ' ')"

if [[ "$role_count" == "0" ]]; then fail "no role_cards json files found in $ROLE_DIR"; fi
if [[ "$strategy_count" == "0" ]]; then fail "no strategy_cards json files found in $STRATEGY_DIR"; fi
if [[ "$reads_count" == "0" ]]; then fail "no reads json files found in $READS_DIR"; fi

# role_type_codes：兼容两种字段：type_code / type_codes[]
role_type_codes="$(jq -r '(.type_code? // empty), (.type_codes[]? // empty)' "$ROLE_DIR"/*.json \
  | sed '/^$/d' | sort -u)"

expected_type_codes="$(
  cat <<'EOF'
INTJ-A
INTJ-T
INTP-A
INTP-T
ENTJ-A
ENTJ-T
ENTP-A
ENTP-T
INFJ-A
INFJ-T
INFP-A
INFP-T
ENFJ-A
ENFJ-T
ENFP-A
ENFP-T
ISTJ-A
ISTJ-T
ISFJ-A
ISFJ-T
ESTJ-A
ESTJ-T
ESFJ-A
ESFJ-T
ISTP-A
ISTP-T
ISFP-A
ISFP-T
ESTP-A
ESTP-T
ESFP-A
ESFP-T
EOF
)"

missing_lines=""
while IFS= read -r code; do
  [[ -z "$code" ]] && continue
  if ! printf '%s\n' "$role_type_codes" | grep -Fxq "$code"; then
    missing_lines+="${code}"$'\n'
  fi
done <<<"$expected_type_codes"

# samples（固定排序取前 N；不使用数组变量）
role_sample_json="$(jq -r '.id' "$ROLE_DIR"/*.json | sort | head -n 5 | jq -R . | jq -s .)"
strategy_sample_json="$(jq -r '.id' "$STRATEGY_DIR"/*.json | sort | head -n 5 | jq -R . | jq -s .)"
read_sample_json="$(jq -r '.id' "$READS_DIR"/*.json | sort | head -n 8 | jq -R . | jq -s .)"
missing_json="$(printf '%s' "$missing_lines" | sed '/^$/d' | jq -R . | jq -s .)"

jq -n \
  --argjson role_count "$role_count" \
  --argjson strategy_count "$strategy_count" \
  --argjson reads_count "$reads_count" \
  --argjson missing_role_codes "$missing_json" \
  --argjson role_sample "$role_sample_json" \
  --argjson strategy_sample "$strategy_sample_json" \
  --argjson read_sample "$read_sample_json" \
  '{
    counts: {
      role_cards: $role_count,
      strategy_cards: $strategy_count,
      reads: $reads_count
    },
    missing: {
      role_cards: $missing_role_codes
    },
    sample: {
      role_cards: $role_sample,
      strategy_cards: $strategy_sample,
      reads: $read_sample
    }
  }' >"$INVENTORY_JSON"

echo "[CI] content graph inventory -> $INVENTORY_JSON"
echo "[CI] counts: role_cards=$role_count strategy_cards=$strategy_count reads=$reads_count"

missing_count="$(printf '%s' "$missing_lines" | sed '/^$/d' | wc -l | tr -d ' ')"
if [[ "$missing_count" != "0" ]]; then
  echo "[CI][FAIL] missing role_card type_codes:" >&2
  printf '%s' "$missing_lines" >&2
  exit 41
fi
if (( role_count != 32 )); then
  echo "[CI][FAIL] role_cards count=$role_count (expect 32)" >&2
  exit 42
fi
if (( strategy_count < 6 )); then
  echo "[CI][FAIL] strategy_cards count=$strategy_count (expect >=6)" >&2
  exit 43
fi
if (( reads_count < 60 )); then
  echo "[CI][FAIL] reads count=$reads_count (expect >=60)" >&2
  exit 44
fi

# -----------------------------
# Prepare Laravel env + DB
# -----------------------------
echo "[CI] prepare laravel env/db"

if [[ ! -f ".env" ]]; then
  cp -a .env.example .env
fi

php artisan key:generate --force >/dev/null 2>&1 || true

mkdir -p database
touch database/database.sqlite

# Force sqlite (safe for CI)
export APP_ENV="${APP_ENV:-testing}"
export DB_CONNECTION="${DB_CONNECTION:-sqlite}"
export DB_DATABASE="${DB_DATABASE:-$BACKEND_DIR/database/database.sqlite}"
export QUEUE_CONNECTION="${QUEUE_CONNECTION:-sync}"

php artisan migrate --force >/dev/null

# -----------------------------
# Start server (fail fast if port in use)
# -----------------------------
if lsof -ti "tcp:${PORT}" >/dev/null 2>&1; then
  fail "port already in use: ${PORT}. Stop your local server or set PORT=18xxx"
fi

echo "[CI] starting server: php artisan serve --host=$HOST --port=$PORT"
php artisan serve --host="$HOST" --port="$PORT" >"$SERVE_LOG" 2>&1 &
SERVE_PID=$!

cleanup() {
  local ec=$?
  if [[ -n "${SERVE_PID:-}" ]]; then
    kill "$SERVE_PID" >/dev/null 2>&1 || true
  fi
  exit $ec
}
trap cleanup EXIT

echo "[CI] waiting for health: $API/api/v0.2/health"
wait_health "$API/api/v0.2/health" 100 || {
  echo "[CI][FAIL] server not ready"
  echo "---- tail artisan_serve.log ----" >&2
  tail -n 120 "$SERVE_LOG" >&2 || true
  exit 11
}
echo "[CI] server ready (pid=$SERVE_PID)"

# -----------------------------
# Self-check: manifest/assets/schema
# -----------------------------
echo "[CI] fap:self-check (manifest/assets/schema)"
php artisan fap:self-check >"$SELF_CHECK_LOG" 2>&1 || {
  echo "[CI][FAIL] self-check failed"
  tail -n 220 "$SELF_CHECK_LOG" >&2 || true
  exit 12
}
echo "[CI] self-check OK"

# -----------------------------
# MVP check (templates + reads)
# - Always persist log to artifacts (for postmortem)
# - Default is HARD GATE (MVP_STRICT=1)
# -----------------------------
if [[ -n "$PACK_DIR" ]]; then
  MVP_SH="$SCRIPT_DIR/mvp_check.sh"
  if [[ ! -x "$MVP_SH" ]]; then
    echo "[CI][FAIL] missing or not executable: $MVP_SH" >&2
    exit 14
  fi

  echo "[CI] MVP check -> $MVP_LOG"
  set +e
  {
    echo "== MVP check (templates + reads) =="
    echo "PACK_DIR=$PACK_DIR"
    bash "$MVP_SH" "$PACK_DIR"
    echo "EXIT=$?"
  } 2>&1 | tee "$MVP_LOG"
  set -e

  if [[ "$MVP_STRICT" == "1" ]]; then
    # 1) templates coverage: any false => FAIL
    if grep -qE '^[A-Z]{2}\.[A-Z]=false$' "$MVP_LOG"; then
      echo "[CI][FAIL] MVP templates coverage has false (see $MVP_LOG)" >&2
      exit 30
    fi

    # 2) reads thresholds
    total_unique="$(grep -E '^reads\.total_unique=' "$MVP_LOG" | tail -n 1 | sed -E 's/^reads\.total_unique=//')"
    fallback="$(grep -E '^reads\.fallback=' "$MVP_LOG" | tail -n 1 | sed -E 's/^reads\.fallback=//')"
    non_empty="$(grep -E '^reads\.non_empty_strategy_buckets=' "$MVP_LOG" | tail -n 1 | sed -E 's/^reads\.non_empty_strategy_buckets=([0-9]+).*/\1/')"

    if ! [[ "$total_unique" =~ ^[0-9]+$ && "$fallback" =~ ^[0-9]+$ && "$non_empty" =~ ^[0-9]+$ ]]; then
      echo "[CI][FAIL] MVP reads stats missing/non-numeric (see $MVP_LOG)" >&2
      exit 31
    fi
    if (( total_unique < 7 )); then
      echo "[CI][FAIL] MVP reads.total_unique=$total_unique < 7 (see $MVP_LOG)" >&2
      exit 32
    fi
    if (( fallback < 2 )); then
      echo "[CI][FAIL] MVP reads.fallback=$fallback < 2 (see $MVP_LOG)" >&2
      exit 33
    fi
    if (( non_empty < 2 )); then
      echo "[CI][FAIL] MVP reads.non_empty_strategy_buckets=$non_empty < 2 (see $MVP_LOG)" >&2
      exit 34
    fi

    echo "[CI] MVP check PASS ✅"
  else
    echo "[CI][WARN] MVP_STRICT=0; skip MVP hard gate (log only)."
  fi
fi

# -----------------------------
# Smoke: questions endpoint must be ok=true
# -----------------------------
echo "[CI] smoke: /api/v0.2/scales/MBTI/questions"
curl -fsS "$API/api/v0.2/scales/MBTI/questions" >"$SMOKE_Q_LOG" || {
  echo "[CI][FAIL] smoke curl failed"
  tail -n 120 "$SERVE_LOG" >&2 || true
  exit 13
}

if ! jq -e '.ok==true' "$SMOKE_Q_LOG" >/dev/null 2>&1; then
  echo "[CI][FAIL] smoke returned ok=false. body:"
  head -c 1200 "$SMOKE_Q_LOG" || true
  echo
  echo "[CI] tail artisan_serve.log:"
  tail -n 200 "$SERVE_LOG" || true
  exit 13
fi
echo "[CI] smoke OK"

# -----------------------------
# Run E2E verify
# -----------------------------
echo "[CI] get fm_token for gated endpoints"
FM_TOKEN="$(
  curl -sS -X POST "$API/api/v0.2/auth/wx_phone" \
    -H "Content-Type: application/json" \
    -d '{"wx_code":"dev","phone_code":"dev","anon_id":"ci_verify"}' \
  | jq -r .token
)"

if [[ -z "$FM_TOKEN" || "$FM_TOKEN" == "null" ]]; then
  echo "[CI][FAIL] cannot get token from /api/v0.2/auth/wx_phone" >&2
  exit 15
fi

set_curl_auth

echo "[CI] running verify_mbti.sh (with FM_TOKEN)"
API="$API" REGION="$REGION" LOCALE="$LOCALE" RUN_DIR="$RUN_DIR" FM_TOKEN="$FM_TOKEN" \
  bash "$SCRIPT_DIR/verify_mbti.sh"
echo "[CI] verify_mbti OK ✅"

# -----------------------------
# ContentGraph: stability / rollback verification
# -----------------------------
RR_DIR="$RUN_DIR/content_graph"
mkdir -p "$RR_DIR"
ATTEMPT_ID_FILE="$RUN_DIR/attempt_id.txt"
if [[ ! -s "$ATTEMPT_ID_FILE" ]]; then
  fail "missing attempt_id file for content_graph checks: $ATTEMPT_ID_FILE"
fi
ATTEMPT_ID="$(cat "$ATTEMPT_ID_FILE")"
REPORT_URL="$API/api/v0.2/attempts/$ATTEMPT_ID/report"

RR_REPORT_1="$RR_DIR/report_rr_1.json"
RR_REPORT_2="$RR_DIR/report_rr_2.json"
RR_LIST_1="$RR_DIR/recommended_reads_1.json"
RR_LIST_2="$RR_DIR/recommended_reads_2.json"
RR_COMPARE="$RR_DIR/recommended_reads_compare.json"
RR_ROLLBACK="$RR_DIR/recommended_reads_rollback.json"

if [[ "${CONTENT_GRAPH_ENABLED:-0}" == "1" ]]; then
  echo "[CI] content_graph stability: recommended_reads (CONTENT_GRAPH_ENABLED=1)"
  fetch_authed_json "$REPORT_URL" "$RR_REPORT_1"
  fetch_authed_json "$REPORT_URL" "$RR_REPORT_2"

  for f in "$RR_REPORT_1" "$RR_REPORT_2"; do
    if ! jq -e '.report.recommended_reads | type=="array"' "$f" >/dev/null 2>&1; then
      fail "report.recommended_reads missing or not array (CONTENT_GRAPH_ENABLED=1). file=$f"
    fi

    rr_count="$(jq -r '.report.recommended_reads | length' "$f")"
    if (( rr_count < 3 || rr_count > 6 )); then
      fail "report.recommended_reads count out of range: $rr_count (expect 3-6). file=$f"
    fi

    if [[ "$f" == "$RR_REPORT_1" ]]; then
      jq -c '[.report.recommended_reads[] | {id,type,slug,why,show_order}]' "$f" >"$RR_LIST_1"
    else
      jq -c '[.report.recommended_reads[] | {id,type,slug,why,show_order}]' "$f" >"$RR_LIST_2"
    fi
done

  first_json="$(cat "$RR_LIST_1")"
  second_json="$(cat "$RR_LIST_2")"
  jq -n --argjson first "$first_json" --argjson second "$second_json" \
      '{first:$first, second:$second}' >"$RR_COMPARE"

    if ! cmp -s "$RR_LIST_1" "$RR_LIST_2"; then
      fail "recommended_reads unstable across calls (see $RR_COMPARE)"
    fi

  echo "[CI] content_graph stability OK (recommended_reads count=$rr_count)"
else
  echo "[CI] content_graph rollback: recommended_reads disabled (CONTENT_GRAPH_ENABLED=0)"
  REPORT_JSON="$RUN_DIR/report.json"
  if [[ ! -s "$REPORT_JSON" ]]; then
    fetch_authed_json "$REPORT_URL" "$RR_REPORT_1"
    REPORT_JSON="$RR_REPORT_1"
  fi

  if jq -e '.report | has("recommended_reads") and (.recommended_reads | type!="array" and . != null)' "$REPORT_JSON" >/dev/null 2>&1; then
    fail "report.recommended_reads present but not array (CONTENT_GRAPH_ENABLED=0). file=$REPORT_JSON"
  fi
  if jq -e '.report.recommended_reads? | type=="array" and length>0' "$REPORT_JSON" >/dev/null 2>&1; then
    fail "report.recommended_reads should be empty or missing when CONTENT_GRAPH_ENABLED=0. file=$REPORT_JSON"
  fi

  rr_len="$(jq -r '.report.recommended_reads? | length // 0' "$REPORT_JSON")"
  has_rr="false"
  if jq -e '.report | has("recommended_reads")' "$REPORT_JSON" >/dev/null 2>&1; then
    has_rr="true"
  fi

  jq -n \
    --argjson has_rr "$has_rr" \
    --argjson rr_len "$rr_len" \
    '{has_recommended_reads: $has_rr, recommended_reads_length: $rr_len}' \
    >"$RR_ROLLBACK"

  echo "[CI] content_graph rollback OK (recommended_reads length=$rr_len)"
fi

# -----------------------------
# Events acceptance (M3)
# -----------------------------
echo "[CI] events acceptance (M3)"

# Ensure sqlite path is passed to acceptance scripts (keep consistent with CI env)
SQLITE_DB_FOR_ACCEPT="${DB_DATABASE:-$BACKEND_DIR/database/database.sqlite}"

# ----------------------------
# Phase B: phone OTP acceptance (run before events so logs/order are clear)
# ----------------------------
if [[ "$ACCEPT_PHONE" == "1" ]]; then
  echo "[CI] phone otp acceptance (Phase B)"
  API="$API" SQLITE_DB="$SQLITE_DB_FOR_ACCEPT" bash "$ACCEPT_PHONE_SH"
  echo "[CI] phone otp acceptance OK"
fi

# ----------------------------
# Phase C-1: email claim acceptance (optional)
# ----------------------------
if [[ "$ACCEPT_EMAIL" == "1" ]]; then
  echo "[CI] email claim acceptance (Phase C-1)"
  API="$API" SQLITE_DB="$SQLITE_DB_FOR_ACCEPT" bash "$ACCEPT_EMAIL_SH"
  echo "[CI] email claim acceptance OK"

  echo "[CI] email outbox dedup acceptance (Phase C-1b)"
  API="$API" SQLITE_DB="$SQLITE_DB_FOR_ACCEPT" bash "$ACCEPT_EMAIL_DEDUP_SH"
  echo "[CI] email outbox dedup acceptance OK"
fi

# ----------------------------
# Phase C-2: identities bind acceptance (optional)
# ----------------------------
if [[ "$ACCEPT_IDENTITIES" == "1" ]]; then
  echo "[CI] identities bind acceptance (Phase C-2)"
  API="$API" SQLITE_DB="$SQLITE_DB_FOR_ACCEPT" bash "$ACCEPT_IDENTITIES_SH"
  echo "[CI] identities bind acceptance OK"
fi

# ----------------------------
# Phase C-3: abuse audit acceptance (optional)
# ----------------------------
if [[ "$ACCEPT_ABUSE" == "1" ]]; then
  echo "[CI] abuse audit acceptance (Phase C-3)"
  API="$API" SQLITE_DB="$SQLITE_DB_FOR_ACCEPT" bash "$ACCEPT_ABUSE_SH"
  echo "[CI] abuse audit acceptance OK"
fi

# ----------------------------
# Phase C-4: lookup/order acceptance (optional)
# ----------------------------
if [[ "$ACCEPT_ORDER" == "1" ]]; then
  echo "[CI] lookup order acceptance (Phase C-4)"
  API="$API" SQLITE_DB="$SQLITE_DB_FOR_ACCEPT" bash "$ACCEPT_ORDER_SH"
  echo "[CI] lookup order acceptance OK"
fi

API="$API" SQLITE_DB="$SQLITE_DB_FOR_ACCEPT" FM_TOKEN="$FM_TOKEN" \
  "$SCRIPT_DIR/accept_events_C.sh" >/dev/null

API="$API" SQLITE_DB="$SQLITE_DB_FOR_ACCEPT" FM_TOKEN="$FM_TOKEN" \
  "$SCRIPT_DIR/accept_events_F_result_view_meta.sh"

API="$API" SQLITE_DB="$SQLITE_DB_FOR_ACCEPT" FM_TOKEN="$FM_TOKEN" \
  "$SCRIPT_DIR/accept_events_G_report_view_meta.sh"

API="$API" SQLITE_DB="$SQLITE_DB_FOR_ACCEPT" FM_TOKEN="$FM_TOKEN" \
  "$SCRIPT_DIR/accept_events_E_share_meta.sh"

API="$API" SQLITE_DB="$SQLITE_DB_FOR_ACCEPT" FM_TOKEN="$FM_TOKEN" \
  "$SCRIPT_DIR/accept_events_H_share_view_meta.sh"

API="$API" SQLITE_DB="$SQLITE_DB_FOR_ACCEPT" FM_TOKEN="$FM_TOKEN" \
  "$SCRIPT_DIR/accept_events_D_anon.sh"

API="$API" SQLITE_DB="$SQLITE_DB_FOR_ACCEPT" FM_TOKEN="$FM_TOKEN" \
  "$SCRIPT_DIR/accept_events_D_click_anon_override.sh"

API="$API" SQLITE_DB="$SQLITE_DB_FOR_ACCEPT" FM_TOKEN="$FM_TOKEN" \
  "$SCRIPT_DIR/accept_events_D_anon_block_placeholder_click.sh"

echo "[CI] events acceptance OK"
