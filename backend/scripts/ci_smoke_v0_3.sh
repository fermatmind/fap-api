#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$BACKEND_DIR"

if [[ ! -f ".env" ]]; then
  cp .env.example .env
fi

PORT=18001
BASE_URL="http://127.0.0.1:${PORT}"
SERVE_LOG="/tmp/fap_ci_serve.log"

php artisan serve --host=127.0.0.1 --port="${PORT}" >"${SERVE_LOG}" 2>&1 &
SRV_PID=$!

cleanup() {
  if [[ -n "${SRV_PID:-}" ]] && kill -0 "${SRV_PID}" >/dev/null 2>&1; then
    kill "${SRV_PID}" >/dev/null 2>&1 || true
    wait "${SRV_PID}" 2>/dev/null || true
  fi
}
trap cleanup EXIT

READY_URL=""
for i in $(seq 1 30); do
  if curl -fsS "${BASE_URL}/healthz" >/dev/null 2>&1; then
    READY_URL="${BASE_URL}/healthz"
    break
  fi
  if curl -fsS "${BASE_URL}/api/healthz" >/dev/null 2>&1; then
    READY_URL="${BASE_URL}/api/healthz"
    break
  fi
  sleep 1
done

if [[ -z "${READY_URL}" ]]; then
  echo "[smoke_v0_3] healthz timeout"
  tail -n 120 "${SERVE_LOG}" || true
  exit 1
fi

BOOT_JSON="$(curl -fsS "${BASE_URL}/api/v0.3/boot")"
BOOT_JSON="$(printf '%s\n' "${BOOT_JSON}" | sed -n '/^{/,$p')"
php -r '
$raw = stream_get_contents(STDIN);
$j = json_decode($raw, true);
if (!is_array($j)) { fwrite(STDERR, "boot: top-level must be object\n"); exit(1); }
if (($j["ok"] ?? null) !== true) { fwrite(STDERR, "boot: ok must be true\n"); exit(1); }
if (array_key_exists("version", $j) && (!is_string($j["version"]) || trim($j["version"]) === "")) {
    fwrite(STDERR, "boot: version must be non-empty string when present\n");
    exit(1);
}
' <<<"${BOOT_JSON}"

FLAGS_JSON="$(curl -fsS "${BASE_URL}/api/v0.3/flags")"
FLAGS_JSON="$(printf '%s\n' "${FLAGS_JSON}" | sed -n '/^{/,$p')"
php -r '
$raw = stream_get_contents(STDIN);
$j = json_decode($raw, true);
if (!is_array($j)) { fwrite(STDERR, "flags: top-level must be object\n"); exit(1); }
if (!array_key_exists("ok", $j) && !array_key_exists("flags", $j)) {
    fwrite(STDERR, "flags: missing ok/flags key\n");
    exit(1);
}
' <<<"${FLAGS_JSON}"

echo "PASS: smoke v0.3"
