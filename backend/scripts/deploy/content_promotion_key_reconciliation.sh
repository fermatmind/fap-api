#!/usr/bin/env bash
set -euo pipefail

MODE="${MODE:-preflight}"
DEPLOY_PATH="${DEPLOY_PATH:-}"
EXPECTED_CONTROL_PLANE_SHA="${EXPECTED_CONTROL_PLANE_SHA:-}"
EXPECTED_ACTIVE_SHA="${EXPECTED_ACTIVE_SHA:-}"
EXPECTED_SOURCE_KEY_SHA256="${EXPECTED_SOURCE_KEY_SHA256:-}"
EXPECTED_ENV_SHA256="${EXPECTED_ENV_SHA256:-}"
EXPECTED_CONFIG_CACHE_SHA256="${EXPECTED_CONFIG_CACHE_SHA256:-}"
EXPECTED_ENV_KEY_SHA256="${EXPECTED_ENV_KEY_SHA256:-}"
EXPECTED_RUNTIME_KEY_SHA256="${EXPECTED_RUNTIME_KEY_SHA256:-}"
PREFLIGHT_RUN_ID="${PREFLIGHT_RUN_ID:-}"
PREFLIGHT_RUN_ATTEMPT="${PREFLIGHT_RUN_ATTEMPT:-}"
AUTHORIZATION_PHRASE="${AUTHORIZATION_PHRASE:-}"
CONTENT_PROMOTION_AUTOMATION_KEY="${CONTENT_PROMOTION_AUTOMATION_KEY:-}"

ZERO_SHA="$(printf '' | sha256sum | awk '{print $1}')"
env_sha="$ZERO_SHA"
cache_sha="$ZERO_SHA"
env_key_sha="$ZERO_SHA"
runtime_key_sha="$ZERO_SHA"
active_sha=""
writes_committed=false
env_setting_write_count=0
config_cache_rebuild_count=0

emit() {
  local ok="$1" status="$2" apply_ready="$3"
  jq -nc \
    --argjson ok "$ok" \
    --arg mode "$MODE" \
    --arg status "$status" \
    --arg control "$EXPECTED_CONTROL_PLANE_SHA" \
    --arg active "$active_sha" \
    --arg source_key_sha "$EXPECTED_SOURCE_KEY_SHA256" \
    --arg env_sha "$env_sha" \
    --arg cache_sha "$cache_sha" \
    --arg env_key_sha "$env_key_sha" \
    --arg runtime_key_sha "$runtime_key_sha" \
    --argjson apply_ready "$apply_ready" \
    --argjson writes_committed "$writes_committed" \
    --argjson env_setting_write_count "$env_setting_write_count" \
    --argjson config_cache_rebuild_count "$config_cache_rebuild_count" \
    '{schema_version:"fermatmind.content_promotion_key_reconciliation.v1",ok:$ok,mode:$mode,status:$status,control_plane_sha:$control,active_backend_sha:$active,source_key_sha256:$source_key_sha,env_sha256:$env_sha,config_cache_sha256:$cache_sha,env_key_sha256:$env_key_sha,runtime_key_sha256:$runtime_key_sha,apply_ready:$apply_ready,writes_committed:$writes_committed,env_setting_write_count:$env_setting_write_count,config_cache_rebuild_count:$config_cache_rebuild_count,secret_values_output:false,production_write_execution:$writes_committed,deploy_mutation_count:0,symlink_mutation_count:0,migration_mutation_count:0,database_mutation_count:0,cms_mutation_count:0,career_mutation_count:0,publication_mutation_count:0,indexability_mutation_count:0,sitemap_mutation_count:0,llms_mutation_count:0,search_mutation_count:0,cache_mutation_count:$config_cache_rebuild_count,queue_or_service_restart_count:0,automatic_rollback:false}'
}

fail() {
  emit false "$1" false
  exit 1
}

