#!/usr/bin/env bash
set -euo pipefail

# -----------------------------
# Auth (fm_token) for gated endpoints
# -----------------------------
CURL_AUTH=()
if [[ -n "${FM_TOKEN:-}" ]]; then
  CURL_AUTH=(-H "Authorization: Bearer ${FM_TOKEN}")
fi

# -----------------------------
# Config / Defaults
# -----------------------------
API="${API:-http://127.0.0.1:1827}"
SCALE_CODE="${SCALE_CODE:-MBTI}"
SCALE_VERSION="${SCALE_VERSION:-v0.2}"
ANSWER_CODE="${ANSWER_CODE:-C}"
REGION="${REGION:-CN_MAINLAND}"
LOCALE="${LOCALE:-zh-CN}"
ATTEMPT_ANON_ID="${ATTEMPT_ANON_ID:-}"
ANON_ID="${ANON_ID:-}"

# 你原本用 pack prefix 断言；保留
EXPECT_PACK_PREFIX="${EXPECT_PACK_PREFIX:-MBTI.cn-mainland.zh-CN.}"

# 3连验收相关参数
VERIFY_MODE="${VERIFY_MODE:-local}"                         # local|server|ci（此脚本是 HTTP E2E，ci 也可以用，只要 CI 起了服务）
STRICT="${STRICT:-1}"                         # 1=禁止出现 deprecated/GLOBAL/en 等信号
MIN_HL="${MIN_HL:-3}"                         # highlights 数量下限
MAX_HL="${MAX_HL:-4}"                         # highlights 数量上限

# Prefer RUN_DIR; fallback to WORKDIR; default to backend/artifacts/verify_mbti
RUN_DIR="${RUN_DIR:-}"
WORKDIR="${WORKDIR:-}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

if [[ -z "$RUN_DIR" ]]; then
  if [[ -n "$WORKDIR" ]]; then
    RUN_DIR="$WORKDIR"
  else
    RUN_DIR="$BACKEND_DIR/artifacts/verify_mbti"
  fi
fi

if [[ -z "${ANON_ID}" ]]; then
  ANON_ID="local_verify_$(date +%s)"
fi
if [[ -z "${ATTEMPT_ANON_ID}" ]]; then
  ATTEMPT_ANON_ID="${ANON_ID}"
fi
CURL_OWNER=(-H "X-Anon-Id: ${ANON_ID}")

LOG_DIR="$RUN_DIR/logs"
mkdir -p "$LOG_DIR"

HEALTH_JSON="$RUN_DIR/health.json"
QUESTIONS_JSON="$RUN_DIR/questions.json"
PAYLOAD_JSON="$RUN_DIR/payload.json"
ATTEMPT_RESP_JSON="$RUN_DIR/attempt.json"
REPORT_JSON="$RUN_DIR/report.json"
SHARE_JSON="$RUN_DIR/share.json"
ATTEMPT_ID_TXT="$RUN_DIR/attempt_id.txt"
ANON_ID_TXT="$RUN_DIR/anon_id.txt"
SUMMARY_TXT="$RUN_DIR/summary.txt"

OVR_LOG="$LOG_DIR/overrides_accept_D.log"

echo "[ARTIFACTS] $RUN_DIR"

# -----------------------------
# Assertions lib (shared helpers)
# -----------------------------
ASSERT_LIB="$SCRIPT_DIR/verify_mbti_assert.sh"
if [[ ! -f "$ASSERT_LIB" ]]; then
  echo "[FAIL] missing assertion lib: $ASSERT_LIB" >&2
  exit 2
fi
# shellcheck disable=SC1090
source "$ASSERT_LIB"

# -----------------------------
# Preconditions
# -----------------------------
require_cmd curl
require_cmd php

