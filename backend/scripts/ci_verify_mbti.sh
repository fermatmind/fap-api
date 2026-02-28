#!/usr/bin/env bash
set -euo pipefail

# --- CI hard defaults (force deterministic env) ---
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_DIR="$(cd "${BACKEND_DIR}/.." && pwd)"

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1
export XDG_CONFIG_HOME="${XDG_CONFIG_HOME:-/tmp/psysh_config}"
mkdir -p "$XDG_CONFIG_HOME" || true

# DB (force CI sqlite)
export APP_ENV="${APP_ENV:-testing}"
export DB_CONNECTION=sqlite
export DB_DATABASE=/tmp/fap-ci.sqlite
export QUEUE_CONNECTION=sync

# Content packs (force local driver + repo path)
export FAP_PACKS_DRIVER=local
export FAP_PACKS_ROOT="${REPO_DIR}/content_packages"
export FAP_PACKS_CACHE_DIR="${FAP_PACKS_CACHE_DIR:-storage/app/private/content_packs_cache}"
export FAP_S3_PREFIX="${FAP_S3_PREFIX:-content_packages/}"

# MBTI defaults (avoid resolver picking default)
export FAP_DEFAULT_REGION=CN_MAINLAND
export FAP_DEFAULT_LOCALE=zh-CN
export FAP_DEFAULT_PACK_ID=MBTI.cn-mainland.zh-CN.v0.3
export FAP_DEFAULT_DIR_VERSION=MBTI-CN-v0.3

# -----------------------------
# Auth header holder (must be defined under -u)
# -----------------------------
CURL_AUTH=()

# -----------------------------
# Paths (set cwd to backend BEFORE running any artisan)
# -----------------------------
cd "$BACKEND_DIR"

ENV_CREATED=0
SERVE_PID=""

# Ensure .env exists + key before any artisan
if [[ ! -f ".env" ]]; then
  cp -a .env.example .env
  ENV_CREATED=1
fi
php artisan key:generate --force >/dev/null 2>&1 || true

# Prepare sqlite + seed scales registry/slugs
bash "$BACKEND_DIR/scripts/ci/prepare_sqlite.sh"
php artisan fap:schema:verify

RUN_BIG5_OCEAN_GATE="${RUN_BIG5_OCEAN_GATE:-0}"
RUN_CLINICAL_COMBO_68_GATE="${RUN_CLINICAL_COMBO_68_GATE:-0}"
RUN_SDS_20_GATE="${RUN_SDS_20_GATE:-0}"
RUN_EQ_60_GATE="${RUN_EQ_60_GATE:-0}"
RUN_SDS_NORMS_GATE="${RUN_SDS_NORMS_GATE:-0}"
RUN_FULL_SCALE_REGRESSION="${RUN_FULL_SCALE_REGRESSION:-0}"
RUN_SCALE_IDENTITY_GATE="${RUN_SCALE_IDENTITY_GATE:-0}"
RUN_SCALE_IDENTITY_CONTRACT="${RUN_SCALE_IDENTITY_CONTRACT:-0}"
RUN_PARTNER_API_SMOKE="${RUN_PARTNER_API_SMOKE:-1}"
RUN_EXPERIMENT_GOVERNANCE_GATE="${RUN_EXPERIMENT_GOVERNANCE_GATE:-0}"
RUN_OPENAPI_DIFF_GATE="${RUN_OPENAPI_DIFF_GATE:-1}"
OPENAPI_DIFF_ALLOW_DRIFT="${OPENAPI_DIFF_ALLOW_DRIFT:-0}"
if [[ "$RUN_FULL_SCALE_REGRESSION" == "1" && "$RUN_SCALE_IDENTITY_CONTRACT" != "1" ]]; then
  RUN_SCALE_IDENTITY_CONTRACT="1"
fi
RUN_SCALE_IDENTITY_HARD_CUTOVER="${RUN_SCALE_IDENTITY_HARD_CUTOVER:-$RUN_SCALE_IDENTITY_CONTRACT}"
if [[ "$RUN_FULL_SCALE_REGRESSION" == "1" && "$RUN_SCALE_IDENTITY_HARD_CUTOVER" != "1" ]]; then
  RUN_SCALE_IDENTITY_HARD_CUTOVER="1"
