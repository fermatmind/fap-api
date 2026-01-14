#!/usr/bin/env bash
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="$REPO_DIR/backend"
API="${API:-http://127.0.0.1:18000}"
SQLITE_DB="${SQLITE_DB:-$BACKEND_DIR/database/database.sqlite}"

echo "[ACCEPT_D] repo=$REPO_DIR"
echo "[ACCEPT_D] backend=$BACKEND_DIR"
echo "[ACCEPT_D] API=$API"
echo "[ACCEPT_D] SQLITE_DB=$SQLITE_DB"

# 1) 先跑 C：保证 result_view/share_generate/share_click 三条链路能写出来
API="$API" SQLITE_DB="$SQLITE_DB" bash "$BACKEND_DIR/scripts/accept_events_C.sh"

# accept_events_C.sh 会在 stdout 打出 ATT / SHARE_ID；为了简单可靠，这里直接从 artifacts 读 ATT
ATT="$(jq -r '.attempt_id // .attemptId // empty' "$BACKEND_DIR/artifacts/verify_mbti/report.json" 2>/dev/null || true)"
if [[ -z "${ATT}" ]]; then
  echo "[ACCEPT_D][FAIL] cannot resolve ATT from artifacts/verify_mbti/report.json"
  exit 1
fi
echo "[ACCEPT_D] ATT=$ATT"

# 2) 在 testing/sqlite 同库检查 anon_id：result_view / share_generate / share_click 都必须非空且非占位符
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE="$SQLITE_DB" \
php -d detect_unicode=0 "$BACKEND_DIR/artisan" tinker --execute='
$att = getenv("ATT");

$badPatterns = [
  "把你查到的anon_id填这里",
  "anon_id填这里",
  "TODO",
  "placeholder",
  "dummy",
  "test_anon",
];

$getLast = function(string $code) use ($att) {
  return \DB::table("events")
    ->where("attempt_id",$att)
    ->where("event_code",$code)
    ->orderByDesc("occurred_at")
    ->first();
};

$mustNonEmptyNonPlaceholder = function(?string $anon, string $eventCode) use ($badPatterns) {
  $anon = is_string($anon) ? trim($anon) : "";
  if ($anon === "") {
    throw new \RuntimeException("FAIL: {$eventCode}.anon_id is empty");
  }
  foreach ($badPatterns as $p) {
    if ($p !== "" && str_contains($anon, $p)) {
      throw new \RuntimeException("FAIL: {$eventCode}.anon_id is placeholder (matched={$p}) anon_id={$anon}");
    }
  }
  return $anon;
};

$rv = $getLast("result_view");
$sg = $getLast("share_generate");
$sc = $getLast("share_click");

$rvMeta = $rv ? json_decode($rv->meta_json ?? "{}", true) : [];
$sgMeta = $sg ? json_decode($sg->meta_json ?? "{}", true) : [];
$scMeta = $sc ? json_decode($sc->meta_json ?? "{}", true) : [];

$out = [
  "ATT" => $att,
  "result_view" => [
    "occurred_at" => $rv->occurred_at ?? null,
    "anon_id" => $rv->anon_id ?? null,
    "type_code" => $rvMeta["type_code"] ?? null,
  ],
  "share_generate" => [
    "occurred_at" => $sg->occurred_at ?? null,
    "anon_id" => $sg->anon_id ?? null,
    "share_id" => $sgMeta["share_id"] ?? null,
  ],
  "share_click" => [
    "occurred_at" => $sc->occurred_at ?? null,
    "anon_id" => $sc->anon_id ?? null,
    "share_id" => $scMeta["share_id"] ?? null,
    "attempt_id" => $scMeta["attempt_id"] ?? null,
  ],
];

dump($out);

// hard asserts
$mustNonEmptyNonPlaceholder($rv->anon_id ?? null, "result_view");
$mustNonEmptyNonPlaceholder($sg->anon_id ?? null, "share_generate");
$mustNonEmptyNonPlaceholder($sc->anon_id ?? null, "share_click");

echo "[ACCEPT_D] PASS ✅" . PHP_EOL;
' ATT="$ATT"