# -----------------------------
# Exit trap
# -----------------------------
cleanup_on_exit() {
  local exit_code=$?

  if [[ $exit_code -ne 0 ]]; then
    echo "[FAIL] verify_mbti exited with code=$exit_code" >&2
    echo "[FAIL] artifacts kept at: $RUN_DIR" >&2

    if [[ -f "$ATTEMPT_RESP_JSON" ]]; then
      echo "---- attempt response (first 800 bytes) ----" >&2
      head -c 800 "$ATTEMPT_RESP_JSON" 2>/dev/null || true
      echo >&2
    fi
    if [[ -f "$REPORT_JSON" ]]; then
      echo "---- report (first 400 bytes) ----" >&2
      head -c 400 "$REPORT_JSON" 2>/dev/null || true
      echo >&2
    fi
    if [[ -f "$SHARE_JSON" ]]; then
      echo "---- share (first 400 bytes) ----" >&2
      head -c 400 "$SHARE_JSON" 2>/dev/null || true
      echo >&2
    fi
    if [[ -f "$OVR_LOG" ]]; then
      echo "---- overrides log (tail 120 lines) ----" >&2
      tail -n 120 "$OVR_LOG" 2>/dev/null || true
      echo >&2
    fi
  fi

  trap - EXIT
  exit $exit_code
}
trap cleanup_on_exit EXIT

# -----------------------------
# Helpers
# -----------------------------
fetch_json() {
  local url="$1"
  local out="$2"
  local need_auth="${3:-0}"  # 0|1

  local http
  if [[ "$need_auth" == "1" ]]; then
    http="$(curl -sS -L -o "$out" -w "%{http_code}" "${CURL_AUTH[@]}" "${CURL_OWNER[@]}" "$url" || true)"
  else
    http="$(curl -sS -L -o "$out" -w "%{http_code}" "${CURL_OWNER[@]}" "$url" || true)"
  fi

  if [[ -z "${http:-}" ]]; then
    echo "[CURL][FAIL] no http code (curl error). url=$url" >&2
    return 2
  fi

  if [[ "$http" != "200" && "$http" != "201" ]]; then
    echo "[CURL][FAIL] HTTP=$http url=$url" >&2
    echo "---- body (first 800 bytes) ----" >&2
    head -c 800 "$out" 2>/dev/null || true
    echo >&2
    return 3
  fi

  if [[ ! -s "$out" ]]; then
    echo "[CURL][FAIL] empty body. url=$url" >&2
    return 4
  fi

  return 0
}

fail() { echo "[FAIL] $*" >&2; exit 1; }

assert_file_not_contains() {
  local file="$1" needle="$2" ctx="${3:-}"
  [[ -f "$file" ]] || return 0
  if grep -Fq -- "$needle" "$file"; then
    fail "${ctx:+$ctx: }forbidden string found: $needle (file=$file)"
  fi
}

assert_file_contains() {
  local file="$1" needle="$2" ctx="${3:-}"
  [[ -f "$file" ]] || fail "${ctx:+$ctx: }file not found: $file"
  grep -Fq -- "$needle" "$file" || fail "${ctx:+$ctx: }expected string missing: $needle (file=$file)"
}

strict_negative_signals() {
  local file="$1" ctx="$2"
  [[ "${STRICT}" == "1" ]] || return 0

  # 你要防的两类：deprecated链路 & GLOBAL/en
  assert_file_not_contains "$file" "content_packages/_deprecated" "$ctx"
  assert_file_not_contains "$file" "GLOBAL/en" "$ctx"
  assert_file_not_contains "$file" "fallback to GLOBAL" "$ctx"
}

# find highlights array path (兼容不同 report schema)
pick_highlights_path() {
  local f="$1"
  FILE="$f" php -r '
$file=getenv("FILE");
$data=json_decode(file_get_contents($file), true);
if (!is_array($data)) { exit(1); }
$candidates = [
  ["highlights","items"],
  ["highlights"],
  ["report","highlights","items"],
  ["report","highlights"],
  ["data","highlights","items"],
  ["data","highlights"],
];
foreach ($candidates as $path) {
  $cur=$data;
  $ok=true;
  foreach ($path as $seg) {
    if (is_array($cur) && array_key_exists($seg, $cur)) {
      $cur=$cur[$seg];
      continue;
    }
    $ok=false;
    break;
  }
  if ($ok && is_array($cur)) {
    echo implode(".", $path);
    exit(0);
  }
}
exit(1);
'
}

# -----------------------------
# Phase 0: health & questions & payload & attempt & report
# -----------------------------
echo "[1/8] health: $API"
fetch_json "$API/api/v0.2/health" "$HEALTH_JSON"
HEALTH_JSON="$HEALTH_JSON" php -r '
$j=json_decode(file_get_contents(getenv("HEALTH_JSON")), true);
if (!is_array($j) || ($j["ok"] ?? false) !== true) {
  fwrite(STDERR, "[FAIL] health not ok\n");
  exit(1);
}
echo "[OK] health: " . ($j["service"] ?? "") . " " . ($j["version"] ?? "") . " " . ($j["time"] ?? "") . PHP_EOL;
'