fi
HARD_CUTOVER_ENV_APPLIED=0
PREV_FAP_SCALE_IDENTITY_WRITE_MODE="${FAP_SCALE_IDENTITY_WRITE_MODE-__UNSET__}"
PREV_FAP_SCALE_IDENTITY_READ_MODE="${FAP_SCALE_IDENTITY_READ_MODE-__UNSET__}"
PREV_FAP_API_RESPONSE_SCALE_CODE_MODE="${FAP_API_RESPONSE_SCALE_CODE_MODE-__UNSET__}"
PREV_FAP_ACCEPT_LEGACY_SCALE_CODE="${FAP_ACCEPT_LEGACY_SCALE_CODE-__UNSET__}"
PREV_FAP_ALLOW_DEMO_SCALES="${FAP_ALLOW_DEMO_SCALES-__UNSET__}"
PREV_FAP_CONTENT_PATH_MODE="${FAP_CONTENT_PATH_MODE-__UNSET__}"
PREV_FAP_CONTENT_PUBLISH_MODE="${FAP_CONTENT_PUBLISH_MODE-__UNSET__}"
if [[ "$RUN_SCALE_IDENTITY_HARD_CUTOVER" == "1" ]]; then
  export FAP_SCALE_IDENTITY_WRITE_MODE="${FAP_SCALE_IDENTITY_WRITE_MODE:-dual}"
  export FAP_SCALE_IDENTITY_READ_MODE="${FAP_SCALE_IDENTITY_READ_MODE:-v2}"
  export FAP_API_RESPONSE_SCALE_CODE_MODE="${FAP_API_RESPONSE_SCALE_CODE_MODE:-v2}"
  export FAP_ACCEPT_LEGACY_SCALE_CODE="${FAP_ACCEPT_LEGACY_SCALE_CODE:-false}"
  export FAP_ALLOW_DEMO_SCALES="${FAP_ALLOW_DEMO_SCALES:-false}"
  export FAP_CONTENT_PATH_MODE="${FAP_CONTENT_PATH_MODE:-dual_prefer_new}"
  export FAP_CONTENT_PUBLISH_MODE="${FAP_CONTENT_PUBLISH_MODE:-dual}"
  HARD_CUTOVER_ENV_APPLIED=1
fi

restore_hard_cutover_env() {
  if [[ "$PREV_FAP_SCALE_IDENTITY_WRITE_MODE" == "__UNSET__" ]]; then unset FAP_SCALE_IDENTITY_WRITE_MODE; else export FAP_SCALE_IDENTITY_WRITE_MODE="$PREV_FAP_SCALE_IDENTITY_WRITE_MODE"; fi
  if [[ "$PREV_FAP_SCALE_IDENTITY_READ_MODE" == "__UNSET__" ]]; then unset FAP_SCALE_IDENTITY_READ_MODE; else export FAP_SCALE_IDENTITY_READ_MODE="$PREV_FAP_SCALE_IDENTITY_READ_MODE"; fi
  if [[ "$PREV_FAP_API_RESPONSE_SCALE_CODE_MODE" == "__UNSET__" ]]; then unset FAP_API_RESPONSE_SCALE_CODE_MODE; else export FAP_API_RESPONSE_SCALE_CODE_MODE="$PREV_FAP_API_RESPONSE_SCALE_CODE_MODE"; fi
  if [[ "$PREV_FAP_ACCEPT_LEGACY_SCALE_CODE" == "__UNSET__" ]]; then unset FAP_ACCEPT_LEGACY_SCALE_CODE; else export FAP_ACCEPT_LEGACY_SCALE_CODE="$PREV_FAP_ACCEPT_LEGACY_SCALE_CODE"; fi
  if [[ "$PREV_FAP_ALLOW_DEMO_SCALES" == "__UNSET__" ]]; then unset FAP_ALLOW_DEMO_SCALES; else export FAP_ALLOW_DEMO_SCALES="$PREV_FAP_ALLOW_DEMO_SCALES"; fi
  if [[ "$PREV_FAP_CONTENT_PATH_MODE" == "__UNSET__" ]]; then unset FAP_CONTENT_PATH_MODE; else export FAP_CONTENT_PATH_MODE="$PREV_FAP_CONTENT_PATH_MODE"; fi
  if [[ "$PREV_FAP_CONTENT_PUBLISH_MODE" == "__UNSET__" ]]; then unset FAP_CONTENT_PUBLISH_MODE; else export FAP_CONTENT_PUBLISH_MODE="$PREV_FAP_CONTENT_PUBLISH_MODE"; fi
}
SCALE_SCOPE="${SCALE_SCOPE:-mbti_only}"
echo "[CI] scale_scope=${SCALE_SCOPE} run_big5_ocean_gate=${RUN_BIG5_OCEAN_GATE} run_clinical_combo_68_gate=${RUN_CLINICAL_COMBO_68_GATE} run_sds_20_gate=${RUN_SDS_20_GATE} run_eq_60_gate=${RUN_EQ_60_GATE} run_sds_norms_gate=${RUN_SDS_NORMS_GATE} run_full_scale_regression=${RUN_FULL_SCALE_REGRESSION} run_scale_identity_gate=${RUN_SCALE_IDENTITY_GATE} run_scale_identity_contract=${RUN_SCALE_IDENTITY_CONTRACT} run_scale_identity_hard_cutover=${RUN_SCALE_IDENTITY_HARD_CUTOVER} run_partner_api_smoke=${RUN_PARTNER_API_SMOKE} run_experiment_governance_gate=${RUN_EXPERIMENT_GOVERNANCE_GATE} run_openapi_diff_gate=${RUN_OPENAPI_DIFF_GATE} openapi_diff_allow_drift=${OPENAPI_DIFF_ALLOW_DRIFT}"
if [[ "$RUN_BIG5_OCEAN_GATE" == "1" ]]; then
  echo "[CI] running BIG5_OCEAN content gates"
  bash "$BACKEND_DIR/scripts/ci/verify_big5_norms.sh"
  php artisan content:lint --pack=BIG5_OCEAN --pack-version=v1
  php artisan content:compile --pack=BIG5_OCEAN --pack-version=v1
  php artisan test --filter BigFive

  echo "[CI] rebuilding sqlite baseline after BIG5_OCEAN gate"
  bash "$BACKEND_DIR/scripts/ci/prepare_sqlite.sh"
  php artisan fap:schema:verify
