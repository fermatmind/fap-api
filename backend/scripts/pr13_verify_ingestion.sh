#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${ROOT_DIR}/backend"
ART_DIR="${BACKEND_DIR}/artifacts/pr13"
LOG_DIR="${ART_DIR}/logs"
SERVER_LOG="${LOG_DIR}/server.log"

mkdir -p "${LOG_DIR}"

PORT="${PORT:-18030}"
HOST="127.0.0.1"

detect_port() {
  local p="${PORT}"
  for i in $(seq 0 29); do
    local try=$((p + i))
    if ! lsof -iTCP -sTCP:LISTEN -n -P 2>/dev/null | rg -q ":${try}"; then
      PORT="${try}"
      return 0
    fi
  done
  echo "No available port in 18030-18059" >&2
  exit 1
}

start_server() {
  detect_port
  cd "${BACKEND_DIR}"
  php artisan serve --host="${HOST}" --port="${PORT}" >"${SERVER_LOG}" 2>&1 &
  SERVER_PID=$!
  echo "${SERVER_PID}" > "${ART_DIR}/server.pid"
  sleep 2
}

stop_server() {
  if [[ -f "${ART_DIR}/server.pid" ]]; then
    local pid
    pid="$(cat "${ART_DIR}/server.pid")"
    if kill -0 "${pid}" 2>/dev/null; then
      kill "${pid}" || true
    fi
    rm -f "${ART_DIR}/server.pid"
  fi
}

cleanup() {
  stop_server
}

trap cleanup EXIT

cd "${BACKEND_DIR}"
php artisan migrate
php artisan db:seed --class=QuantifiedSelfSeeder

start_server

BASE_URL="http://${HOST}:${PORT}/api/v0.2"

