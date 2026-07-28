#!/usr/bin/env bash
set -euo pipefail

MODE="${MODE:-preflight}"
DEPLOY_PATH="${DEPLOY_PATH:-}"
MANAGED_DEPLOY_PATH="${MANAGED_DEPLOY_PATH:-/var/www/fap-api}"
EXPECTED_CONTROL_PLANE_SHA="${EXPECTED_CONTROL_PLANE_SHA:-}"
EXPECTED_ACTIVE_SHA="${EXPECTED_ACTIVE_SHA:-}"
EXPECTED_ENV_SHA256="${EXPECTED_ENV_SHA256:-}"
EXPECTED_CONFIG_CACHE_SHA256="${EXPECTED_CONFIG_CACHE_SHA256:-}"
EXPECTED_CONFIG_SOURCE_SHA256="${EXPECTED_CONFIG_SOURCE_SHA256:-}"
EXPECTED_RUNTIME_FINGERPRINT_SHA256="${EXPECTED_RUNTIME_FINGERPRINT_SHA256:-}"
EXPECTED_SOURCE_BUNDLE_SHA256="${EXPECTED_SOURCE_BUNDLE_SHA256:-}"
PREFLIGHT_RUN_ID="${PREFLIGHT_RUN_ID:-}"
PREFLIGHT_RUN_ATTEMPT="${PREFLIGHT_RUN_ATTEMPT:-}"
AUTHORIZATION_PHRASE="${AUTHORIZATION_PHRASE:-}"
CONTENT_RELEASE_REVALIDATE_SECRET="${CONTENT_RELEASE_REVALIDATE_SECRET:-}"
REVALIDATION_URL="https://fermatmind.com/api/content-release/revalidate"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
ZERO_SHA256="0000000000000000000000000000000000000000000000000000000000000000"
WRITES_COMMITTED=false
ENV_SETTING_WRITE_COUNT=0
CONFIG_CACHE_REBUILD_ATTEMPTED=false
CONFIG_CACHE_REBUILD_COMMITTED=false
ENV_PERMISSION_NORMALIZED=false
SECRET_PATTERN='^[A-Za-z0-9_-]+$'
TARGET_ENV_MODE='0640'

fail() {
  jq -cnS \
    --arg error_code "$1" \
    --argjson writes_committed "$WRITES_COMMITTED" \
    --argjson env_setting_write_count "$ENV_SETTING_WRITE_COUNT" \
    --argjson config_cache_rebuild_attempted "$CONFIG_CACHE_REBUILD_ATTEMPTED" \
    --argjson config_cache_rebuild_committed "$CONFIG_CACHE_REBUILD_COMMITTED" \
    --argjson env_permission_normalized "$ENV_PERMISSION_NORMALIZED" \
    '{
      ok: false,
      status: (if $writes_committed then "FAIL_CLOSED_PARTIAL_CONFIG_WRITE" else "FAIL_CLOSED_NO_WRITES" end),
      error_code: $error_code,
      writes_committed: $writes_committed,
      env_setting_write_count: $env_setting_write_count,
      config_cache_rebuild_attempted: $config_cache_rebuild_attempted,
      config_cache_rebuild_committed: $config_cache_rebuild_committed,
      env_permission_normalized: $env_permission_normalized,
      application_deploy: false,
      symlink_activation: false,
      migration: false,
      cms_or_database_authority_write: false,
      public_cache_revalidation: false,
      queue_or_service_restart: false,
      automatic_rollback: false,
      secret_values_output: false
    }'
  exit 1
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || fail "MISSING_REQUIRED_COMMAND"
}

file_sha256() {
  local file="$1"
  if [[ -f "$file" ]]; then
    sha256sum "$file" | cut -d' ' -f1
  else
    printf '%s' "$ZERO_SHA256"
  fi
}

canonical_path() {
  # $argv is evaluated by PHP, not Bash.
  # shellcheck disable=SC2016
  php -r '$path = realpath($argv[1]); if ($path === false) { exit(1); } echo $path;' "$1"
}

bundle_sha256() {
  local secret="$1"
  local url="$2"
  if [[ -z "$secret" || -z "$url" ]]; then
    printf '%s' "$ZERO_SHA256"
    return
  fi
  printf '%s\0%s' "$secret" "$url" | sha256sum | cut -d' ' -f1
}

env_value() {
  local key="$1"
  local value
  value="$(sed -n "s/^${key}=//p" "$ENV_FILE" | tail -n 1)"
  value="${value#\"}"
  value="${value%\"}"
  printf '%s' "$value"
}

