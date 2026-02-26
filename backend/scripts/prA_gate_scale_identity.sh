#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ART_DIR="$ROOT_DIR/artifacts/prA_gate_scale_identity"
HARD_CUTOVER_SH="$ROOT_DIR/scripts/ci/verify_scale_identity_hard_cutover.sh"
mkdir -p "$ART_DIR"

REGION="${REGION:-CN_MAINLAND}"
LOCALE="${LOCALE:-zh-CN}"
HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-1827}"
API="${API:-http://${HOST}:${PORT}}"
FAP_PACKS_DRIVER="${FAP_PACKS_DRIVER:-local}"
FAP_PACKS_ROOT="${FAP_PACKS_ROOT:-$ROOT_DIR/../content_packages}"
FAP_PACKS_CACHE_DIR="${FAP_PACKS_CACHE_DIR:-storage/app/private/content_packs_cache}"
MBTI_CONTENT_PACKAGE="${MBTI_CONTENT_PACKAGE:-default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3}"
FAP_SCALE_IDENTITY_WRITE_MODE="${FAP_SCALE_IDENTITY_WRITE_MODE:-dual}"
FAP_SCALE_IDENTITY_READ_MODE="${FAP_SCALE_IDENTITY_READ_MODE:-v2}"
FAP_API_RESPONSE_SCALE_CODE_MODE="${FAP_API_RESPONSE_SCALE_CODE_MODE:-v2}"
FAP_ACCEPT_LEGACY_SCALE_CODE="${FAP_ACCEPT_LEGACY_SCALE_CODE:-false}"
FAP_ALLOW_DEMO_SCALES="${FAP_ALLOW_DEMO_SCALES:-false}"
FAP_CONTENT_PATH_MODE="${FAP_CONTENT_PATH_MODE:-dual_prefer_new}"
FAP_CONTENT_PUBLISH_MODE="${FAP_CONTENT_PUBLISH_MODE:-dual}"

cd "$ROOT_DIR"

if [[ ! -x "$HARD_CUTOVER_SH" ]]; then
  echo "[FAIL] missing or not executable: $HARD_CUTOVER_SH" >&2
  exit 1
fi

php artisan migrate --force >/dev/null
php artisan fap:scales:seed-default >/dev/null

FAP_GATE_IDENTITY_RESOLVE_MISMATCH_RATE_MAX="${FAP_GATE_IDENTITY_RESOLVE_MISMATCH_RATE_MAX:-0}"
FAP_GATE_DUAL_WRITE_MISMATCH_RATE_MAX="${FAP_GATE_DUAL_WRITE_MISMATCH_RATE_MAX:-0}"
FAP_GATE_CONTENT_PATH_FALLBACK_RATE_MAX="${FAP_GATE_CONTENT_PATH_FALLBACK_RATE_MAX:-0}"
FAP_GATE_LEGACY_CODE_HIT_RATE_MAX="${FAP_GATE_LEGACY_CODE_HIT_RATE_MAX:-0}"
FAP_GATE_DEMO_SCALE_HIT_RATE_MAX="${FAP_GATE_DEMO_SCALE_HIT_RATE_MAX:-0}"

export FAP_GATE_IDENTITY_RESOLVE_MISMATCH_RATE_MAX
export FAP_GATE_DUAL_WRITE_MISMATCH_RATE_MAX
export FAP_GATE_CONTENT_PATH_FALLBACK_RATE_MAX
export FAP_GATE_LEGACY_CODE_HIT_RATE_MAX
export FAP_GATE_DEMO_SCALE_HIT_RATE_MAX
export FAP_PACKS_DRIVER
export FAP_PACKS_ROOT
export FAP_PACKS_CACHE_DIR
export MBTI_CONTENT_PACKAGE
export FAP_SCALE_IDENTITY_WRITE_MODE
export FAP_SCALE_IDENTITY_READ_MODE
export FAP_API_RESPONSE_SCALE_CODE_MODE
export FAP_ACCEPT_LEGACY_SCALE_CODE
export FAP_ALLOW_DEMO_SCALES
export FAP_CONTENT_PATH_MODE
export FAP_CONTENT_PUBLISH_MODE

php artisan ops:scale-identity-gate --json=1 > "$ART_DIR/gate.json"

