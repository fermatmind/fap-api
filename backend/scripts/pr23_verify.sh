#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1

SERVE_PORT="${SERVE_PORT:-1823}"
API_BASE="http://127.0.0.1:${SERVE_PORT}"
ORG_ID="${ORG_ID:-0}"
DB_DATABASE="${DB_DATABASE:-/tmp/pr23.sqlite}"
export DB_DATABASE
export ORG_ID

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
BACKEND_DIR="$ROOT_DIR/backend"
ART_DIR="$ROOT_DIR/backend/artifacts/pr23"

mkdir -p "$ART_DIR"
LOG_FILE="$ART_DIR/verify.log"
: > "$LOG_FILE"

log() {
  echo "[$(date +'%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

cleanup_port() {
  local port="$1"
  local pids
  pids="$(lsof -ti tcp:"$port" 2>/dev/null || true)"
  if [[ -n "$pids" ]]; then
    kill -9 $pids || true
  fi
}

log "Cleaning ports ${SERVE_PORT} and 18000"
cleanup_port "$SERVE_PORT"
cleanup_port 18000

SERVER_PID=""
cleanup() {
  if [[ -n "$SERVER_PID" ]]; then
    kill "$SERVER_PID" >/dev/null 2>&1 || true
  fi
  cleanup_port "$SERVE_PORT"
  cleanup_port 18000
}
trap cleanup EXIT

log "Starting server on port ${SERVE_PORT}"
(
  cd "$BACKEND_DIR"
  php artisan serve --host=127.0.0.1 --port="$SERVE_PORT"
) > "$ART_DIR/server.log" 2>&1 &
SERVER_PID=$!

echo "$SERVER_PID" > "$ART_DIR/server.pid"

log "Waiting for health"
health_code=""
for _i in $(seq 1 40); do
  health_code="$(curl -s -o /dev/null -w "%{http_code}" "$API_BASE/api/healthz" || true)"
  if [[ "$health_code" == "200" ]]; then
    break
  fi
  sleep 0.5
done
if [[ "$health_code" != "200" ]]; then
  log "Health check failed: ${health_code}"
  tail -n 120 "$ART_DIR/server.log" | tee -a "$LOG_FILE" || true
  exit 1
fi

log "Generating anon_id pair"
PAIR_JSON="$ART_DIR/anon_pair.json"
cat <<'PHP' > /tmp/pr23_pair.php
<?php
$salt = getenv('FAP_EXPERIMENTS_SALT');
if ($salt === false || $salt === '') {
    $salt = 'pr23_sticky_bucket_v1';
}
$orgId = (int) (getenv('ORG_ID') ?: 0);
$experiment = 'PR23_STICKY_BUCKET';

$bucket = function (string $value): int {
    $hash = hash('sha256', $value);
    $slice = substr($hash, 0, 8);
    $num = hexdec($slice);
    return $num % 100;
};

$variantFor = function (string $anonId) use ($bucket, $orgId, $experiment, $salt): string {
    $subject = 'anon:' . $anonId;
    $b = $bucket($subject . '|' . $orgId . '|' . $experiment . '|' . $salt);
    return $b < 50 ? 'A' : 'B';
};

$base = 'pr23_anon_';
$first = null;
$firstVariant = null;
$second = null;
$secondVariant = null;
for ($i = 0; $i < 500; $i++) {
    $anon = $base . $i;
    $variant = $variantFor($anon);
    if ($first === null) {
        $first = $anon;
        $firstVariant = $variant;
        continue;
    }
    if ($variant !== $firstVariant) {
        $second = $anon;
        $secondVariant = $variant;
        break;
    }
}

if ($second === null) {
    $second = $base . 'fallback';
    $secondVariant = $firstVariant === 'A' ? 'B' : 'A';
}

echo json_encode([
    'anon_a' => $first,
    'anon_b' => $second,
    'variant_a' => $firstVariant,
    'variant_b' => $secondVariant,
], JSON_UNESCAPED_SLASHES);
PHP
ORG_ID="$ORG_ID" FAP_EXPERIMENTS_SALT="${FAP_EXPERIMENTS_SALT:-}" php /tmp/pr23_pair.php > "$PAIR_JSON"

ANON_A="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["anon_a"] ?? "";' "$PAIR_JSON")"
ANON_B="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["anon_b"] ?? "";' "$PAIR_JSON")"
VAR_A_EXPECTED="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["variant_a"] ?? "";' "$PAIR_JSON")"
VAR_B_EXPECTED="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["variant_b"] ?? "";' "$PAIR_JSON")"
export ANON_A

if [[ -z "$ANON_A" || -z "$ANON_B" ]]; then
  log "anon_id generation failed"
  exit 1
fi

curl_json() {
  local url="$1"
  local out="$2"
  local code
  shift 2
  code="$(curl -sS -o "$out" -w "%{http_code}" "$@" "$url" || true)"
  printf '%s' "$code"
}

assert_json() {
  local path="$1"
  if [[ ! -f "$path" || ! -s "$path" ]]; then
    log "json missing or empty: $path"
    exit 1
  fi
  if ! php -r 'json_decode(file_get_contents($argv[1]), true); if (json_last_error() !== JSON_ERROR_NONE) { exit(1);} ' "$path"; then
    log "invalid json: $path"
    exit 1
  fi
}

log "GET /api/v0.3/boot (A)"
BOOT_A="$ART_DIR/curl_boot_a.json"
code_a=$(curl_json "$API_BASE/api/v0.3/boot" "$BOOT_A" \
  -H "Accept: application/json" \
  -H "X-Org-Id: $ORG_ID" \
  -H "X-Anon-Id: $ANON_A")
if [[ "$code_a" != "200" ]]; then
  log "boot A failed: HTTP=$code_a"
  tail -n 120 "$ART_DIR/server.log" | tee -a "$LOG_FILE" || true
  exit 1
fi
assert_json "$BOOT_A"

log "GET /api/v0.3/boot (B)"
BOOT_B="$ART_DIR/curl_boot_b.json"
code_b=$(curl_json "$API_BASE/api/v0.3/boot" "$BOOT_B" \
  -H "Accept: application/json" \
  -H "X-Org-Id: $ORG_ID" \
  -H "X-Anon-Id: $ANON_B")
if [[ "$code_b" != "200" ]]; then
  log "boot B failed: HTTP=$code_b"
  tail -n 120 "$ART_DIR/server.log" | tee -a "$LOG_FILE" || true
  exit 1
fi
assert_json "$BOOT_B"

log "GET /api/v0.3/boot (A repeat)"
BOOT_A2="$ART_DIR/curl_boot_a_repeat.json"
code_a2=$(curl_json "$API_BASE/api/v0.3/boot" "$BOOT_A2" \
  -H "Accept: application/json" \
  -H "X-Org-Id: $ORG_ID" \
  -H "X-Anon-Id: $ANON_A")
if [[ "$code_a2" != "200" ]]; then
  log "boot A repeat failed: HTTP=$code_a2"
  tail -n 120 "$ART_DIR/server.log" | tee -a "$LOG_FILE" || true
  exit 1
fi
assert_json "$BOOT_A2"

VAR_A="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["experiments"]["PR23_STICKY_BUCKET"] ?? "";' "$BOOT_A")"
VAR_B="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["experiments"]["PR23_STICKY_BUCKET"] ?? "";' "$BOOT_B")"
VAR_A2="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["experiments"]["PR23_STICKY_BUCKET"] ?? "";' "$BOOT_A2")"

if [[ -z "$VAR_A" || -z "$VAR_B" ]]; then
  log "missing experiment variants in boot response"
  exit 1
fi

if [[ "$VAR_A" != "$VAR_A_EXPECTED" || "$VAR_B" != "$VAR_B_EXPECTED" ]]; then
  log "variant mismatch with hash output"
  exit 1
fi

if [[ "$VAR_A" == "$VAR_B" ]]; then
  log "expected different variants for A/B"
  exit 1
fi

if [[ "$VAR_A" != "$VAR_A2" ]]; then
  log "expected sticky variant for A"
  exit 1
fi

log "DB assertions (sticky assignments)"
DB_ASSERT="$ART_DIR/db_assertions.json"
EXP_AGG="$ART_DIR/experiments_agg.json"
VAR_A="$VAR_A" VAR_B="$VAR_B" ANON_A="$ANON_A" ANON_B="$ANON_B" ORG_ID="$ORG_ID" php -r '
$dbFile = getenv("DB_DATABASE") ?: "/tmp/pr23.sqlite";
$anonA = getenv("ANON_A") ?: "";
$anonB = getenv("ANON_B") ?: "";
$expectedA = getenv("VAR_A") ?: "";
$expectedB = getenv("VAR_B") ?: "";
$orgId = (int) (getenv("ORG_ID") ?: 0);
$db = new PDO("sqlite:" . $dbFile);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$stmt = $db->prepare("select variant from experiment_assignments where org_id = ? and anon_id = ? and experiment_key = ? limit 1");
$stmt->execute([$orgId, $anonA, "PR23_STICKY_BUCKET"]);
$assignA = $stmt->fetch(PDO::FETCH_ASSOC);
$stmt->execute([$orgId, $anonB, "PR23_STICKY_BUCKET"]);
$assignB = $stmt->fetch(PDO::FETCH_ASSOC);
$variantA = is_array($assignA) ? (string) ($assignA["variant"] ?? "") : "";
$variantB = is_array($assignB) ? (string) ($assignB["variant"] ?? "") : "";
$ok = ($variantA !== "" && $variantB !== "" && $variantA === $expectedA && $variantB === $expectedB);
$out = [
  "ok" => $ok,
  "boot_variant_a" => $expectedA,
  "boot_variant_b" => $expectedB,
  "assignment_variant_a" => $variantA,
  "assignment_variant_b" => $variantB,
];
file_put_contents($argv[1], json_encode($out, JSON_UNESCAPED_SLASHES));
if (!$ok) {
  fwrite(STDERR, "sticky assignment mismatch\n");
  exit(1);
}
$rows = $db->query("select variant, count(*) as c from experiment_assignments where org_id = {$orgId} and experiment_key = \"PR23_STICKY_BUCKET\" group by variant")->fetchAll(PDO::FETCH_ASSOC);
$counts = ["A" => 0, "B" => 0];
foreach ($rows as $r) {
  $v = (string) ($r["variant"] ?? "");
  $c = (int) ($r["c"] ?? 0);
  if ($v === "A" || $v === "B") {
    $counts[$v] = $c;
  }
}
file_put_contents($argv[2], json_encode($counts, JSON_UNESCAPED_SLASHES));
' "$DB_ASSERT" "$EXP_AGG"

log "Verify port cleanup"
cleanup_port "$SERVE_PORT"
cleanup_port 18000
if lsof -nP -iTCP:"$SERVE_PORT" -sTCP:LISTEN >/dev/null 2>&1; then
  log "Port ${SERVE_PORT} still in use"
  exit 1
fi

log "pr23_verify done"