file_metadata() {
  # $argv is evaluated by PHP, not Bash.
  # shellcheck disable=SC2016
  php -r '
    $state = stat($argv[1]);
    if ($state === false) {
        exit(1);
    }
    echo json_encode([
        "mode" => sprintf("%04o", $state["mode"] & 0777),
        "uid" => (string) $state["uid"],
        "gid" => (string) $state["gid"],
    ], JSON_THROW_ON_ERROR);
  ' "$1"
}

cached_bundle_state() {
  CACHE_FILE="$CONFIG_CACHE_FILE" php <<'PHP'
<?php
$path = getenv('CACHE_FILE') ?: '';
$config = is_file($path) ? require $path : [];
$observability = is_array($config)
    ? ($config['ops']['content_release_observability'] ?? [])
    : [];
$secret = is_array($observability)
    ? (string) ($observability['hmac_revalidation_secret'] ?? '')
    : '';
$url = is_array($observability)
    ? trim((string) ($observability['hmac_revalidation_url'] ?? ''))
    : '';
$bundle = $secret !== '' && $url !== ''
    ? hash('sha256', $secret."\0".$url)
    : str_repeat('0', 64);
echo json_encode([
    'cache_present' => is_file($path),
    'secret_present' => $secret !== '',
    'url_present' => $url !== '',
    'bundle_sha256' => $bundle,
], JSON_THROW_ON_ERROR);
PHP
}

runtime_state() {
  local active_sha="$1"
  local env_sha="$2"
  local config_cache_sha="$3"
  local config_source_sha="$4"
  local source_bundle_sha="$5"
  local env_bundle_sha="$6"
  local cached_state="$7"
  local deploy_lock_present="$8"
  local source_ready="$9"
  local env_mode="${10}"
  local env_uid="${11}"
  local env_gid="${12}"
  local env_runtime_readable="${13}"

  jq -cnS \
    --arg schema_version "content_release_revalidation_config_cache.v1" \
    --arg mode "$MODE" \
    --arg control_plane_sha "$EXPECTED_CONTROL_PLANE_SHA" \
    --arg active_backend_sha "$active_sha" \
    --arg expected_active_backend_sha "$EXPECTED_ACTIVE_SHA" \
    --arg env_sha256 "$env_sha" \
    --arg config_cache_sha256 "$config_cache_sha" \
    --arg config_source_sha256 "$config_source_sha" \
    --arg source_bundle_sha256 "$source_bundle_sha" \
    --arg env_bundle_sha256 "$env_bundle_sha" \
    --argjson cached "$cached_state" \
    --argjson deploy_lock_present "$deploy_lock_present" \
    --argjson source_ready "$source_ready" \
    --arg env_mode "$env_mode" \
    --arg env_target_mode "$TARGET_ENV_MODE" \
    --arg env_uid "$env_uid" \
    --arg env_gid "$env_gid" \
    --argjson env_runtime_readable "$env_runtime_readable" \
    '{
      schema_version: $schema_version,
      mode: $mode,
      control_plane_sha: $control_plane_sha,
      active_backend_sha: $active_backend_sha,
      expected_active_backend_sha: $expected_active_backend_sha,
      active_revision_matches: ($active_backend_sha == $expected_active_backend_sha),
      env_sha256: $env_sha256,
      config_cache_sha256: $config_cache_sha256,
      config_source_sha256: $config_source_sha256,
      source_bundle_sha256: $source_bundle_sha256,
      env_bundle_sha256: $env_bundle_sha256,
      cached_bundle_sha256: $cached.bundle_sha256,
      cached_secret_present: $cached.secret_present,
      cached_url_present: $cached.url_present,
      source_config_ready: $source_ready,
      env_matches_source: ($env_bundle_sha256 == $source_bundle_sha256),
      cached_config_matches_source: ($cached.bundle_sha256 == $source_bundle_sha256),
      config_residue_present: (
        $cached.secret_present
        and $cached.bundle_sha256 != $source_bundle_sha256
      ),
      env_mode: $env_mode,
      env_target_mode: $env_target_mode,
      env_uid: $env_uid,
      env_gid: $env_gid,
      env_runtime_readable: $env_runtime_readable,
      env_permission_repair_required: (
        $env_mode != $env_target_mode
        or $env_runtime_readable == false
      ),
      deploy_lock_present: $deploy_lock_present,
      production_write_execution: false,
      writes_committed: false,
      application_deploy: false,
      symlink_activation: false,
      migration: false,
      cms_or_database_authority_write: false,
      public_cache_revalidation: false,
      queue_or_service_restart: false,
      automatic_rollback: false,
      secret_values_output: false
    }'
}