php -r '
$payload = json_decode((string) file_get_contents($argv[1]), true);
if (!is_array($payload) || ($payload["ok"] ?? false) !== true) {
    fwrite(STDERR, "[FAIL] invalid gate payload\n");
    exit(1);
}
$metrics = is_array($payload["metrics"] ?? null) ? $payload["metrics"] : [];
$required = [
    "identity_resolve_mismatch_rate",
    "dual_write_mismatch_rate",
    "content_path_fallback_rate",
    "legacy_code_hit_rate",
    "demo_scale_hit_rate",
];
foreach ($required as $name) {
    if (!array_key_exists($name, $metrics)) {
        fwrite(STDERR, "[FAIL] missing metric: {$name}\n");
        exit(1);
    }
}
' "$ART_DIR/gate.json"

php artisan ops:scale-identity-gate --json=1 --strict=1 > "$ART_DIR/gate_strict.json"

php -r '
$payload = json_decode((string) file_get_contents($argv[1]), true);
if (!is_array($payload)) {
    fwrite(STDERR, "[FAIL] strict payload decode failed\n");
    exit(1);
}
$pass = (bool) ($payload["pass"] ?? false);
if (!$pass) {
    fwrite(STDERR, "[FAIL] strict gate failed: ".json_encode($payload["violations"] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    exit(1);
}
' "$ART_DIR/gate_strict.json"

php artisan ops:scale-identity-mode-audit --json=1 --strict=1 > "$ART_DIR/mode_audit_strict.json"

php -r '
$payload = json_decode((string) file_get_contents($argv[1]), true);
if (!is_array($payload)) {
    fwrite(STDERR, "[FAIL] mode audit payload decode failed\n");
    exit(1);
}
$pass = (bool) ($payload["pass"] ?? false);
if (!$pass) {
    fwrite(STDERR, "[FAIL] mode audit strict failed: ".json_encode($payload["violations"] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    exit(1);
}
' "$ART_DIR/mode_audit_strict.json"

SERVE_LOG="$ART_DIR/serve.log"
HARD_CUTOVER_LOG="$ART_DIR/hard_cutover.log"
SRV_PID=""

cleanup() {
  if [[ -n "${SRV_PID}" ]]; then
    kill "$SRV_PID" >/dev/null 2>&1 || true
    wait "$SRV_PID" 2>/dev/null || true
    SRV_PID=""
  fi
}

trap cleanup EXIT

php artisan serve --host="$HOST" --port="$PORT" >"$SERVE_LOG" 2>&1 &
SRV_PID=$!

ready=0
for _ in $(seq 1 30); do
  if curl -fsS "$API/api/healthz" >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 1
done

if [[ "$ready" != "1" ]]; then
  echo "[FAIL] api server did not become healthy at $API" >&2
  tail -n 120 "$SERVE_LOG" >&2 || true
  exit 1
fi

if ! API="$API" REGION="$REGION" LOCALE="$LOCALE" bash "$HARD_CUTOVER_SH" >"$HARD_CUTOVER_LOG" 2>&1; then
  echo "[FAIL] hard-cutover contract probe failed" >&2
  tail -n 200 "$HARD_CUTOVER_LOG" >&2 || true
  exit 1
fi

cleanup
trap - EXIT

cat > "$ART_DIR/summary.txt" <<TXT
PR-A scale identity gate summary

Checks:
- migrate --force: OK
- fap:scales:seed-default: OK
- ops:scale-identity-gate --json=1: OK
- ops:scale-identity-gate --strict=1 --json=1: PASS
- ops:scale-identity-mode-audit --strict=1 --json=1: PASS
- verify_scale_identity_hard_cutover.sh (six-scale probes): PASS

Thresholds:
- identity_resolve_mismatch_rate <= ${FAP_GATE_IDENTITY_RESOLVE_MISMATCH_RATE_MAX}
- dual_write_mismatch_rate <= ${FAP_GATE_DUAL_WRITE_MISMATCH_RATE_MAX}
- content_path_fallback_rate <= ${FAP_GATE_CONTENT_PATH_FALLBACK_RATE_MAX}
- legacy_code_hit_rate <= ${FAP_GATE_LEGACY_CODE_HIT_RATE_MAX}
- demo_scale_hit_rate <= ${FAP_GATE_DEMO_SCALE_HIT_RATE_MAX}

Artifacts:
- backend/artifacts/prA_gate_scale_identity/gate.json
- backend/artifacts/prA_gate_scale_identity/gate_strict.json
- backend/artifacts/prA_gate_scale_identity/mode_audit_strict.json
- backend/artifacts/prA_gate_scale_identity/hard_cutover.log
- backend/artifacts/prA_gate_scale_identity/serve.log
- backend/artifacts/prA_gate_scale_identity/summary.txt
TXT

echo "[OK] scale identity gate verification complete."