stat_links() { stat -c '%h' "$1" 2>/dev/null || stat -f '%l' "$1"; }
stat_mode() { stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1"; }

[[ "$MODE" =~ ^(preflight|apply)$ ]] || fail INVALID_MODE
[[ "$DEPLOY_PATH" =~ ^/[A-Za-z0-9._/-]+$ && "$DEPLOY_PATH" != *".."* ]] || fail INVALID_DEPLOY_PATH
[[ "$EXPECTED_CONTROL_PLANE_SHA" =~ ^[a-f0-9]{40}$ ]] || fail INVALID_CONTROL_PLANE_SHA
[[ "$EXPECTED_ACTIVE_SHA" =~ ^[a-f0-9]{40}$ ]] || fail INVALID_ACTIVE_SHA
[[ "$EXPECTED_SOURCE_KEY_SHA256" =~ ^[a-f0-9]{64}$ ]] || fail INVALID_SOURCE_KEY_SHA

current="$(readlink -f "$DEPLOY_PATH/current")"
[[ -n "$current" && -f "$current/REVISION" ]] || fail ACTIVE_RELEASE_MISSING
active_sha="$(tr -d '\r\n' < "$current/REVISION")"
[[ "$active_sha" = "$EXPECTED_ACTIVE_SHA" ]] || fail ACTIVE_SHA_DRIFT
[[ ! -e "$DEPLOY_PATH/.dep/deploy.lock" ]] || fail DEPLOY_LOCK_PRESENT

backend="$current/backend"
env_link="$backend/.env"
env_file="$(readlink -f "$env_link")"
cache_file="$backend/bootstrap/cache/config.php"
[[ -f "$env_file" && -f "$cache_file" ]] || fail RUNTIME_CONFIG_MISSING
[[ ! -L "$env_file" && "$(stat_links "$env_file")" = 1 ]] || fail ENV_FILE_UNSAFE
if LC_ALL=C grep -q $'\r' "$env_file"; then fail ENV_EOL_UNSAFE; fi

read_key() {
  local file="$1"
  php -r '
    $lines = file($argv[1], FILE_IGNORE_NEW_LINES);
    if ($lines === false) { exit(2); }
    $matches = [];
    foreach ($lines as $line) {
      if (preg_match("/\\ACONTENT_PROMOTION_AUTOMATION_KEY=(.*)\\z/", $line, $m) === 1) { $matches[] = $m[1]; }
    }
    if (count($matches) !== 1) { exit(3); }
    $value = $matches[0];
    if (strlen($value) >= 2 && (($value[0] === "\"" && substr($value, -1) === "\"") || ($value[0] === "\x27" && substr($value, -1) === "\x27"))) {
      $value = substr($value, 1, -1);
    }
    if (strlen($value) < 32) { exit(4); }
    echo hash("sha256", $value);
  ' "$file"
}

read_runtime_key() {
  php -r '
    $config = require $argv[1];
    $value = $config["content_promotion"]["workflow_identity_key"] ?? "";
    if (!is_string($value) || strlen($value) < 32) { exit(3); }
    echo hash("sha256", $value);
  ' "$cache_file"
}

env_sha="$(sha256sum "$env_file" | awk '{print $1}')"
cache_sha="$(sha256sum "$cache_file" | awk '{print $1}')"
env_key_sha="$(read_key "$env_file")" || fail ENV_KEY_INVALID
runtime_key_sha="$(read_runtime_key)" || fail RUNTIME_KEY_INVALID
[[ "$env_key_sha" = "$runtime_key_sha" ]] || fail ENV_RUNTIME_KEY_DRIFT

if [[ "$MODE" = preflight ]]; then
  if [[ "$env_key_sha" = "$EXPECTED_SOURCE_KEY_SHA256" ]]; then
    emit true PASS_ALREADY_ALIGNED false
  else
    emit true PASS_RECONCILIATION_REQUIRED true
  fi
  exit 0
fi

for value in "$EXPECTED_ENV_SHA256" "$EXPECTED_CONFIG_CACHE_SHA256" "$EXPECTED_ENV_KEY_SHA256" "$EXPECTED_RUNTIME_KEY_SHA256"; do
  [[ "$value" =~ ^[a-f0-9]{64}$ ]] || fail INVALID_APPLY_BINDING
done
[[ "$PREFLIGHT_RUN_ID" =~ ^[1-9][0-9]{0,19}$ && "$PREFLIGHT_RUN_ATTEMPT" =~ ^[1-9][0-9]{0,9}$ ]] || fail INVALID_PREFLIGHT_IDENTITY
[[ "$env_sha" = "$EXPECTED_ENV_SHA256" ]] || fail ENV_FILE_DRIFT
[[ "$cache_sha" = "$EXPECTED_CONFIG_CACHE_SHA256" ]] || fail CONFIG_CACHE_DRIFT
[[ "$env_key_sha" = "$EXPECTED_ENV_KEY_SHA256" ]] || fail ENV_KEY_DRIFT
[[ "$runtime_key_sha" = "$EXPECTED_RUNTIME_KEY_SHA256" ]] || fail RUNTIME_KEY_DRIFT
[[ "$env_key_sha" != "$EXPECTED_SOURCE_KEY_SHA256" ]] || fail ALREADY_ALIGNED
[[ ${#CONTENT_PROMOTION_AUTOMATION_KEY} -ge 32 ]] || fail SOURCE_KEY_MISSING
[[ "$CONTENT_PROMOTION_AUTOMATION_KEY" =~ ^[A-Za-z0-9._~+/=-]+$ ]] || fail SOURCE_KEY_FORMAT_UNSAFE
[[ "$(printf '%s' "$CONTENT_PROMOTION_AUTOMATION_KEY" | sha256sum | awk '{print $1}')" = "$EXPECTED_SOURCE_KEY_SHA256" ]] || fail SOURCE_KEY_DRIFT

expected_phrase="I explicitly approve production fap-api content-promotion key reconciliation from preflight run ${PREFLIGHT_RUN_ID} attempt ${PREFLIGHT_RUN_ATTEMPT} with control-plane SHA ${EXPECTED_CONTROL_PLANE_SHA} active SHA ${EXPECTED_ACTIVE_SHA} environment SHA256 ${EXPECTED_ENV_SHA256} config-cache SHA256 ${EXPECTED_CONFIG_CACHE_SHA256} environment-key SHA256 ${EXPECTED_ENV_KEY_SHA256} runtime-key SHA256 ${EXPECTED_RUNTIME_KEY_SHA256} source-key SHA256 ${EXPECTED_SOURCE_KEY_SHA256}; write only CONTENT_PROMOTION_AUTOMATION_KEY and rebuild only Laravel config cache, no deploy/symlink/migration/CMS/database/Career/cache-publication/queue/service-restart/publication/indexability/sitemap/llms/search/automatic rollback."
[[ "$AUTHORIZATION_PHRASE" = "$expected_phrase" ]] || fail AUTHORIZATION_PHRASE_MISMATCH

env_dir="$(dirname "$env_file")"
env_candidate="$(mktemp "$env_dir/.content-promotion-env.XXXXXX")"
cache_dir="$(dirname "$cache_file")"
cache_candidate="$(mktemp "$cache_dir/.content-promotion-config.XXXXXX.php")"
cleanup() { rm -f "$env_candidate" "$cache_candidate"; }
trap cleanup EXIT

export CONTENT_PROMOTION_AUTOMATION_KEY
php -r '
  $source = file_get_contents($argv[1]);
  if ($source === false) { exit(2); }
  $secret = getenv("CONTENT_PROMOTION_AUTOMATION_KEY");
  $count = 0;
  $candidate = preg_replace_callback(
    "/^CONTENT_PROMOTION_AUTOMATION_KEY=.*$/m",
    static function () use ($secret, &$count): string { $count++; return "CONTENT_PROMOTION_AUTOMATION_KEY=".$secret; },
    $source,
  );
  if ($count !== 1) { exit(3); }
  if (!is_string($candidate) || file_put_contents($argv[2], $candidate) === false) { exit(4); }
' "$env_file" "$env_candidate" || fail ENV_CANDIDATE_FAILED
chmod "$(stat_mode "$env_file")" "$env_candidate"
[[ "$(read_key "$env_candidate")" = "$EXPECTED_SOURCE_KEY_SHA256" ]] || fail ENV_CANDIDATE_INVALID

(
  cd "$backend"
  APP_CONFIG_CACHE="$cache_candidate" CONTENT_PROMOTION_AUTOMATION_KEY="$CONTENT_PROMOTION_AUTOMATION_KEY" \
    timeout 120 php artisan config:cache --no-ansi >/dev/null 2>&1
) || fail CONFIG_CACHE_CANDIDATE_FAILED
[[ "$(php -r '$config=require $argv[1]; $value=$config["content_promotion"]["workflow_identity_key"]??""; echo is_string($value)?hash("sha256",$value):"";' "$cache_candidate")" = "$EXPECTED_SOURCE_KEY_SHA256" ]] || fail CONFIG_CACHE_CANDIDATE_INVALID

chmod "$(stat_mode "$cache_file")" "$cache_candidate"
mv -f "$env_candidate" "$env_file"
env_setting_write_count=1
writes_committed=true
mv -f "$cache_candidate" "$cache_file"
config_cache_rebuild_count=1

env_sha="$(sha256sum "$env_file" | awk '{print $1}')"
cache_sha="$(sha256sum "$cache_file" | awk '{print $1}')"
env_key_sha="$(read_key "$env_file")" || fail POST_ENV_KEY_INVALID
runtime_key_sha="$(read_runtime_key)" || fail POST_RUNTIME_KEY_INVALID
[[ "$env_key_sha" = "$EXPECTED_SOURCE_KEY_SHA256" && "$runtime_key_sha" = "$EXPECTED_SOURCE_KEY_SHA256" ]] || fail POST_RECONCILIATION_MISMATCH

emit true PASS_KEY_RECONCILED false