echo "[2/8] fetch questions"
fetch_json "$API/api/v0.2/scales/$SCALE_CODE/questions" "$QUESTIONS_JSON"
QUESTIONS_JSON="$QUESTIONS_JSON" php -r '
$j=json_decode(file_get_contents(getenv("QUESTIONS_JSON")), true);
if (!is_array($j) || ($j["ok"] ?? false) !== true) {
  fwrite(STDERR, "[FAIL] questions not ok\n");
  exit(1);
}
$items=$j["items"] ?? [];
$cnt=is_array($items) ? count($items) : 0;
if ($cnt <= 0) { fwrite(STDERR, "[FAIL] no items\n"); exit(1); }
echo "[OK] questions count=" . $cnt . PHP_EOL;
'

echo "[3/8] build payload ($ANSWER_CODE for all)"
QUESTIONS_JSON="$QUESTIONS_JSON" PAYLOAD_JSON="$PAYLOAD_JSON" ANSWER_CODE="$ANSWER_CODE" SCALE_CODE="$SCALE_CODE" SCALE_VERSION="$SCALE_VERSION" REGION="$REGION" LOCALE="$LOCALE" ANON_ID="$ANON_ID" php -r '
$j=json_decode(file_get_contents(getenv("QUESTIONS_JSON")), true);
$items=$j["items"] ?? [];
$answers=[];
if (is_array($items)) {
  foreach ($items as $q) {
    if (!is_array($q)) { continue; }
    $qid=$q["question_id"] ?? "";
    if ($qid === "") { continue; }
    $answers[]=["question_id"=>$qid, "code"=>getenv("ANSWER_CODE")];
  }
}
$anonId = trim((string) getenv("ANON_ID"));
if ($anonId === "") {
  $anonId = "local_verify_" . substr(bin2hex(random_bytes(8)), 0, 8);
}
$payload=[
  "anon_id"=>$anonId,
  "scale_code"=>getenv("SCALE_CODE"),
  "scale_version"=>getenv("SCALE_VERSION"),
  "answers"=>$answers,
  "client_platform"=>"cli",
  "client_version"=>"verify-1",
  "channel"=>"direct",
  "referrer"=>"cli",
  "region"=>getenv("REGION"),
  "locale"=>getenv("LOCALE"),
];
file_put_contents(getenv("PAYLOAD_JSON"), json_encode($payload, JSON_UNESCAPED_UNICODE));
echo "[OK] payload written: " . getenv("PAYLOAD_JSON") . " answers=" . count($answers) . PHP_EOL;
'

if [[ -z "${ATTEMPT_ANON_ID}" ]]; then
  ATTEMPT_ANON_ID="$(PAYLOAD_JSON="$PAYLOAD_JSON" php -r '
  $j=json_decode(file_get_contents(getenv("PAYLOAD_JSON")), true);
  if (!is_array($j)) { exit(1); }
  echo (string)($j["anon_id"] ?? "");
  ' || true)"
fi

# 4) create attempt OR reuse attempt
if [[ -n "${ATTEMPT_ID:-}" ]]; then
  echo "[4/8] reuse attempt: $ATTEMPT_ID"
else
  echo "[4/8] create attempt"
  http="$(curl -sS -L -o "$ATTEMPT_RESP_JSON" -w "%{http_code}" \
    -X POST "$API/api/v0.2/attempts" \
    -H 'Content-Type: application/json' \
    "${CURL_OWNER[@]}" \
    -d @"$PAYLOAD_JSON" || true)"

  if [[ "$http" != "200" && "$http" != "201" ]]; then
    echo "[CURL][FAIL] create attempt HTTP=$http" >&2
    echo "---- body (first 800 bytes) ----" >&2
    head -c 800 "$ATTEMPT_RESP_JSON" 2>/dev/null || true
    echo >&2
    echo "---- server log tail (/tmp/pr4_srv.log) ----" >&2
    tail -n 200 /tmp/pr4_srv.log 2>/dev/null || true
    echo "---- laravel log tail ($BACKEND_DIR/storage/logs/laravel.log) ----" >&2
    tail -n 200 "$BACKEND_DIR/storage/logs/laravel.log" 2>/dev/null || true
    echo "---- laravel log tail (storage/logs/laravel.log) ----" >&2
    tail -n 200 storage/logs/laravel.log 2>/dev/null || true
    exit 5
  fi

  ATTEMPT_ID="$(ATTEMPT_RESP_JSON="$ATTEMPT_RESP_JSON" php -r '