runtime_fingerprint_sha256() {
  jq -cS 'del(.mode, .runtime_fingerprint_sha256, .ok, .status, .apply_ready)' \
    | sha256sum | cut -d' ' -f1
}

write_managed_env() {
  local temp_file="${ENV_FILE}.tmp.$$"
  umask 077

  ENV_FILE="$ENV_FILE" \
    TEMP_FILE="$temp_file" \
    CONTENT_RELEASE_REVALIDATE_SECRET="$CONTENT_RELEASE_REVALIDATE_SECRET" \
    ENNEAGRAM_AUTHORITY_V2_REVALIDATION_URL="$REVALIDATION_URL" \
    php <<'PHP'
<?php
$target = getenv('ENV_FILE') ?: '';
$temp = getenv('TEMP_FILE') ?: '';
$targetOwner = fileowner($target);
$targetGroup = filegroup($target);
if ($targetOwner === false || $targetGroup === false) {
    exit(5);
}
$values = [
    'CONTENT_RELEASE_REVALIDATE_SECRET' => getenv('CONTENT_RELEASE_REVALIDATE_SECRET') ?: '',
    'ENNEAGRAM_AUTHORITY_V2_REVALIDATION_URL' => getenv('ENNEAGRAM_AUTHORITY_V2_REVALIDATION_URL') ?: '',
];
$lines = is_file($target) ? preg_split('/\R/', (string) file_get_contents($target)) : [];
$lines = is_array($lines) ? $lines : [];
$lines = array_values(array_filter(
    $lines,
    static fn (string $line): bool => ! array_key_exists(strtok($line, '=') ?: '', $values),
));
while ($lines !== [] && end($lines) === '') {
    array_pop($lines);
}
foreach ($values as $key => $value) {
    if ($value === '' || preg_match('/[\r\n]/', $value) === 1) {
        exit(2);
    }
    $lines[] = $key.'='.$value;
}
if (file_put_contents($temp, implode("\n", $lines)."\n", LOCK_EX) === false) {
    exit(3);
}
if (fileowner($temp) !== $targetOwner) {
    @unlink($temp);
    exit(5);
}
if (! chown($temp, $targetOwner) || ! chgrp($temp, $targetGroup) || ! chmod($temp, 0640)) {
    @unlink($temp);
    exit(6);
}
if (! rename($temp, $target)) {
    @unlink($temp);
    exit(4);
}
PHP
}

require_command jq
require_command php
require_command sha256sum
require_command sed
require_command sudo
require_command timeout
[[ -x "$PHP_BIN" ]] || fail "INVALID_PHP_BINARY"

[[ "$MODE" == "preflight" || "$MODE" == "apply" ]] || fail "INVALID_MODE"
[[ "$EXPECTED_CONTROL_PLANE_SHA" =~ ^[0-9a-f]{40}$ ]] || fail "INVALID_CONTROL_PLANE_SHA"
[[ "$EXPECTED_ACTIVE_SHA" =~ ^[0-9a-f]{40}$ ]] || fail "INVALID_ACTIVE_SHA"
[[ "$DEPLOY_PATH" == "$MANAGED_DEPLOY_PATH" ]] || fail "UNMANAGED_DEPLOY_PATH"
[[ -d "$DEPLOY_PATH" && -L "$DEPLOY_PATH/current" ]] || fail "INVALID_DEPLOY_ROOT"
[[ ! -e "$DEPLOY_PATH/.dep/deploy.lock" ]] || fail "DEPLOY_LOCK_PRESENT"

CURRENT_RELEASE="$(canonical_path "$DEPLOY_PATH/current")" || fail "CURRENT_RELEASE_RESOLVE_FAILED"
RELEASES_ROOT="$(canonical_path "$DEPLOY_PATH/releases")" || fail "RELEASES_ROOT_RESOLVE_FAILED"
[[ "$CURRENT_RELEASE" == "$RELEASES_ROOT/"* ]] || fail "CURRENT_RELEASE_OUTSIDE_MANAGED_ROOT"
[[ -f "$CURRENT_RELEASE/REVISION" ]] || fail "ACTIVE_REVISION_MISSING"
ACTIVE_BACKEND_SHA="$(tr -d '[:space:]' < "$CURRENT_RELEASE/REVISION")"
[[ "$ACTIVE_BACKEND_SHA" == "$EXPECTED_ACTIVE_SHA" ]] || fail "ACTIVE_REVISION_DRIFT"