oauth_start=$(curl -s "${BASE_URL}/integrations/mock/oauth/start")
state=$(echo "${oauth_start}" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["state"] ?? "";')
oauth_cb=$(curl -s "${BASE_URL}/integrations/mock/oauth/callback?state=${state}&code=mock_code")

phone_send=$(curl -s -X POST "${BASE_URL}/auth/phone/send_code" -H "Content-Type: application/json" -d '{"phone":"15500000001","scene":"login","anon_id":"anon_pr13","consent":true}')
dev_code=$(echo "${phone_send}" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["dev_code"] ?? "";')
phone_verify=$(curl -s -X POST "${BASE_URL}/auth/phone/verify" -H "Content-Type: application/json" -d "{\"phone\":\"15500000001\",\"code\":\"${dev_code}\",\"scene\":\"login\",\"anon_id\":\"anon_pr13\",\"consent\":true}")
fm_token=$(echo "${phone_verify}" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["token"] ?? "";')
user_id=$(echo "${phone_verify}" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["user"]["uid"] ?? "";')
if [[ -z "${fm_token}" ]]; then
  echo "Failed to get fm_token from phone_verify" >&2
  echo "phone_send=${phone_send}" >&2
  echo "phone_verify=${phone_verify}" >&2
  exit 1
fi
if [[ -z "${user_id}" ]]; then
  echo "Failed to get user_id from phone_verify" >&2
  echo "phone_verify=${phone_verify}" >&2
  exit 1
fi

payload="{\"user_id\":${user_id},\"range_start\":\"2026-01-01T00:00:00Z\",\"range_end\":\"2026-01-10T00:00:00Z\",\"samples\":[{\"domain\":\"sleep\",\"recorded_at\":\"2026-01-10T00:00:00Z\",\"value\":{\"duration_minutes\":420},\"external_id\":\"sleep_1\"},{\"domain\":\"screen_time\",\"recorded_at\":\"2026-01-10T00:00:00Z\",\"value\":{\"total_screen_minutes\":180},\"external_id\":\"screen_1\"},{\"domain\":\"steps\",\"recorded_at\":\"2026-01-10T00:00:00Z\",\"value\":{\"steps\":8000},\"external_id\":\"steps_1\"},{\"domain\":\"heart_rate\",\"recorded_at\":\"2026-01-10T01:00:00Z\",\"value\":{\"bpm\":72},\"external_id\":\"hr_1\"},{\"domain\":\"mood\",\"recorded_at\":\"2026-01-10T02:00:00Z\",\"value\":{\"score\":4},\"external_id\":\"mood_1\"},{\"domain\":\"sleep\",\"recorded_at\":\"2026-01-11T00:00:00Z\",\"value\":{\"duration_minutes\":390},\"external_id\":\"sleep_2\"},{\"domain\":\"screen_time\",\"recorded_at\":\"2026-01-11T00:00:00Z\",\"value\":{\"total_screen_minutes\":210},\"external_id\":\"screen_2\"},{\"domain\":\"steps\",\"recorded_at\":\"2026-01-11T00:00:00Z\",\"value\":{\"steps\":7000},\"external_id\":\"steps_2\"},{\"domain\":\"heart_rate\",\"recorded_at\":\"2026-01-11T01:00:00Z\",\"value\":{\"bpm\":68},\"external_id\":\"hr_2\"},{\"domain\":\"mood\",\"recorded_at\":\"2026-01-11T02:00:00Z\",\"value\":{\"score\":3},\"external_id\":\"mood_2\"}]}"

ingest=$(curl -s -X POST "${BASE_URL}/integrations/mock/ingest" -H "Content-Type: application/json" -d "${payload}")
batch_id=$(echo "${ingest}" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["batch_id"] ?? "";')
skipped=$(echo "${ingest}" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["skipped"] ?? "0";')
if [[ -z "${batch_id}" ]]; then
  echo "Failed to get batch_id from ingest" >&2
  echo "ingest=${ingest}" >&2
  exit 1
fi

ingest_repeat=$(curl -s -X POST "${BASE_URL}/integrations/mock/ingest" -H "Content-Type: application/json" -d "${payload}")
skipped_repeat=$(echo "${ingest_repeat}" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["skipped"] ?? "0";')
if [[ "${skipped_repeat}" == "0" ]]; then
  echo "Expected skipped_repeat > 0" >&2
  echo "ingest_repeat=${ingest_repeat}" >&2
  exit 1
fi

replay=$(curl -s -X POST "${BASE_URL}/integrations/mock/replay/${batch_id}")
replay_inserted=$(echo "${replay}" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["inserted"] ?? "0";')
if [[ "${replay_inserted}" != "0" ]]; then
  echo "Expected replay_inserted == 0" >&2
  echo "replay=${replay}" >&2
  exit 1
fi

webhook_payload='{"event_id":"evt_001","external_user_id":"ext_001","recorded_at":"2026-01-12T00:00:00Z","samples":[{"domain":"sleep","recorded_at":"2026-01-12T00:00:00Z","value":{"duration_minutes":410},"external_id":"sleep_3"}]}'
webhook_first=$(curl -s -X POST "${BASE_URL}/webhooks/mock" -H "Content-Type: application/json" -d "${webhook_payload}")
webhook_second=$(curl -s -X POST "${BASE_URL}/webhooks/mock" -H "Content-Type: application/json" -d "${webhook_payload}")
webhook_second_status=$(echo "${webhook_second}" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["status"] ?? "";')
if [[ "${webhook_second_status}" != "duplicate" ]]; then
  echo "Expected webhook_second status=duplicate" >&2
  echo "webhook_second=${webhook_second}" >&2
  exit 1
fi

sleep_resp=$(curl -s "${BASE_URL}/me/data/sleep" -H "Authorization: Bearer ${fm_token}" || true)
screen_resp=$(curl -s "${BASE_URL}/me/data/screen-time" -H "Authorization: Bearer ${fm_token}" || true)
sleep_ok=$(echo "${sleep_resp}" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["ok"] ?? "";')
screen_ok=$(echo "${screen_resp}" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["ok"] ?? "";')
if [[ "${sleep_ok}" != "1" && "${sleep_ok}" != "true" ]]; then
  echo "Expected sleep_ok true" >&2
  echo "sleep_resp=${sleep_resp}" >&2
  exit 1
fi
if [[ "${screen_ok}" != "1" && "${screen_ok}" != "true" ]]; then
  echo "Expected screen_ok true" >&2
  echo "screen_resp=${screen_resp}" >&2
  exit 1
fi

{
  echo "checks=oauth_start,oauth_callback,phone_login,ingest,ingest_repeat,replay,webhook_duplicate,me_data"
  echo "PR13 Ingestion Verify Summary"
  echo "BASE_URL=${BASE_URL}"
  echo "port=${PORT}"
  echo "urls=${BASE_URL}/integrations/mock/ingest,${BASE_URL}/integrations/mock/replay/${batch_id},${BASE_URL}/webhooks/mock,${BASE_URL}/me/data/sleep"
  echo "oauth_start=${oauth_start}"
  echo "oauth_cb=${oauth_cb}"
  echo "ingest=${ingest}"
  echo "ingest_repeat=${ingest_repeat}"
  echo "replay=${replay}"
  echo "webhook_first=${webhook_first}"
  echo "webhook_second=${webhook_second}"
  echo "batch_id=${batch_id}"
  echo "skipped=${skipped}"
  echo "skipped_repeat=${skipped_repeat}"
  echo "replay_inserted=${replay_inserted}"
  echo "tables=integrations,ingest_batches,sleep_samples,health_samples,screen_time_samples,idempotency_keys"
} | tee "${ART_DIR}/summary.txt"