fi

if [[ "$RUN_CLINICAL_COMBO_68_GATE" == "1" ]]; then
  echo "[CI] running CLINICAL_COMBO_68 content gates"
  RUN_CLINICAL_COMBO_68_GATE=1 bash "$BACKEND_DIR/scripts/ci/verify_clinical_combo_68.sh"

  echo "[CI] rebuilding sqlite baseline after CLINICAL_COMBO_68 gate"
  bash "$BACKEND_DIR/scripts/ci/prepare_sqlite.sh"
  php artisan fap:schema:verify
fi

if [[ "$RUN_SDS_20_GATE" == "1" ]]; then
  echo "[CI] running SDS_20 content gates"
  RUN_SDS_20_GATE=1 bash "$BACKEND_DIR/scripts/ci/verify_sds_20.sh"

  echo "[CI] rebuilding sqlite baseline after SDS_20 gate"
  bash "$BACKEND_DIR/scripts/ci/prepare_sqlite.sh"
  php artisan fap:schema:verify
fi

if [[ "$RUN_EQ_60_GATE" == "1" ]]; then
  echo "[CI] running EQ_60 content gates"
  RUN_EQ_60_GATE=1 bash "$BACKEND_DIR/scripts/ci/verify_eq_60.sh"
  echo "[CI] rebuilding sqlite baseline after EQ_60 gate"
  bash "$BACKEND_DIR/scripts/ci/prepare_sqlite.sh"
  php artisan fap:schema:verify
fi

if [[ "$RUN_SDS_NORMS_GATE" == "1" ]]; then
  echo "[CI] running SDS_20 norms gates"
  RUN_SDS_NORMS_GATE=1 bash "$BACKEND_DIR/scripts/ci/verify_sds_norms.sh"

  echo "[CI] rebuilding sqlite baseline after SDS_20 norms gate"
  bash "$BACKEND_DIR/scripts/ci/prepare_sqlite.sh"
  php artisan fap:schema:verify
fi

if [[ "$RUN_SCALE_IDENTITY_GATE" == "1" ]]; then
  echo "[CI] running scale identity gate"
  bash "$BACKEND_DIR/scripts/prA_gate_scale_identity.sh"

  echo "[CI] rebuilding sqlite baseline after scale identity gate"
  bash "$BACKEND_DIR/scripts/ci/prepare_sqlite.sh"
  php artisan fap:schema:verify
fi

if [[ "$RUN_PARTNER_API_SMOKE" == "1" ]]; then
  echo "[CI] running Partner API smoke test"
  php artisan test --filter PartnerApiMvpTest
fi

if [[ "$RUN_EXPERIMENT_GOVERNANCE_GATE" == "1" ]]; then
  echo "[CI] running experiment governance gate"
  php artisan test --filter ExperimentGuardrailAutoRollbackTest
fi

# Ensure MBTI commercial benefit codes exist for report/share entitlement gate.
BACKEND_DIR="$BACKEND_DIR" php -r '
$backendDir = rtrim((string) getenv("BACKEND_DIR"), "/");
require $backendDir . "/vendor/autoload.php";
$app = require $backendDir . "/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

if (!Illuminate\Support\Facades\Schema::hasTable("scales_registry")) {
    exit(0);
}

$row = Illuminate\Support\Facades\DB::table("scales_registry")
    ->where("org_id", 0)
    ->where("code", "MBTI")
    ->first();

if (!$row) {
    exit(0);
}

$commercial = $row->commercial_json ?? null;
if (is_string($commercial)) {
    $decoded = json_decode($commercial, true);
    $commercial = is_array($decoded) ? $decoded : [];
}
if (!is_array($commercial)) {
    $commercial = [];
}

if (trim((string) ($commercial["report_benefit_code"] ?? "")) === "") {
    $commercial["report_benefit_code"] = "MBTI_REPORT_FULL";
}
if (trim((string) ($commercial["credit_benefit_code"] ?? "")) === "") {
    $commercial["credit_benefit_code"] = "MBTI_CREDIT";
}

Illuminate\Support\Facades\DB::table("scales_registry")
    ->where("org_id", 0)
    ->where("code", "MBTI")
    ->update([
        "commercial_json" => json_encode($commercial, JSON_UNESCAPED_UNICODE),
        "updated_at" => now(),
    ]);
'
# --- end CI hard defaults ---

# -----------------------------
# Defaults (override via env)
# -----------------------------
HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-1827}"                       # ✅ avoid 8000 collisions by default
API="http://${HOST}:${PORT}"

REGION="${REGION:-CN_MAINLAND}"
LOCALE="${LOCALE:-zh-CN}"
VERIFY_ANON_ID="${VERIFY_ANON_ID:-ci_verify}"
export ANON_ID="${ANON_ID:-$VERIFY_ANON_ID}"

# Your stable pack identifiers
PACK_ID="${PACK_ID:-MBTI.cn-mainland.zh-CN.v0.3}"
LEGACY_DIR="${LEGACY_DIR:-MBTI-CN-v0.3}"