BACKEND_DIR="$CURRENT_RELEASE/backend"
ENV_FILE="$DEPLOY_PATH/shared/backend/.env"
CONFIG_CACHE_FILE="$BACKEND_DIR/bootstrap/cache/config.php"
CONFIG_SOURCE_FILE="$BACKEND_DIR/config/ops.php"
[[ -d "$BACKEND_DIR" && -f "$ENV_FILE" && -f "$CONFIG_SOURCE_FILE" ]] || fail "MANAGED_CONFIG_PATH_MISSING"
ENV_REALPATH="$(canonical_path "$ENV_FILE")" || fail "ENV_RESOLVE_FAILED"
EXPECTED_ENV_REALPATH="$(canonical_path "$DEPLOY_PATH/shared/backend/.env")" || fail "ENV_BOUNDARY_RESOLVE_FAILED"
[[ "$ENV_REALPATH" == "$EXPECTED_ENV_REALPATH" ]] || fail "ENV_OUTSIDE_MANAGED_BOUNDARY"
BACKEND_ENV_REALPATH="$(canonical_path "$BACKEND_DIR/.env")" || fail "ACTIVE_ENV_RESOLVE_FAILED"
[[ "$BACKEND_ENV_REALPATH" == "$ENV_REALPATH" ]] || fail "ACTIVE_ENV_LINK_DRIFT"

