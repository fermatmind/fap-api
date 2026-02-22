#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

RUN_BIG5_OCEAN_GATE="${RUN_BIG5_OCEAN_GATE:-0}"
RUN_FULL_SCALE_REGRESSION="${RUN_FULL_SCALE_REGRESSION:-0}"
SCALE_SCOPE="${SCALE_SCOPE:-mbti_only}"

echo "[CI][scales] scale_scope=${SCALE_SCOPE} run_big5_ocean_gate=${RUN_BIG5_OCEAN_GATE} run_full_scale_regression=${RUN_FULL_SCALE_REGRESSION}"
echo "[CI][scales] running MBTI baseline gate"
bash "${BACKEND_DIR}/scripts/ci_verify_mbti.sh"

if [[ "${RUN_BIG5_OCEAN_GATE}" != "1" ]]; then
  echo "[CI][scales] BIG5_OCEAN gate not requested; skip BIG5 compiled evidence checks"
  exit 0
fi

echo "[CI][scales] validating BIG5 compiled evidence"
MANIFEST_PATH="${BACKEND_DIR}/content_packs/BIG5_OCEAN/v1/compiled/manifest.json"
if [[ ! -f "${MANIFEST_PATH}" ]]; then
  echo "[CI][scales][FAIL] missing BIG5 compiled manifest: ${MANIFEST_PATH}" >&2
  exit 20
fi

php -r '
$path = $argv[1] ?? "";
$json = @file_get_contents($path);
if (!is_string($json) || $json === "") {
    fwrite(STDERR, "[CI][scales][FAIL] unable to read manifest\n");
    exit(21);
}
$manifest = json_decode($json, true);
if (!is_array($manifest)) {
    fwrite(STDERR, "[CI][scales][FAIL] invalid manifest json\n");
    exit(22);
}
$compiledHash = trim((string) ($manifest["compiled_hash"] ?? ""));
if ($compiledHash !== "") {
    echo "[CI][scales] BIG5 compiled_hash={$compiledHash}\n";
    exit(0);
}
$hashes = $manifest["hashes"] ?? null;
if (!is_array($hashes) || count($hashes) === 0) {
    fwrite(STDERR, "[CI][scales][FAIL] missing compiled hash evidence in manifest\n");
    exit(23);
}
echo "[CI][scales] BIG5 manifest hash entries=" . count($hashes) . "\n";
' "${MANIFEST_PATH}"

echo "[CI][scales] collecting BIG5 telemetry summary"
TELEMETRY_JSON="$(cd "${BACKEND_DIR}" && php artisan big5:telemetry:summary --hours=168 --json=1 | tail -n 1)"
php -r '
$raw = $argv[1] ?? "";
$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
    fwrite(STDERR, "[CI][scales][FAIL] telemetry summary is not valid json\n");
    exit(24);
}
$metrics = $decoded["metrics"] ?? null;
if (!is_array($metrics)) {
    fwrite(STDERR, "[CI][scales][FAIL] telemetry summary missing metrics object\n");
    exit(25);
}
$required = [
    "big5.report.failure_rate",
    "big5.norms.fallback_rate",
    "big5.norms.missing_rate",
    "big5.payment.unlock_success_rate",
    "big5.payment.webhook_failed_rate",
    "big5.questions.locale_mismatch_rate",
];
foreach ($required as $key) {
    if (!array_key_exists($key, $metrics)) {
        fwrite(STDERR, "[CI][scales][FAIL] telemetry summary missing metric: {$key}\n");
        exit(26);
    }
}
echo "[CI][scales] telemetry metrics keys verified=" . count($required) . "\n";
' "${TELEMETRY_JSON}"

echo "[CI][scales] running commerce reconcile smoke"
RECON_DATE="$(TZ=Asia/Shanghai date +%F)"
RECON_JSON="$(cd "${BACKEND_DIR}" && php artisan commerce:reconcile --date="${RECON_DATE}" --org_id=0 --json=1 | tail -n 1)"
php -r '
$raw = $argv[1] ?? "";
$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
    fwrite(STDERR, "[CI][scales][FAIL] commerce reconcile output is not valid json\n");
    exit(27);
}
foreach (["ok", "paid_count", "unlocked_count", "mismatch_count", "mismatches"] as $key) {
    if (!array_key_exists($key, $decoded)) {
        fwrite(STDERR, "[CI][scales][FAIL] commerce reconcile missing key: {$key}\n");
        exit(28);
    }
}
echo "[CI][scales] commerce reconcile keys verified\n";
' "${RECON_JSON}"

echo "[CI][scales] running BIG5 perf budget gate"
bash "${BACKEND_DIR}/scripts/ci/verify_big5_perf.sh"

echo "[CI][scales] checking BIG5 questions sidecar artifact (budget gate)"
QUESTIONS_FULL="${BACKEND_DIR}/content_packs/BIG5_OCEAN/v1/compiled/questions.compiled.json"
QUESTIONS_MIN="${BACKEND_DIR}/content_packs/BIG5_OCEAN/v1/compiled/questions.min.compiled.json"
if [[ ! -f "${QUESTIONS_MIN}" ]]; then
  echo "[CI][scales][FAIL] missing sidecar compiled file: ${QUESTIONS_MIN}" >&2
  exit 29
fi
if [[ ! -f "${QUESTIONS_FULL}" ]]; then
  echo "[CI][scales][FAIL] missing compiled file: ${QUESTIONS_FULL}" >&2
  exit 30
fi

FULL_BYTES="$(wc -c < "${QUESTIONS_FULL}" | tr -d '[:space:]')"
MIN_BYTES="$(wc -c < "${QUESTIONS_MIN}" | tr -d '[:space:]')"
if [[ "${FULL_BYTES}" == "0" ]]; then
  echo "[CI][scales][FAIL] questions.compiled.json is empty" >&2
  exit 31
fi

RATIO="$(php -r '$full=(float)$argv[1];$min=(float)$argv[2];echo $full>0?number_format($min/$full,4,".",""):"1.0000";' "${FULL_BYTES}" "${MIN_BYTES}")"
echo "[CI][scales] questions sizes full_bytes=${FULL_BYTES} min_bytes=${MIN_BYTES} ratio=${RATIO}"

MAX_RATIO="$(php -r '$cfg=require $argv[1];echo (string)($cfg["questions"]["min_compiled_max_ratio"] ?? "0.60");' "${BACKEND_DIR}/config/big5_content_budget.php")"
MAX_BYTES="$(php -r '$cfg=require $argv[1];echo (string)($cfg["questions"]["min_compiled_max_bytes"] ?? "300000");' "${BACKEND_DIR}/config/big5_content_budget.php")"
php -r '
$ratio=(float)($argv[1] ?? "1");
$maxRatio=(float)($argv[2] ?? "0.6");
$minBytes=(int)($argv[3] ?? "0");
$maxBytes=(int)($argv[4] ?? "300000");
if ($ratio > $maxRatio) {
    fwrite(STDERR, "[CI][scales][FAIL] questions.min ratio exceeds budget: {$ratio} > {$maxRatio}\n");
    exit(32);
}
if ($minBytes > $maxBytes) {
    fwrite(STDERR, "[CI][scales][FAIL] questions.min bytes exceed budget: {$minBytes} > {$maxBytes}\n");
    exit(33);
}
fwrite(STDOUT, "[CI][scales] questions.min budget gate passed\n");
' "${RATIO}" "${MAX_RATIO}" "${MIN_BYTES}" "${MAX_BYTES}"

echo "[CI][scales] completed"