# Artifacts
RUN_DIR="${RUN_DIR:-$BACKEND_DIR/artifacts/verify_mbti}"
ARTIFACT_DIR="$RUN_DIR"                    # alias (for docs/consistency)
LOG_DIR="$RUN_DIR/logs"
mkdir -p "$LOG_DIR"

SERVE_LOG="$LOG_DIR/artisan_serve.log"
SELF_CHECK_LOG="$LOG_DIR/self_check.log"
SMOKE_Q_LOG="$LOG_DIR/smoke_questions.json"
MVP_LOG="$LOG_DIR/mvp_check.log"
QUEUE_BACKLOG_PROBE_LOG="$LOG_DIR/queue_backlog_probe.json"
QUEUE_BACKLOG_PROBE_ERR_LOG="$LOG_DIR/queue_backlog_probe.err.log"

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
SCALE_IDENTITY_CONTRACT_SH="$BACKEND_DIR/scripts/ci/verify_scale_identity_contract.sh"
SCALE_IDENTITY_HARD_CUTOVER_SH="$BACKEND_DIR/scripts/ci/verify_scale_identity_hard_cutover.sh"
AUTH_GUEST_CONTRACT_SH="$BACKEND_DIR/scripts/verify_auth_guest_contract.sh"

if [[ "$ACCEPT_ORDER" == "1" ]]; then
  if [[ ! -f "$ACCEPT_ORDER_SH" ]]; then
    echo "[CI][FAIL] missing: $ACCEPT_ORDER_SH" >&2
    exit 14
  fi
fi

if [[ ! -x "$AUTH_GUEST_CONTRACT_SH" ]]; then
  echo "[CI][FAIL] missing or not executable: $AUTH_GUEST_CONTRACT_SH" >&2
  exit 14
fi

if [[ "$RUN_SCALE_IDENTITY_HARD_CUTOVER" == "1" ]]; then
  if [[ ! -x "$SCALE_IDENTITY_HARD_CUTOVER_SH" ]]; then
    echo "[CI][FAIL] missing or not executable: $SCALE_IDENTITY_HARD_CUTOVER_SH" >&2
    exit 14
  fi
  if [[ ! -x "$SCALE_IDENTITY_CONTRACT_SH" ]]; then
    echo "[CI][FAIL] missing or not executable: $SCALE_IDENTITY_CONTRACT_SH" >&2
    exit 14
  fi
elif [[ "$RUN_SCALE_IDENTITY_CONTRACT" == "1" ]]; then
  if [[ ! -x "$SCALE_IDENTITY_CONTRACT_SH" ]]; then
    echo "[CI][FAIL] missing or not executable: $SCALE_IDENTITY_CONTRACT_SH" >&2
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
#   ../content_packages/MBTI-CN-v0.3 -> default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3
# -----------------------------
CONTENT_ROOT="$REPO_DIR/content_packages"
CANON_REL="default/${REGION}/${LOCALE}/${LEGACY_DIR}"
CANON_ABS="$CONTENT_ROOT/$CANON_REL"
ALIAS_ABS="$CONTENT_ROOT/$LEGACY_DIR"

cleanup_legacy_alias() {
  if [[ -L "$ALIAS_ABS" ]]; then
    echo "[CI] cleanup legacy alias: $ALIAS_ABS"
    rm -f "$ALIAS_ABS"
  fi
}

cleanup_env_file() {
  if [[ "${ENV_CREATED:-0}" == "1" && -f ".env" ]]; then
    echo "[CI] cleanup temp env: $BACKEND_DIR/.env"
    rm -f ".env"
  fi
}

cleanup() {
  local ec=$?
  trap - EXIT INT TERM

  stop_server_if_running

  cleanup_legacy_alias
  cleanup_env_file
  exit "$ec"
}
trap cleanup EXIT INT TERM

