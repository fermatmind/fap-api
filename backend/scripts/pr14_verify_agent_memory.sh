#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ART_DIR="$ROOT_DIR/artifacts/pr14"
LOG_DIR="$ART_DIR/logs"
mkdir -p "$LOG_DIR"

PORT_BASE=${PORT:-18040}
PORT="$PORT_BASE"

function pick_port() {
  local p
  for p in $(seq $PORT_BASE 18059); do
    if ! lsof -iTCP:"$p" -sTCP:LISTEN >/dev/null 2>&1; then
      PORT="$p"
      return 0
    fi
  done
  echo "No available port between $PORT_BASE-18059" >&2
  exit 1
}

pick_port

SERVER_LOG="$LOG_DIR/server.log"

PHP_BIND_OK=1
if ! php -r '$s=@stream_socket_server("tcp://127.0.0.1:0",$e,$s); if ($s){fclose($s); echo "OK";} else {echo "FAIL";}' | grep -q "OK"; then
  PHP_BIND_OK=0
  echo "[WARN] php cannot bind local ports; using internal requests" >&2
fi

SERVER_PID=""
USE_INTERNAL=0
if [ "$PHP_BIND_OK" -eq 1 ]; then
  AGENT_ENABLED=true MEMORY_ENABLED=true VECTORSTORE_ENABLED=true php artisan serve --host=127.0.0.1 --port="$PORT" >"$SERVER_LOG" 2>&1 &
  SERVER_PID=$!
  sleep 2

  if ! curl -fsS "http://127.0.0.1:$PORT/api/v0.2/health" >/dev/null 2>&1; then
    echo "[WARN] server not healthy; falling back to internal requests" >&2
    USE_INTERNAL=1
  fi
else
  USE_INTERNAL=1
fi

function cleanup() {
  if [ -n "${SERVER_PID:-}" ] && ps -p "$SERVER_PID" >/dev/null 2>&1; then
    kill "$SERVER_PID" || true
  fi
}
trap cleanup EXIT

BASE_URL="http://127.0.0.1:$PORT/api/v0.2"

# Create fm_token if needed
TOKEN=""
if php -r "require '$ROOT_DIR/vendor/autoload.php';" 2>/dev/null; then
  :
fi

if php -r "exit(extension_loaded('pdo_sqlite') ? 0 : 0);"; then
  :
fi

TOKEN=$(php -r "require '$ROOT_DIR/vendor/autoload.php'; \$token='fm_' . (string) \Illuminate\Support\Str::uuid(); echo \$token;" )

php artisan tinker --execute 'if (Schema::hasTable("fm_tokens")) { DB::table("fm_tokens")->insert(["token" => "'$TOKEN'", "user_id" => 1, "anon_id" => "anon_pr14", "created_at" => now(), "updated_at" => now()]); }' >/dev/null || true
php artisan tinker --execute 'if (Schema::hasTable("integrations")) { DB::table("integrations")->updateOrInsert(["user_id" => 1, "provider" => "verify"], ["status" => "connected", "consent_version" => "v0.1", "connected_at" => now(), "created_at" => now(), "updated_at" => now()]); }' >/dev/null || true
php artisan tinker --execute 'if (Schema::hasTable("sleep_samples")) { DB::table("sleep_samples")->insert([["user_id" => 1, "source" => "verify", "recorded_at" => now()->subDays(1), "value_json" => json_encode(["duration_hours" => 3.5]), "confidence" => 1.0, "created_at" => now(), "updated_at" => now()], ["user_id" => 1, "source" => "verify", "recorded_at" => now()->subDays(2), "value_json" => json_encode(["duration_hours" => 7.5]), "confidence" => 1.0, "created_at" => now(), "updated_at" => now()], ["user_id" => 1, "source" => "verify", "recorded_at" => now()->subDays(3), "value_json" => json_encode(["duration_hours" => 4.0]), "confidence" => 1.0, "created_at" => now(), "updated_at" => now()]]); }' >/dev/null || true