SOURCE_SECRET="$CONTENT_RELEASE_REVALIDATE_SECRET"
SOURCE_BUNDLE_SHA256="$(bundle_sha256 "$SOURCE_SECRET" "$REVALIDATION_URL")"
SOURCE_READY=false
if (( ${#SOURCE_SECRET} >= 32 && ${#SOURCE_SECRET} <= 256 )) \
  && [[ "$SOURCE_SECRET" =~ $SECRET_PATTERN ]]; then
  SOURCE_READY=true
fi

ENV_SECRET="$(env_value CONTENT_RELEASE_REVALIDATE_SECRET)"
ENV_URL="$(env_value ENNEAGRAM_AUTHORITY_V2_REVALIDATION_URL)"
ENV_BUNDLE_SHA256="$(bundle_sha256 "$ENV_SECRET" "$ENV_URL")"
ENV_SHA256="$(file_sha256 "$ENV_FILE")"
ENV_METADATA="$(file_metadata "$ENV_FILE")" || fail "ENV_METADATA_READ_FAILED"
ENV_MODE="$(jq -r '.mode' <<<"$ENV_METADATA")"
ENV_UID="$(jq -r '.uid' <<<"$ENV_METADATA")"
ENV_GID="$(jq -r '.gid' <<<"$ENV_METADATA")"
ENV_RUNTIME_READABLE=false
if sudo -n -u www-data -- test -r "$ENV_FILE"; then
  ENV_RUNTIME_READABLE=true
fi
CONFIG_CACHE_SHA256="$(file_sha256 "$CONFIG_CACHE_FILE")"
CONFIG_SOURCE_SHA256="$(file_sha256 "$CONFIG_SOURCE_FILE")"
CACHED_STATE="$(cached_bundle_state)" || fail "CONFIG_CACHE_INSPECTION_FAILED"
DEPLOY_LOCK_PRESENT=false

STATE="$(runtime_state \
  "$ACTIVE_BACKEND_SHA" \
  "$ENV_SHA256" \
  "$CONFIG_CACHE_SHA256" \
  "$CONFIG_SOURCE_SHA256" \
  "$SOURCE_BUNDLE_SHA256" \
  "$ENV_BUNDLE_SHA256" \
  "$CACHED_STATE" \
  "$DEPLOY_LOCK_PRESENT" \
  "$SOURCE_READY" \
  "$ENV_MODE" \
  "$ENV_UID" \
  "$ENV_GID" \
  "$ENV_RUNTIME_READABLE")" || fail "RUNTIME_STATE_BUILD_FAILED"
RUNTIME_FINGERPRINT_SHA256="$(printf '%s' "$STATE" | runtime_fingerprint_sha256)"
APPLY_READY="$(jq -r '
  .active_revision_matches
  and .source_config_ready
  and (.config_source_sha256 | test("^[0-9a-f]{64}$"))
  and .deploy_lock_present == false
' <<<"$STATE")"

if [[ "$MODE" == "preflight" ]]; then
  jq -cS \
    --arg runtime_fingerprint_sha256 "$RUNTIME_FINGERPRINT_SHA256" \
    --argjson apply_ready "$APPLY_READY" \
    '. + {
      ok: true,
      status: (if $apply_ready then "PASS_AUTHORIZATION_REQUIRED" else "PASS_PREFLIGHT_CONFIG_INPUT_REQUIRED" end),
      apply_ready: $apply_ready,
      runtime_fingerprint_sha256: $runtime_fingerprint_sha256
    }' <<<"$STATE"
  exit 0
fi

[[ "$EXPECTED_ENV_SHA256" =~ ^[0-9a-f]{64}$ ]] || fail "INVALID_EXPECTED_ENV_SHA256"
[[ "$EXPECTED_CONFIG_CACHE_SHA256" =~ ^[0-9a-f]{64}$ ]] || fail "INVALID_EXPECTED_CONFIG_CACHE_SHA256"
[[ "$EXPECTED_CONFIG_SOURCE_SHA256" =~ ^[0-9a-f]{64}$ ]] || fail "INVALID_EXPECTED_CONFIG_SOURCE_SHA256"
[[ "$EXPECTED_RUNTIME_FINGERPRINT_SHA256" =~ ^[0-9a-f]{64}$ ]] || fail "INVALID_EXPECTED_RUNTIME_FINGERPRINT"
[[ "$EXPECTED_SOURCE_BUNDLE_SHA256" =~ ^[0-9a-f]{64}$ ]] || fail "INVALID_EXPECTED_SOURCE_BUNDLE"
[[ "$PREFLIGHT_RUN_ID" =~ ^[0-9]+$ ]] || fail "INVALID_PREFLIGHT_RUN_ID"
[[ "$PREFLIGHT_RUN_ATTEMPT" =~ ^[0-9]+$ ]] || fail "INVALID_PREFLIGHT_RUN_ATTEMPT"
[[ "$ENV_SHA256" == "$EXPECTED_ENV_SHA256" ]] || fail "ENV_FILE_DRIFT"
[[ "$CONFIG_CACHE_SHA256" == "$EXPECTED_CONFIG_CACHE_SHA256" ]] || fail "CONFIG_CACHE_DRIFT"
[[ "$CONFIG_SOURCE_SHA256" == "$EXPECTED_CONFIG_SOURCE_SHA256" ]] || fail "CONFIG_SOURCE_DRIFT"
[[ "$RUNTIME_FINGERPRINT_SHA256" == "$EXPECTED_RUNTIME_FINGERPRINT_SHA256" ]] || fail "RUNTIME_FINGERPRINT_DRIFT"
[[ "$SOURCE_BUNDLE_SHA256" == "$EXPECTED_SOURCE_BUNDLE_SHA256" ]] || fail "SOURCE_BUNDLE_DRIFT"
[[ "$APPLY_READY" == "true" ]] || fail "PREFLIGHT_NOT_READY"

EXPECTED_PHRASE="I explicitly approve production fap-api content-release revalidation config convergence from preflight run ${PREFLIGHT_RUN_ID} attempt ${PREFLIGHT_RUN_ATTEMPT} with control-plane SHA ${EXPECTED_CONTROL_PLANE_SHA} active SHA ${EXPECTED_ACTIVE_SHA} environment SHA256 ${EXPECTED_ENV_SHA256} config-cache SHA256 ${EXPECTED_CONFIG_CACHE_SHA256} config-source SHA256 ${EXPECTED_CONFIG_SOURCE_SHA256} runtime fingerprint ${EXPECTED_RUNTIME_FINGERPRINT_SHA256} source bundle SHA256 ${EXPECTED_SOURCE_BUNDLE_SHA256}; write only CONTENT_RELEASE_REVALIDATE_SECRET and ENNEAGRAM_AUTHORITY_V2_REVALIDATION_URL, normalize only shared backend .env mode to 0640 while retaining owner/group, rebuild only Laravel config cache, no deploy/symlink/migration/CMS/database-authority/public-cache-revalidation/queue/service-restart/publication/sitemap/llms/search/PR23/automatic rollback."
[[ "$AUTHORIZATION_PHRASE" == "$EXPECTED_PHRASE" ]] || fail "AUTHORIZATION_PHRASE_MISMATCH"

write_managed_env || fail "ENV_WRITE_FAILED"
WRITES_COMMITTED=true
ENV_SETTING_WRITE_COUNT=2
ENV_PERMISSION_NORMALIZED=true
sudo -n -u www-data -- test -r "$ENV_FILE" || fail "ENV_NOT_READABLE_BY_RUNTIME"
CONFIG_CACHE_REBUILD_ATTEMPTED=true
(
  cd "$BACKEND_DIR"
  timeout 120 sudo -n -u www-data -- "$PHP_BIN" artisan config:cache --no-interaction --no-ansi \
    >/dev/null 2>&1
) || fail "CONFIG_CACHE_REBUILD_FAILED"
CONFIG_CACHE_REBUILD_COMMITTED=true

[[ ! -e "$DEPLOY_PATH/.dep/deploy.lock" ]] || fail "POST_APPLY_DEPLOY_LOCK_PRESENT"
[[ "$(tr -d '[:space:]' < "$CURRENT_RELEASE/REVISION")" == "$EXPECTED_ACTIVE_SHA" ]] \
  || fail "POST_APPLY_ACTIVE_REVISION_DRIFT"

POST_ENV_SHA256="$(file_sha256 "$ENV_FILE")"
POST_ENV_METADATA="$(file_metadata "$ENV_FILE")" || fail "POST_ENV_METADATA_READ_FAILED"
POST_ENV_MODE="$(jq -r '.mode' <<<"$POST_ENV_METADATA")"
POST_ENV_UID="$(jq -r '.uid' <<<"$POST_ENV_METADATA")"
POST_ENV_GID="$(jq -r '.gid' <<<"$POST_ENV_METADATA")"
POST_ENV_RUNTIME_READABLE=false
if sudo -n -u www-data -- test -r "$ENV_FILE"; then
  POST_ENV_RUNTIME_READABLE=true
fi
[[ "$POST_ENV_MODE" == "$TARGET_ENV_MODE" && "$POST_ENV_RUNTIME_READABLE" == "true" ]] \
  || fail "POST_ENV_PERMISSION_MISMATCH"
POST_CONFIG_CACHE_SHA256="$(file_sha256 "$CONFIG_CACHE_FILE")"
POST_ENV_SECRET="$(env_value CONTENT_RELEASE_REVALIDATE_SECRET)"
POST_ENV_URL="$(env_value ENNEAGRAM_AUTHORITY_V2_REVALIDATION_URL)"
POST_ENV_BUNDLE_SHA256="$(bundle_sha256 "$POST_ENV_SECRET" "$POST_ENV_URL")"
POST_CACHED_STATE="$(cached_bundle_state)" || fail "POST_CONFIG_CACHE_INSPECTION_FAILED"
[[ "$POST_ENV_BUNDLE_SHA256" == "$SOURCE_BUNDLE_SHA256" ]] || fail "POST_ENV_BUNDLE_MISMATCH"
[[ "$(jq -r '.bundle_sha256' <<<"$POST_CACHED_STATE")" == "$SOURCE_BUNDLE_SHA256" ]] \
  || fail "POST_CONFIG_CACHE_BUNDLE_MISMATCH"

POST_STATE="$(runtime_state \
  "$ACTIVE_BACKEND_SHA" \
  "$POST_ENV_SHA256" \
  "$POST_CONFIG_CACHE_SHA256" \
  "$CONFIG_SOURCE_SHA256" \
  "$SOURCE_BUNDLE_SHA256" \
  "$POST_ENV_BUNDLE_SHA256" \
  "$POST_CACHED_STATE" \
  "false" \
  "true" \
  "$POST_ENV_MODE" \
  "$POST_ENV_UID" \
  "$POST_ENV_GID" \
  "$POST_ENV_RUNTIME_READABLE")" || fail "POST_RUNTIME_STATE_BUILD_FAILED"
POST_FINGERPRINT_SHA256="$(printf '%s' "$POST_STATE" | runtime_fingerprint_sha256)"

jq -cS \
  --arg runtime_fingerprint_sha256 "$POST_FINGERPRINT_SHA256" \
  '. + {
    ok: true,
    status: "PASS_CONFIG_CACHE_CONVERGED",
    runtime_fingerprint_sha256: $runtime_fingerprint_sha256,
    production_write_execution: true,
    writes_committed: true,
    env_setting_write_count: 2,
    env_permission_normalized: true,
    config_cache_rebuild_attempted: true,
    config_cache_rebuild_committed: true
  }' <<<"$POST_STATE"