stop_server_if_running() {
  if [[ -n "${SERVE_PID:-}" ]]; then
    kill "$SERVE_PID" >/dev/null 2>&1 || true
    wait "$SERVE_PID" 2>/dev/null || true
    SERVE_PID=""
  fi
}

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
role_type_codes="$(php -r '
$codes = [];
foreach (glob($argv[1]."/*.json") as $f) {
  $j = json_decode(file_get_contents($f), true);
  if (!is_array($j)) { continue; }
  $tc = $j["type_code"] ?? null;
  if (is_string($tc) && $tc !== "") { $codes[] = $tc; }
  $tcs = $j["type_codes"] ?? null;
  if (is_array($tcs)) {
    foreach ($tcs as $c) {
      if (is_string($c) && $c !== "") { $codes[] = $c; }
    }
  }
}
$codes = array_values(array_unique($codes));
sort($codes, SORT_STRING);
echo implode("\n", $codes);
' "$ROLE_DIR")"

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
role_sample_json="$(php -r '
$ids = [];
foreach (glob($argv[1]."/*.json") as $f) {
  $j = json_decode(file_get_contents($f), true);
  if (!is_array($j)) { continue; }
  $id = $j["id"] ?? null;
  if (is_string($id) && $id !== "") { $ids[] = $id; }
}
$ids = array_values(array_unique($ids));
sort($ids, SORT_STRING);
$ids = array_slice($ids, 0, 5);
echo json_encode($ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
' "$ROLE_DIR")"
strategy_sample_json="$(php -r '
$ids = [];
foreach (glob($argv[1]."/*.json") as $f) {
  $j = json_decode(file_get_contents($f), true);
  if (!is_array($j)) { continue; }
  $id = $j["id"] ?? null;
  if (is_string($id) && $id !== "") { $ids[] = $id; }
}
$ids = array_values(array_unique($ids));
sort($ids, SORT_STRING);
$ids = array_slice($ids, 0, 5);
echo json_encode($ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
' "$STRATEGY_DIR")"
read_sample_json="$(php -r '
$ids = [];
foreach (glob($argv[1]."/*.json") as $f) {
  $j = json_decode(file_get_contents($f), true);
  if (!is_array($j)) { continue; }
  $id = $j["id"] ?? null;
  if (is_string($id) && $id !== "") { $ids[] = $id; }
}
$ids = array_values(array_unique($ids));
sort($ids, SORT_STRING);
$ids = array_slice($ids, 0, 8);
echo json_encode($ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
' "$READS_DIR")"
missing_json="$(printf '%s' "$missing_lines" | php -r '
$lines = file("php://stdin", FILE_IGNORE_NEW_LINES);
$lines = array_values(array_filter($lines, "strlen"));
echo json_encode($lines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
')"

php -r '
$role_count = (int) $argv[1];
$strategy_count = (int) $argv[2];
$reads_count = (int) $argv[3];
$missing = json_decode($argv[4], true);
$role_sample = json_decode($argv[5], true);
$strategy_sample = json_decode($argv[6], true);
$read_sample = json_decode($argv[7], true);
if (!is_array($missing)) { $missing = []; }
if (!is_array($role_sample)) { $role_sample = []; }
if (!is_array($strategy_sample)) { $strategy_sample = []; }
if (!is_array($read_sample)) { $read_sample = []; }
$out = [
  "counts" => [
    "role_cards" => $role_count,
    "strategy_cards" => $strategy_count,
    "reads" => $reads_count,
  ],
  "missing" => [
    "role_cards" => $missing,
  ],
  "sample" => [
    "role_cards" => $role_sample,
    "strategy_cards" => $strategy_sample,
    "reads" => $read_sample,
  ],
];
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
' "$role_count" "$strategy_count" "$reads_count" "$missing_json" "$role_sample_json" "$strategy_sample_json" "$read_sample_json" >"$INVENTORY_JSON"

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
# Start server (force clean port first)
# -----------------------------
lsof -ti "tcp:${PORT}" | xargs -r kill -9 || true
if lsof -ti "tcp:${PORT}" >/dev/null 2>&1; then
  fail "port already in use: ${PORT}. Stop your local server or set PORT=18xxx"
fi

echo "[CI] starting server: php artisan serve --host=$HOST --port=$PORT"
php artisan serve --host="$HOST" --port="$PORT" >"$SERVE_LOG" 2>&1 &
SERVE_PID=$!

echo "[CI] waiting for health: $API/api/healthz"
wait_health "$API/api/healthz" 100 || {
  echo "[CI][FAIL] server not ready"
  echo "---- tail artisan_serve.log ----" >&2
  tail -n 120 "$SERVE_LOG" >&2 || true
  exit 11
}
echo "[CI] server ready (pid=$SERVE_PID)"

# -----------------------------
# Self-check: manifest/assets/schema
# -----------------------------
SELF_CHECK_PKG="${SELF_CHECK_PKG:-$CANON_REL}"
echo "[CI] fap:self-check (manifest/assets/schema) pkg=$SELF_CHECK_PKG"
php artisan fap:self-check --pkg="$SELF_CHECK_PKG" >"$SELF_CHECK_LOG" 2>&1 || {
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
SMOKE_SCALE_CODE="${SMOKE_SCALE_CODE:-MBTI}"
if [[ "$RUN_SCALE_IDENTITY_HARD_CUTOVER" == "1" ]]; then
  SMOKE_SCALE_CODE="MBTI_PERSONALITY_TEST_16_TYPES"
fi

echo "[CI] smoke: /api/v0.3/scales/${SMOKE_SCALE_CODE}/questions"
echo "[CI] smoke precheck db_connection=${DB_CONNECTION} db_database=${DB_DATABASE}"
if ! APP_ENV="$APP_ENV" DB_CONNECTION="$DB_CONNECTION" DB_DATABASE="$DB_DATABASE" BACKEND_DIR="$BACKEND_DIR" php -r '
$backendDir = rtrim((string) getenv("BACKEND_DIR"), "/");
require $backendDir . "/vendor/autoload.php";
$app = require $backendDir . "/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$defaultConnection = (string) config("database.default", "");
$resolvedDatabase = (string) config("database.connections.".$defaultConnection.".database", "");
$resolvedPath = $resolvedDatabase !== "" ? realpath($resolvedDatabase) : false;
$displayPath = is_string($resolvedPath) && $resolvedPath !== "" ? $resolvedPath : $resolvedDatabase;

if (!Illuminate\Support\Facades\Schema::hasTable("scales_registry")) {
    fwrite(STDERR, "scales_registry table missing before MBTI smoke (conn={$defaultConnection}, db={$displayPath})\n");
    exit(2);
}

$exists = Illuminate\Support\Facades\DB::table("scales_registry")
    ->where("org_id", 0)
    ->where("code", "MBTI")
    ->exists();

if (!$exists) {
    fwrite(STDERR, "MBTI scale row missing in scales_registry before MBTI smoke (conn={$defaultConnection}, db={$displayPath})\n");
    exit(3);
}
'; then
  echo "[CI][FAIL] MBTI scale registry baseline missing before smoke"
  exit 13
fi

SMOKE_HTTP_CODE=""
for attempt in 1 2 3; do
  SMOKE_HTTP_CODE="$(curl -sS -o "$SMOKE_Q_LOG" -w "%{http_code}" "$API/api/v0.3/scales/${SMOKE_SCALE_CODE}/questions" || true)"
  if [[ "$SMOKE_HTTP_CODE" == "200" ]]; then
    break
  fi

  if [[ "$attempt" -lt 3 ]]; then
    echo "[CI][WARN] smoke attempt ${attempt} failed http=${SMOKE_HTTP_CODE}, retrying..."
    sleep 1
  fi
done

if [[ "$SMOKE_HTTP_CODE" != "200" ]]; then
  echo "[CI][FAIL] smoke curl failed http=${SMOKE_HTTP_CODE}"
  head -c 1200 "$SMOKE_Q_LOG" || true
  echo
  tail -n 120 "$SERVE_LOG" >&2 || true
  exit 13
fi

if ! php -r '$j=json_decode(file_get_contents($argv[1]), true); if (!is_array($j) || !($j["ok"] ?? false)) { exit(1); }' "$SMOKE_Q_LOG" >/dev/null 2>&1; then
  echo "[CI][FAIL] smoke returned ok=false. body:"
  head -c 1200 "$SMOKE_Q_LOG" || true
  echo
  echo "[CI] tail artisan_serve.log:"
  tail -n 200 "$SERVE_LOG" || true
  exit 13
fi
echo "[CI] smoke OK"

if [[ "$RUN_SCALE_IDENTITY_HARD_CUTOVER" == "1" ]]; then
  echo "[CI] running scale identity hard-cutover contract gate (full regression)"
  API="$API" REGION="$REGION" LOCALE="$LOCALE" bash "$SCALE_IDENTITY_HARD_CUTOVER_SH"
  echo "[CI] scale identity hard-cutover contract gate OK"
elif [[ "$RUN_SCALE_IDENTITY_CONTRACT" == "1" ]]; then
  echo "[CI] running scale identity contract gate"
  API="$API" REGION="$REGION" LOCALE="$LOCALE" bash "$SCALE_IDENTITY_CONTRACT_SH"
  echo "[CI] scale identity contract gate OK"
fi

# -----------------------------
# Run E2E verify
# -----------------------------
echo "[CI] get fm_token for gated endpoints"
echo "[CI] verify anon_id=$ANON_ID"
FM_TOKEN="$(
  curl -sS -X POST "$API/api/v0.3/auth/wx_phone" \
    -H "Content-Type: application/json" \
    -d "{\"wx_code\":\"dev\",\"phone_code\":\"dev\",\"anon_id\":\"${ANON_ID}\"}" \
  | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j["token"] ?? "";'
)"

if [[ -z "$FM_TOKEN" || "$FM_TOKEN" == "null" ]]; then
  echo "[CI][FAIL] cannot get token from /api/v0.3/auth/wx_phone" >&2
  exit 15
fi

set_curl_auth

VERIFY_MBTI_SCALE_CODE="${VERIFY_MBTI_SCALE_CODE:-MBTI}"
VERIFY_MBTI_EXPECT_PACK_PREFIX="${VERIFY_MBTI_EXPECT_PACK_PREFIX:-MBTI.cn-mainland.zh-CN.}"
if [[ "$RUN_SCALE_IDENTITY_HARD_CUTOVER" == "1" ]]; then
  VERIFY_MBTI_SCALE_CODE="MBTI_PERSONALITY_TEST_16_TYPES"
fi

echo "[CI] running verify_mbti.sh (with FM_TOKEN) scale_code=${VERIFY_MBTI_SCALE_CODE}"
API="$API" REGION="$REGION" LOCALE="$LOCALE" SCALE_CODE="$VERIFY_MBTI_SCALE_CODE" EXPECT_PACK_PREFIX="$VERIFY_MBTI_EXPECT_PACK_PREFIX" RUN_DIR="$RUN_DIR" ANON_ID="$ANON_ID" FM_TOKEN="$FM_TOKEN" \
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
REPORT_URL="$API/api/v0.3/attempts/$ATTEMPT_ID/report?anon_id=$ANON_ID"

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

  rr_count=""
  for f in "$RR_REPORT_1" "$RR_REPORT_2"; do
    out="$RR_LIST_2"
    if [[ "$f" == "$RR_REPORT_1" ]]; then
      out="$RR_LIST_1"
    fi
    set +e
    rr_out="$(php -r '
$path = $argv[1];
$out = $argv[2];
$j = json_decode(file_get_contents($path), true);
if (!is_array($j)) { exit(2); }
$rr = $j["report"]["recommended_reads"] ?? null;
if (!is_array($rr)) { exit(3); }
$len = count($rr);
if ($len < 3 || $len > 6) { echo $len; exit(4); }
$list = [];
foreach ($rr as $item) {
  if (!is_array($item)) { continue; }
  $list[] = [
    "id" => $item["id"] ?? null,
    "type" => $item["type"] ?? null,
    "slug" => $item["slug"] ?? null,
    "why" => $item["why"] ?? null,
    "show_order" => $item["show_order"] ?? null,
  ];
}
file_put_contents($out, json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo $len;
' "$f" "$out")"
    php_ec=$?
    set -e
    if [[ "$php_ec" -ne 0 ]]; then
      if [[ "$php_ec" -eq 3 ]]; then
        fail "report.recommended_reads missing or not array (CONTENT_GRAPH_ENABLED=1). file=$f"
      fi
      if [[ "$php_ec" -eq 4 ]]; then
        fail "report.recommended_reads count out of range: $rr_out (expect 3-6). file=$f"
      fi
      fail "report.recommended_reads parse failed (CONTENT_GRAPH_ENABLED=1). file=$f"
    fi
    rr_count="$rr_out"
  done

  php -r '
$a = json_decode(file_get_contents($argv[1]), true);
$b = json_decode(file_get_contents($argv[2]), true);
if (!is_array($a)) { $a = []; }
if (!is_array($b)) { $b = []; }
echo json_encode(["first" => $a, "second" => $b], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
' "$RR_LIST_1" "$RR_LIST_2" >"$RR_COMPARE"

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

  set +e
  rr_meta="$(php -r '
$path = $argv[1];
$out = $argv[2];
$j = json_decode(file_get_contents($path), true);
if (!is_array($j)) { exit(2); }
$report = $j["report"] ?? [];
$has = is_array($report) && array_key_exists("recommended_reads", $report);
$rr = is_array($report) ? ($report["recommended_reads"] ?? null) : null;
if ($rr !== null && !is_array($rr)) { exit(3); }
if (is_array($rr) && count($rr) > 0) { exit(4); }
$len = is_array($rr) ? count($rr) : 0;
$payload = ["has_recommended_reads" => $has, "recommended_reads_length" => $len];
file_put_contents($out, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo ($has ? "1" : "0") . ":" . $len;
' "$REPORT_JSON" "$RR_ROLLBACK")"
  php_ec=$?
  set -e
  if [[ "$php_ec" -ne 0 ]]; then
    if [[ "$php_ec" -eq 3 ]]; then
      fail "report.recommended_reads present but not array (CONTENT_GRAPH_ENABLED=0). file=$REPORT_JSON"
    fi
    if [[ "$php_ec" -eq 4 ]]; then
      fail "report.recommended_reads should be empty or missing when CONTENT_GRAPH_ENABLED=0. file=$REPORT_JSON"
    fi
    fail "report.recommended_reads parse failed (CONTENT_GRAPH_ENABLED=0). file=$REPORT_JSON"
  fi

  rr_len="${rr_meta#*:}"
  echo "[CI] content_graph rollback OK (recommended_reads length=$rr_len)"
fi

# -----------------------------
# Events acceptance (M3)
# -----------------------------
echo "[CI] events acceptance (M3)"

# Ensure sqlite path is passed to acceptance scripts (keep consistent with CI env)
SQLITE_DB_FOR_ACCEPT="$DB_DATABASE"

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

API="$API" SQLITE_DB="$SQLITE_DB_FOR_ACCEPT" ANON_ID="$ANON_ID" FM_TOKEN="$FM_TOKEN" \
  "$SCRIPT_DIR/accept_events_C.sh" >/dev/null

API="$API" SQLITE_DB="$SQLITE_DB_FOR_ACCEPT" FM_TOKEN="$FM_TOKEN" \
  "$SCRIPT_DIR/accept_events_F_result_view_meta.sh"

API="$API" SQLITE_DB="$SQLITE_DB_FOR_ACCEPT" FM_TOKEN="$FM_TOKEN" \
  "$SCRIPT_DIR/accept_events_G_report_view_meta.sh"

API="$API" SQLITE_DB="$SQLITE_DB_FOR_ACCEPT" FM_TOKEN="$FM_TOKEN" \
  SKIP_C=1 "$SCRIPT_DIR/accept_events_E_share_meta.sh"

API="$API" SQLITE_DB="$SQLITE_DB_FOR_ACCEPT" FM_TOKEN="$FM_TOKEN" \
  "$SCRIPT_DIR/accept_events_H_share_view_meta.sh"

API="$API" SQLITE_DB="$SQLITE_DB_FOR_ACCEPT" FM_TOKEN="$FM_TOKEN" \
  "$SCRIPT_DIR/accept_events_D_anon.sh"

API="$API" SQLITE_DB="$SQLITE_DB_FOR_ACCEPT" FM_TOKEN="$FM_TOKEN" \
  "$SCRIPT_DIR/accept_events_D_click_anon_override.sh"

API="$API" SQLITE_DB="$SQLITE_DB_FOR_ACCEPT" FM_TOKEN="$FM_TOKEN" \
  "$SCRIPT_DIR/accept_events_D_anon_block_placeholder_click.sh"

echo "[CI] events acceptance OK"

echo "[CI] stopping API server before phpunit gates"
stop_server_if_running
if [[ "$HARD_CUTOVER_ENV_APPLIED" == "1" ]]; then
  echo "[CI] restoring scale identity env for phpunit gates"
  restore_hard_cutover_env
fi

echo "[CI] payment event provider uniqueness gate"
php artisan test --filter PaymentEventUniquenessAcrossProvidersTest
echo "[CI] payment event provider uniqueness gate OK"

echo "[CI] migration safety gates"
php artisan test --filter MigrationSafetyTest
php artisan test --filter MigrationRollbackSafetyTest
php artisan test --filter MigrationsNoSilentCatchTest
php artisan test --filter MigrationProtectedTablesNoDropTest
echo "[CI] migration safety gates OK"

echo "[CI] auth guest contract gate"
bash scripts/verify_auth_guest_contract.sh
echo "[CI] auth guest contract gate OK"

if [[ "$RUN_OPENAPI_DIFF_GATE" == "1" ]]; then
  echo "[CI] openapi diff gate"

  if [[ "$OPENAPI_DIFF_ALLOW_DRIFT" == "1" ]]; then
    if bash scripts/export_openapi.sh --check; then
      echo "[CI] openapi diff snapshot check OK (override mode)"
    else
      echo "[CI][WARN] openapi drift detected but OPENAPI_DIFF_ALLOW_DRIFT=1 (non-blocking)"
    fi
    OPENAPI_DIFF_ALLOW_DRIFT=1 php artisan test --filter OpenApiDiffTest
  else
    bash scripts/export_openapi.sh --check
    OPENAPI_DIFF_ALLOW_DRIFT=0 php artisan test --filter OpenApiDiffTest
  fi

  echo "[CI] openapi diff gate OK"
else
  echo "[CI] openapi diff gate skipped (RUN_OPENAPI_DIFF_GATE=0)"
fi

echo "[CI] order security gate"
bash scripts/verify_order_security.sh
echo "[CI] order security gate OK"

echo "[CI] psychometrics streaming gate"
bash scripts/pr70_verify.sh
echo "[CI] psychometrics streaming gate OK"

echo "[CI] migration static guard gate"
bash scripts/pr71_verify.sh
echo "[CI] migration static guard gate OK"

echo "[CI] schema baseline runtime introspection gate"
php artisan test --filter SchemaBaselineRuntimeIntrospectionTest
echo "[CI] schema baseline runtime introspection gate OK"

echo "[CI] big5 ops controller layering gate"
bash scripts/pr72_verify.sh
echo "[CI] big5 ops controller layering gate OK"

echo "[CI] legacy service request coupling gate"
bash scripts/pr73_verify.sh
echo "[CI] legacy service request coupling gate OK"

echo "[CI] constructor injection limit gate"
bash scripts/pr74_verify.sh
echo "[CI] constructor injection limit gate OK"

echo "[CI] app env() usage gate"
bash scripts/pr75_verify.sh
echo "[CI] app env() usage gate OK"

echo "[CI] events dedupe explain gate"
bash scripts/pr76_verify.sh
echo "[CI] events dedupe explain gate OK"

echo "[CI] share flow alignment gate"
bash scripts/pr77_verify.sh
echo "[CI] share flow alignment gate OK"

echo "[CI] dependency security gate"
bash scripts/pr78_verify.sh
echo "[CI] dependency security gate OK"

if [[ "$RUN_SCALE_IDENTITY_HARD_CUTOVER" == "1" ]]; then
  echo "[CI] hard-cutover phpunit gate"
  php artisan test tests/Feature/Ops/ScaleIdentityHardCutoverCiTest.php
  echo "[CI] hard-cutover phpunit gate OK"
fi

echo "[CI] webhook/attempt regression gates"
php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml tests/Feature/V0_3/PaymentWebhookControllerTest.php
php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml tests/Feature/V0_3/AttemptOwnershipAnd404Test.php
php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml tests/Feature/V0_3/PaymentWebhookRouteWiringTest.php
php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml tests/Feature/Architecture/V0_3TraitReferenceTest.php
echo "[CI] webhook/attempt regression gates OK"

QUEUE_BACKLOG_PROBE_STRICT="${QUEUE_BACKLOG_PROBE_STRICT:-1}"
if [[ "$QUEUE_BACKLOG_PROBE_STRICT" == "1" ]]; then
  echo "[CI] queue backlog probe (blocking strict=1)"
  php artisan ops:queue-backlog-probe --json=1 --strict="$QUEUE_BACKLOG_PROBE_STRICT" >"$QUEUE_BACKLOG_PROBE_LOG" 2>"$QUEUE_BACKLOG_PROBE_ERR_LOG"
  echo "[CI] queue backlog probe artifact=$QUEUE_BACKLOG_PROBE_LOG"
else
  echo "[CI] queue backlog probe (non-blocking strict=0)"
  if php artisan ops:queue-backlog-probe --json=1 --strict="$QUEUE_BACKLOG_PROBE_STRICT" >"$QUEUE_BACKLOG_PROBE_LOG" 2>"$QUEUE_BACKLOG_PROBE_ERR_LOG"; then
    echo "[CI] queue backlog probe artifact=$QUEUE_BACKLOG_PROBE_LOG"
  else
    echo "[CI][WARN] queue backlog probe failed (non-blocking), see $QUEUE_BACKLOG_PROBE_ERR_LOG"
  fi
fi