AUTH_HEADER="Authorization: Bearer $TOKEN"

http_request() {
  local method="$1"
  local path="$2"
  local body="${3:-}"

  if [ "$USE_INTERNAL" -eq 1 ]; then
    local tmp_body
    if [[ "$path" != /api/* ]]; then
      path="/api/v0.2${path}"
    fi
    tmp_body=$(mktemp)
    printf "%s" "$body" > "$tmp_body"
    local response
    response=$(REQ_METHOD="$method" REQ_PATH="$path" REQ_BODY="$tmp_body" REQ_AUTH="$TOKEN" php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$method = getenv("REQ_METHOD") ?: "GET";
$path = getenv("REQ_PATH") ?: "/";
$bodyPath = getenv("REQ_BODY") ?: "";
$token = getenv("REQ_AUTH") ?: "";
$body = $bodyPath !== "" && file_exists($bodyPath) ? file_get_contents($bodyPath) : "";
$request = Illuminate\Http\Request::create($path, $method, [], [], [], [], $body);
$request->headers->set("Content-Type", "application/json");
if ($token !== "") { $request->headers->set("Authorization", "Bearer " . $token); }
$response = $kernel->handle($request);
echo (string) $response->getContent();
$kernel->terminate($request, $response);
');
    rm -f "$tmp_body"
    echo "$response"
  else
    if [ "$method" = "GET" ]; then
      curl -sS "$BASE_URL$path" -H "$AUTH_HEADER"
    else
      curl -sS -X "$method" "$BASE_URL$path" -H "$AUTH_HEADER" -H "Content-Type: application/json" -d "$body"
    fi
  fi
}

# 1) propose memory
MEM_RESP=$(http_request "POST" "/memory/propose" '{"content":"I prefer evening workouts","kind":"preference","tags":["workout"],"evidence":[{"type":"events","note":"manual"}],"source_refs":[{"type":"events","days":7}]}' )

MEM_ID=$(echo "$MEM_RESP" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["id"] ?? "";')

if [ -z "$MEM_ID" ]; then
  echo "Memory propose failed: $MEM_RESP" >&2
  exit 1
fi

# 2) confirm memory
CONF_RESP=$(http_request "POST" "/memory/$MEM_ID/confirm" "")

# 3) search
SEARCH_RESP=$(http_request "GET" "/memory/search?q=workouts" "")

# 4) export
EXPORT_RESP=$(http_request "GET" "/memory/export" "")

# 5) update agent settings
SETTINGS_RESP=$(http_request "POST" "/me/agent/settings" '{"enabled":true,"quiet_hours":{"start":"23:00","end":"06:00","timezone":"UTC"},"max_messages_per_day":2,"cooldown_minutes":1}' )

# 6) trigger AgentTick via job (sync)
php artisan tinker --execute '\\App\\Jobs\\AgentTickJob::dispatchSync();' >/dev/null || true

# 7) get agent messages
MSG_RESP=$(http_request "GET" "/me/agent/messages" "")
MSG_ID=$(echo "$MSG_RESP" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["items"][0]["id"] ?? "";')

# 8) feedback + ack
if [ -n "$MSG_ID" ]; then
  http_request "POST" "/me/agent/messages/$MSG_ID/feedback" '{"rating":"helpful","reason":"useful"}' >/dev/null
  http_request "POST" "/me/agent/messages/$MSG_ID/ack" "" >/dev/null
fi

SUMMARY="$ART_DIR/summary.txt"
cat <<SUMMARY_EOF > "$SUMMARY"
PORT=$PORT
memory_id=$MEM_ID
agent_message_id=$MSG_ID
memory_propose_response=$MEM_RESP
memory_confirm_response=$CONF_RESP
memory_search_response=$SEARCH_RESP
memory_export_response=$EXPORT_RESP
agent_settings_response=$SETTINGS_RESP
agent_messages_response=$MSG_RESP
SUMMARY_EOF

echo "OK: summary written to $SUMMARY"