$j=json_decode(file_get_contents(getenv("ATTEMPT_RESP_JSON")), true);
if (!is_array($j) || ($j["ok"] ?? false) !== true) { exit(2); }
$aid=$j["attempt_id"] ?? "";
if ($aid === "") { exit(3); }
echo $aid;
')"
fi

echo "$ATTEMPT_ID" > "$ATTEMPT_ID_TXT"
echo "$ATTEMPT_ANON_ID" > "$ANON_ID_TXT"
echo "[OK] attempt_id=$ATTEMPT_ID"

echo "[5/8] fetch report & share"
[[ -n "${ATTEMPT_ANON_ID}" ]] || fail "missing ATTEMPT_ANON_ID for report ownership guard"
REPORT_URL="$API/api/v0.2/attempts/$ATTEMPT_ID/report?anon_id=$ATTEMPT_ANON_ID"
# ✅ report uses owner anon_id guard; avoid mismatched fm_token owner overriding anon_id
fetch_json "$REPORT_URL" "$REPORT_JSON" 0
# share is public (not gated)
fetch_json "$API/api/v0.2/attempts/$ATTEMPT_ID/share"  "$SHARE_JSON" 0
echo "[OK] report=$REPORT_JSON"
echo "[OK] share=$SHARE_JSON"

# -----------------------------
# Phase A: Content verification
# -----------------------------
echo "[6/8] content verify (no fallback)"
# 1) pack prefix / locale assertions (php -r)
REPORT_JSON="$REPORT_JSON" SHARE_JSON="$SHARE_JSON" EXPECT_PACK_PREFIX="$EXPECT_PACK_PREFIX" EXPECT_LOCALE="$LOCALE" php -r '
function die_msg($msg) { fwrite(STDERR, "[ASSERT][FAIL] " . $msg . PHP_EOL); exit(2); }
$reportPath=getenv("REPORT_JSON");
$sharePath=getenv("SHARE_JSON");
$expectPackPrefix=getenv("EXPECT_PACK_PREFIX") ?: "";
$expectLocale=getenv("EXPECT_LOCALE") ?: "zh-CN";
$reportRoot=json_decode(file_get_contents($reportPath), true);
if (!is_array($reportRoot) || ($reportRoot["ok"] ?? false) !== true) { die_msg("report.ok != true"); }
$shareRoot=json_decode(file_get_contents($sharePath), true);
if (!is_array($shareRoot) || ($shareRoot["ok"] ?? false) !== true) { die_msg("share.ok != true"); }
$report=$reportRoot["report"] ?? [];
$versions=$report["versions"] ?? [];
$contentPackId=$versions["content_pack_id"] ?? $versions["content_pack"] ?? $report["content_pack_id"] ?? $reportRoot["content_pack_id"] ?? "";
if ($contentPackId === "") { die_msg("report.versions.content_pack_id missing"); }
$cards=$report["sections"]["traits"]["cards"] ?? [];
if (!is_array($cards) || count($cards) <= 0) { die_msg("report.sections.traits.cards empty or missing"); }
$shareId=$shareRoot["share_id"] ?? "";
if ($shareId === "") { die_msg("share.share_id missing"); }
$attemptReport=$reportRoot["attempt_id"] ?? "";
$attemptShare=$shareRoot["attempt_id"] ?? "";
$attemptId=$attemptShare ?: $attemptReport;
if ($attemptId === "") { die_msg("attempt_id missing in share/report root"); }
if ($attemptReport && $attemptShare && $attemptReport !== $attemptShare) { die_msg("attempt_id mismatch: share={$attemptShare} report={$attemptReport}"); }
$profile=$report["profile"] ?? [];
$reportType=$profile["type_code"] ?? ($reportRoot["type_code"] ?? "");
$shareType=$shareRoot["type_code"] ?? "";
if ($shareType && $reportType && $shareType !== $reportType) { die_msg("type_code mismatch: share={$shareType} report={$reportType}"); }
$idcard=$report["identity_card"] ?? [];
$locale=$idcard["locale"] ?? "";
$expectLocaleAlt=str_replace("-", "_", $expectLocale);
if ($locale !== $expectLocale && $locale !== $expectLocaleAlt) { die_msg("identity_card.locale unexpected: {$locale} (expect {$expectLocale})"); }
if ($expectPackPrefix !== "" && strpos((string)$contentPackId, $expectPackPrefix) === false) {
  die_msg("content_pack_id does not contain expected prefix: {$expectPackPrefix} (got {$contentPackId})");
}
echo "[ASSERT][OK] report/share assertions passed" . PHP_EOL;
echo "  attempt_id={$attemptId}" . PHP_EOL;
echo "  type_code=" . ($shareType ?: $reportType) . PHP_EOL;
echo "  content_pack_id={$contentPackId}" . PHP_EOL;
echo "  share_id={$shareId}" . PHP_EOL;
echo "  report_path={$reportPath}" . PHP_EOL;
echo "  share_path={$sharePath}" . PHP_EOL;
'

