#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ART_DIR="$ROOT_DIR/artifacts/prA_gate_scale_identity"
mkdir -p "$ART_DIR"

cd "$ROOT_DIR"

php artisan migrate --force >/dev/null
php artisan fap:scales:seed-default >/dev/null

FAP_GATE_IDENTITY_RESOLVE_MISMATCH_RATE_MAX="${FAP_GATE_IDENTITY_RESOLVE_MISMATCH_RATE_MAX:-0}"
FAP_GATE_DUAL_WRITE_MISMATCH_RATE_MAX="${FAP_GATE_DUAL_WRITE_MISMATCH_RATE_MAX:-0}"
FAP_GATE_CONTENT_PATH_FALLBACK_RATE_MAX="${FAP_GATE_CONTENT_PATH_FALLBACK_RATE_MAX:-1}"
FAP_GATE_LEGACY_CODE_HIT_RATE_MAX="${FAP_GATE_LEGACY_CODE_HIT_RATE_MAX:-1}"
FAP_GATE_DEMO_SCALE_HIT_RATE_MAX="${FAP_GATE_DEMO_SCALE_HIT_RATE_MAX:-0.001}"

export FAP_GATE_IDENTITY_RESOLVE_MISMATCH_RATE_MAX
export FAP_GATE_DUAL_WRITE_MISMATCH_RATE_MAX
export FAP_GATE_CONTENT_PATH_FALLBACK_RATE_MAX
export FAP_GATE_LEGACY_CODE_HIT_RATE_MAX
export FAP_GATE_DEMO_SCALE_HIT_RATE_MAX

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

cat > "$ART_DIR/summary.txt" <<TXT
PR-A scale identity gate summary

Checks:
- migrate --force: OK
- fap:scales:seed-default: OK
- ops:scale-identity-gate --json=1: OK
- ops:scale-identity-gate --strict=1 --json=1: PASS

Thresholds:
- identity_resolve_mismatch_rate <= ${FAP_GATE_IDENTITY_RESOLVE_MISMATCH_RATE_MAX}
- dual_write_mismatch_rate <= ${FAP_GATE_DUAL_WRITE_MISMATCH_RATE_MAX}
- content_path_fallback_rate <= ${FAP_GATE_CONTENT_PATH_FALLBACK_RATE_MAX}
- legacy_code_hit_rate <= ${FAP_GATE_LEGACY_CODE_HIT_RATE_MAX}
- demo_scale_hit_rate <= ${FAP_GATE_DEMO_SCALE_HIT_RATE_MAX}

Artifacts:
- backend/artifacts/prA_gate_scale_identity/gate.json
- backend/artifacts/prA_gate_scale_identity/gate_strict.json
- backend/artifacts/prA_gate_scale_identity/summary.txt
TXT

echo "[OK] scale identity gate verification complete."