# -------------------------
# REGION positive assertion
# -------------------------
# Expect REGION like: CN_MAINLAND  -> report dir uses: CN-MAINLAND
_expect_region="${REGION:-CN_MAINLAND}"
_expect_region_dir="${_expect_region//_/-}"                 # CN_MAINLAND -> CN-MAINLAND
_expect_region_slug="$(echo "$_expect_region" | tr '[:upper:]' '[:lower:]' | tr '_' '-')"  # cn-mainland

_actual_dir="$(REPORT_JSON="$REPORT_JSON" php -r '$j=json_decode(file_get_contents(getenv("REPORT_JSON")), true); echo (string)($j["report"]["versions"]["content_package_dir"] ?? "");')"
_actual_pack_id="$(REPORT_JSON="$REPORT_JSON" php -r '$j=json_decode(file_get_contents(getenv("REPORT_JSON")), true); echo (string)($j["report"]["versions"]["content_pack_id"] ?? "");')"

if [[ -z "$_actual_dir" ]]; then
  echo "[ASSERT][FAIL] report.versions.content_package_dir missing"
  exit 2
fi

# dir must include CN-MAINLAND (or the expected region dir)
if [[ "$_actual_dir" != *"/${_expect_region_dir}/"* && "$_actual_dir" != *"${_expect_region_dir}/"* ]]; then
  echo "[ASSERT][FAIL] content_package_dir unexpected: ${_actual_dir} (expect contains ${_expect_region_dir})"
  exit 2
fi

# pack_id must include cn-mainland
if [[ -z "$_actual_pack_id" ]]; then
  echo "[ASSERT][FAIL] report.versions.content_pack_id missing"
  exit 2
fi

if [[ "$_actual_pack_id" != *".${_expect_region_slug}."* && "$_actual_pack_id" != *"${_expect_region_slug}"* ]]; then
  echo "[ASSERT][FAIL] content_pack_id unexpected: ${_actual_pack_id} (expect contains ${_expect_region_slug})"
  exit 2
fi

# 2) STRICT 负断言：禁止 deprecated/GLOBAL/en 信号出现在 report/share（足够抓到很多 silent fallback）
strict_negative_signals "$REPORT_JSON" "Content(report)"
strict_negative_signals "$SHARE_JSON" "Content(share)"

echo "[OK] content verification passed"

# -----------------------------
# Phase B: Rules verification (highlights contract)
# -----------------------------
echo "[7/8] rules verify (highlights contract)"
HL_PATH="$(pick_highlights_path "$REPORT_JSON" || true)"
[[ -n "${HL_PATH:-}" ]] || fail "Rules: cannot find highlights array path in report.json"

HL_N="$(REPORT_JSON="$REPORT_JSON" HL_PATH="$HL_PATH" MIN_HL="$MIN_HL" MAX_HL="$MAX_HL" php -r '
function die_msg($msg) { fwrite(STDERR, "[FAIL] " . $msg . PHP_EOL); exit(1); }
$file=getenv("REPORT_JSON");
$path=getenv("HL_PATH");
$min=(int)getenv("MIN_HL");
$max=(int)getenv("MAX_HL");
$data=json_decode(file_get_contents($file), true);
if (!is_array($data)) { die_msg("Rules: invalid json"); }
function get_path($data, $path) {
  $cur=$data;
  foreach (explode(".", $path) as $seg) {
    if ($seg === "") { continue; }
    if (is_array($cur) && array_key_exists($seg, $cur)) { $cur=$cur[$seg]; continue; }
    return null;
  }
  return $cur;
}
$hl=get_path($data, $path);
if (!is_array($hl)) { die_msg("Rules: invalid highlights path"); }
$len=count($hl);
if ($len < $min || $len > $max) { die_msg("Rules: highlights count out of range: got={$len} expect=[{$min},{$max}] path={$path}"); }
$kinds=[];
foreach ($hl as $item) {
  if (!is_array($item)) { continue; }
  $kinds[] = (string)($item["kind"] ?? "");
  $id = (string)($item["id"] ?? "");
  if ($id !== "" && str_contains($id, "hl.blindspot.hl.")) {
    die_msg("Rules: found forbidden id prefix 'hl.blindspot.hl.'");
  }
  $tags = $item["tags"] ?? [];
  $tags_str = is_array($tags) ? implode(",", $tags) : "";
  $combined = strtolower(trim($id . " " . $tags_str));
  if ($combined !== "" && str_contains($combined, "borderline")) {
    die_msg("Rules: found forbidden 'borderline' in highlight id/tags");
  }
}
if (!in_array("blindspot", $kinds, true)) { die_msg("Rules: highlights.kind must include 'blindspot'"); }
if (!in_array("action", $kinds, true)) { die_msg("Rules: highlights.kind must include 'action'"); }
echo $len;
')"

echo "[OK] rules verification passed (path=$HL_PATH count=$HL_N)"

# -----------------------------
# Phase C: Overrides verification (call accept_overrides_D.sh)
# -----------------------------
echo "[8/8] overrides verify (accept_overrides_D.sh)"
ACCEPT_OVR="$SCRIPT_DIR/accept_overrides_D.sh"

if [[ -f "$ACCEPT_OVR" ]]; then
  # ✅ 严格模式：accept_overrides_D.sh 非 0 直接 FAIL
  # 同时把 stdout/stderr 全部落盘到 artifacts，方便回溯
  BASE="$API" FM_TOKEN="${FM_TOKEN:-}" bash "$ACCEPT_OVR" "$ATTEMPT_ID" >"$OVR_LOG" 2>&1 || {
  echo "---- overrides log (tail 200 lines) ----" >&2
  tail -n 200 "$OVR_LOG" >&2 || true
  fail "Overrides: accept_overrides_D.sh failed (see $OVR_LOG)"
}

  # ✅ 额外硬断言：必须包含 ALL DONE（防止脚本误 exit 0 但没跑完）
  assert_contains "$OVR_LOG" "✅ ALL DONE: D-1 / D-2 / D-3 passed" "Overrides"

  # ✅ STRICT 负断言：禁止 deprecated/GLOBAL/en 信号
  strict_negative_signals "$OVR_LOG" "Overrides(log)"

  echo "[OK] overrides verification passed (log=$OVR_LOG)"
else
  echo "[SKIP] accept_overrides_D.sh not found; overrides phase skipped"
fi

# -----------------------------
# Summary
# -----------------------------
MVP_CHECK_LOG="${MVP_CHECK_LOG:-$LOG_DIR/mvp_check.log}"
MVP_CHECK_STATUS="(not generated)"
if [[ -s "$MVP_CHECK_LOG" ]]; then
  MVP_CHECK_STATUS="see $MVP_CHECK_LOG"
fi

cat >"$SUMMARY_TXT" <<EOF
verify_mbti summary
  VERIFY_MODE=$VERIFY_MODE
    API=$API
  REGION=$REGION
  LOCALE=$LOCALE
  EXPECT_PACK_PREFIX=$EXPECT_PACK_PREFIX
  STRICT=$STRICT
  highlights_path=$HL_PATH
  highlights_count=$HL_N
  mvp_check=$MVP_CHECK_STATUS
  artifacts_dir=$RUN_DIR
  files:
    report=$REPORT_JSON
    share=$SHARE_JSON
    overrides_log=${OVR_LOG}
EOF

echo "[DONE] verify_mbti OK ✅"
echo "[SUMMARY] $SUMMARY_TXT"
echo "[ARTIFACTS] $RUN_DIR"
