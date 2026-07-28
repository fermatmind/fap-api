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
EXPECTED_MALFORMED_LINE_COUNT="${EXPECTED_MALFORMED_LINE_COUNT:-}"
EXPECTED_MALFORMED_LINE_SET_SHA256="${EXPECTED_MALFORMED_LINE_SET_SHA256:-}"
EXPECTED_CANDIDATE_ENV_SHA256="${EXPECTED_CANDIDATE_ENV_SHA256:-}"
PREFLIGHT_RUN_ID="${PREFLIGHT_RUN_ID:-}"
PREFLIGHT_RUN_ATTEMPT="${PREFLIGHT_RUN_ATTEMPT:-}"
AUTHORIZATION_PHRASE="${AUTHORIZATION_PHRASE:-}"
CONTENT_RELEASE_REVALIDATE_SECRET="${CONTENT_RELEASE_REVALIDATE_SECRET:-}"
REVALIDATION_URL="https://fermatmind.com/api/content-release/revalidate"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
ZERO_SHA256="0000000000000000000000000000000000000000000000000000000000000000"
WRITES_COMMITTED=false
MALFORMED_COMMENT_WRITE_COUNT=0
CONFIG_CACHE_REBUILD_ATTEMPTED=false
CONFIG_CACHE_REBUILD_COMMITTED=false
SECRET_PATTERN='^[A-Za-z0-9_-]+$'

fail() {
  jq -cnS \
    --arg error_code "$1" \
    --argjson writes_committed "$WRITES_COMMITTED" \
    --argjson malformed_comment_write_count "$MALFORMED_COMMENT_WRITE_COUNT" \
    --argjson config_cache_rebuild_attempted "$CONFIG_CACHE_REBUILD_ATTEMPTED" \
    --argjson config_cache_rebuild_committed "$CONFIG_CACHE_REBUILD_COMMITTED" \
    '{
      ok: false,
      status: (if $writes_committed then "FAIL_CLOSED_PARTIAL_DOTENV_RECOVERY" else "FAIL_CLOSED_NO_WRITES" end),
      error_code: $error_code,
      writes_committed: $writes_committed,
      malformed_comment_write_count: $malformed_comment_write_count,
      config_cache_rebuild_attempted: $config_cache_rebuild_attempted,
      config_cache_rebuild_committed: $config_cache_rebuild_committed,
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

dotenv_analysis() {
  ENV_FILE="$ENV_FILE" AUTOLOAD_FILE="$AUTOLOAD_FILE" php <<'PHP'
<?php
$autoload = getenv('AUTOLOAD_FILE') ?: '';
$path = getenv('ENV_FILE') ?: '';
if (! is_file($autoload) || ! is_file($path)) {
    exit(2);
}
require $autoload;
$lines = file($path, FILE_IGNORE_NEW_LINES);
$lines = is_array($lines) ? $lines : [];
$parser = new Dotenv\Parser\Parser();
$current = implode("\n", $lines)."\n";
$currentValid = true;
$currentErrorSha = null;
try {
    $parser->parse($current);
} catch (Throwable $error) {
    $currentValid = false;
    $currentErrorSha = hash('sha256', $error->getMessage());
}
$targets = [];
$candidate = [];
foreach ($lines as $index => $line) {
    $trimmed = ltrim($line);
    $malformed = $trimmed !== ''
        && ! str_starts_with($trimmed, '#')
        && preg_match('/^(?:export\s+)?[A-Za-z_][A-Za-z0-9_]*\s*=/', $trimmed) !== 1;
    if ($malformed) {
        $targets[] = [
            'line' => $index + 1,
            'line_sha256' => hash('sha256', $line),
        ];
        $candidate[] = '# '.$line;
    } else {
        $candidate[] = $line;
    }
}
$candidateText = implode("\n", $candidate)."\n";
$candidateValid = true;
$candidateErrorSha = null;
try {
    $parser->parse($candidateText);
} catch (Throwable $error) {
    $candidateValid = false;
    $candidateErrorSha = hash('sha256', $error->getMessage());
}
echo json_encode([
    'dotenv_valid' => $currentValid,
    'dotenv_error_sha256' => $currentErrorSha,
    'candidate_valid' => $candidateValid,
    'candidate_error_sha256' => $candidateErrorSha,
    'malformed_line_count' => count($targets),
    'malformed_line_set_sha256' => hash('sha256', json_encode($targets, JSON_THROW_ON_ERROR)),
    'candidate_env_sha256' => hash('sha256', $candidateText),
], JSON_THROW_ON_ERROR);
PHP
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
  jq -cnS \
    --arg schema_version "content_release_revalidation_dotenv_recovery.v1" \
    --arg mode "$MODE" \
    --arg control_plane_sha "$EXPECTED_CONTROL_PLANE_SHA" \
    --arg active_backend_sha "$ACTIVE_BACKEND_SHA" \
    --arg expected_active_backend_sha "$EXPECTED_ACTIVE_SHA" \
    --arg env_sha256 "$ENV_SHA256" \
    --arg config_cache_sha256 "$CONFIG_CACHE_SHA256" \
    --arg config_source_sha256 "$CONFIG_SOURCE_SHA256" \
    --arg source_bundle_sha256 "$SOURCE_BUNDLE_SHA256" \
    --arg env_bundle_sha256 "$ENV_BUNDLE_SHA256" \
    --arg env_mode "$ENV_MODE" \
    --arg env_uid "$ENV_UID" \
    --arg env_gid "$ENV_GID" \
    --argjson env_runtime_readable "$ENV_RUNTIME_READABLE" \
    --argjson source_ready "$SOURCE_READY" \
    --argjson dotenv "$DOTENV_STATE" \
    --argjson cached "$CACHED_STATE" \
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
      env_matches_source: ($env_bundle_sha256 == $source_bundle_sha256),
      env_mode: $env_mode,
      env_uid: $env_uid,
      env_gid: $env_gid,
      env_runtime_readable: $env_runtime_readable,
      source_config_ready: $source_ready,
      dotenv_valid: $dotenv.dotenv_valid,
      dotenv_error_sha256: $dotenv.dotenv_error_sha256,
      candidate_valid: $dotenv.candidate_valid,
      candidate_error_sha256: $dotenv.candidate_error_sha256,
      malformed_line_count: $dotenv.malformed_line_count,
      malformed_line_set_sha256: $dotenv.malformed_line_set_sha256,
      candidate_env_sha256: $dotenv.candidate_env_sha256,
      cached_bundle_sha256: $cached.bundle_sha256,
      cached_config_matches_source: ($cached.bundle_sha256 == $source_bundle_sha256),
      deploy_lock_present: false,
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

write_candidate_env() {
  local temp_file="${ENV_FILE}.tmp.$$"
  umask 077
  ENV_FILE="$ENV_FILE" \
    TEMP_FILE="$temp_file" \
    AUTOLOAD_FILE="$AUTOLOAD_FILE" \
    EXPECTED_MALFORMED_LINE_COUNT="$EXPECTED_MALFORMED_LINE_COUNT" \
    EXPECTED_MALFORMED_LINE_SET_SHA256="$EXPECTED_MALFORMED_LINE_SET_SHA256" \
    EXPECTED_CANDIDATE_ENV_SHA256="$EXPECTED_CANDIDATE_ENV_SHA256" \
    php <<'PHP'
<?php
$autoload = getenv('AUTOLOAD_FILE') ?: '';
$target = getenv('ENV_FILE') ?: '';
$temp = getenv('TEMP_FILE') ?: '';
require $autoload;
$targetOwner = fileowner($target);
$targetGroup = filegroup($target);
if ($targetOwner === false || $targetGroup === false) {
    exit(2);
}
$lines = file($target, FILE_IGNORE_NEW_LINES);
$lines = is_array($lines) ? $lines : [];
$targets = [];
$candidate = [];
foreach ($lines as $index => $line) {
    $trimmed = ltrim($line);
    $malformed = $trimmed !== ''
        && ! str_starts_with($trimmed, '#')
        && preg_match('/^(?:export\s+)?[A-Za-z_][A-Za-z0-9_]*\s*=/', $trimmed) !== 1;
    if ($malformed) {
        $targets[] = [
            'line' => $index + 1,
            'line_sha256' => hash('sha256', $line),
        ];
        $candidate[] = '# '.$line;
    } else {
        $candidate[] = $line;
    }
}
$candidateText = implode("\n", $candidate)."\n";
$setSha = hash('sha256', json_encode($targets, JSON_THROW_ON_ERROR));
$candidateSha = hash('sha256', $candidateText);
if (
    count($targets) !== (int) getenv('EXPECTED_MALFORMED_LINE_COUNT')
    || ! hash_equals((string) getenv('EXPECTED_MALFORMED_LINE_SET_SHA256'), $setSha)
    || ! hash_equals((string) getenv('EXPECTED_CANDIDATE_ENV_SHA256'), $candidateSha)
) {
    exit(3);
}
try {
    (new Dotenv\Parser\Parser())->parse($candidateText);
} catch (Throwable) {
    exit(4);
}
if (file_put_contents($temp, $candidateText, LOCK_EX) === false) {
    exit(5);
}
if (
    ! chown($temp, $targetOwner)
    || ! chgrp($temp, $targetGroup)
    || ! chmod($temp, 0640)
    || ! rename($temp, $target)
) {
    @unlink($temp);
    exit(6);
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
AUTOLOAD_FILE="$BACKEND_DIR/vendor/autoload.php"
[[ -d "$BACKEND_DIR" && -f "$ENV_FILE" && -f "$CONFIG_SOURCE_FILE" && -f "$AUTOLOAD_FILE" ]] \
  || fail "MANAGED_CONFIG_PATH_MISSING"
ENV_REALPATH="$(canonical_path "$ENV_FILE")" || fail "ENV_RESOLVE_FAILED"
EXPECTED_ENV_REALPATH="$(canonical_path "$DEPLOY_PATH/shared/backend/.env")" \
  || fail "ENV_BOUNDARY_RESOLVE_FAILED"
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
DOTENV_STATE="$(dotenv_analysis)" || fail "DOTENV_ANALYSIS_FAILED"
CACHED_STATE="$(cached_bundle_state)" || fail "CONFIG_CACHE_INSPECTION_FAILED"
STATE="$(runtime_state)" || fail "RUNTIME_STATE_BUILD_FAILED"
RUNTIME_FINGERPRINT_SHA256="$(printf '%s' "$STATE" | runtime_fingerprint_sha256)"
APPLY_READY="$(jq -r '
  .active_revision_matches
  and .source_config_ready
  and .env_matches_source
  and .env_mode == "0640"
  and .env_runtime_readable
  and .dotenv_valid == false
  and .candidate_valid
  and .malformed_line_count > 0
  and .deploy_lock_present == false
' <<<"$STATE")"

if [[ "$MODE" == "preflight" ]]; then
  jq -cS \
    --arg runtime_fingerprint_sha256 "$RUNTIME_FINGERPRINT_SHA256" \
    --argjson apply_ready "$APPLY_READY" \
    '. + {
      ok: true,
      status: (if $apply_ready then "PASS_AUTHORIZATION_REQUIRED" else "PASS_PREFLIGHT_NOT_RECOVERABLE" end),
      apply_ready: $apply_ready,
      runtime_fingerprint_sha256: $runtime_fingerprint_sha256
    }' <<<"$STATE"
  exit 0
fi

[[ "$EXPECTED_ENV_SHA256" =~ ^[0-9a-f]{64}$ ]] || fail "INVALID_EXPECTED_ENV_SHA256"
[[ "$EXPECTED_CONFIG_CACHE_SHA256" =~ ^[0-9a-f]{64}$ ]] || fail "INVALID_EXPECTED_CONFIG_CACHE_SHA256"
[[ "$EXPECTED_CONFIG_SOURCE_SHA256" =~ ^[0-9a-f]{64}$ ]] || fail "INVALID_EXPECTED_CONFIG_SOURCE_SHA256"
[[ "$EXPECTED_RUNTIME_FINGERPRINT_SHA256" =~ ^[0-9a-f]{64}$ ]] \
  || fail "INVALID_EXPECTED_RUNTIME_FINGERPRINT"
[[ "$EXPECTED_SOURCE_BUNDLE_SHA256" =~ ^[0-9a-f]{64}$ ]] || fail "INVALID_EXPECTED_SOURCE_BUNDLE"
[[ "$EXPECTED_MALFORMED_LINE_COUNT" =~ ^[1-9][0-9]*$ ]] || fail "INVALID_EXPECTED_MALFORMED_COUNT"
[[ "$EXPECTED_MALFORMED_LINE_SET_SHA256" =~ ^[0-9a-f]{64}$ ]] \
  || fail "INVALID_EXPECTED_MALFORMED_SET"
[[ "$EXPECTED_CANDIDATE_ENV_SHA256" =~ ^[0-9a-f]{64}$ ]] || fail "INVALID_EXPECTED_CANDIDATE_ENV"
[[ "$PREFLIGHT_RUN_ID" =~ ^[0-9]+$ ]] || fail "INVALID_PREFLIGHT_RUN_ID"
[[ "$PREFLIGHT_RUN_ATTEMPT" =~ ^[0-9]+$ ]] || fail "INVALID_PREFLIGHT_RUN_ATTEMPT"
[[ "$ENV_SHA256" == "$EXPECTED_ENV_SHA256" ]] || fail "ENV_FILE_DRIFT"
[[ "$CONFIG_CACHE_SHA256" == "$EXPECTED_CONFIG_CACHE_SHA256" ]] || fail "CONFIG_CACHE_DRIFT"
[[ "$CONFIG_SOURCE_SHA256" == "$EXPECTED_CONFIG_SOURCE_SHA256" ]] || fail "CONFIG_SOURCE_DRIFT"
[[ "$RUNTIME_FINGERPRINT_SHA256" == "$EXPECTED_RUNTIME_FINGERPRINT_SHA256" ]] \
  || fail "RUNTIME_FINGERPRINT_DRIFT"
[[ "$SOURCE_BUNDLE_SHA256" == "$EXPECTED_SOURCE_BUNDLE_SHA256" ]] || fail "SOURCE_BUNDLE_DRIFT"
[[ "$(jq -r '.malformed_line_count' <<<"$DOTENV_STATE")" == "$EXPECTED_MALFORMED_LINE_COUNT" ]] \
  || fail "MALFORMED_LINE_COUNT_DRIFT"
[[ "$(jq -r '.malformed_line_set_sha256' <<<"$DOTENV_STATE")" == "$EXPECTED_MALFORMED_LINE_SET_SHA256" ]] \
  || fail "MALFORMED_LINE_SET_DRIFT"
[[ "$(jq -r '.candidate_env_sha256' <<<"$DOTENV_STATE")" == "$EXPECTED_CANDIDATE_ENV_SHA256" ]] \
  || fail "CANDIDATE_ENV_DRIFT"
[[ "$APPLY_READY" == "true" ]] || fail "PREFLIGHT_NOT_READY"

EXPECTED_PHRASE="I explicitly approve production fap-api dotenv comment recovery from preflight run ${PREFLIGHT_RUN_ID} attempt ${PREFLIGHT_RUN_ATTEMPT} with control-plane SHA ${EXPECTED_CONTROL_PLANE_SHA} active SHA ${EXPECTED_ACTIVE_SHA} environment SHA256 ${EXPECTED_ENV_SHA256} config-cache SHA256 ${EXPECTED_CONFIG_CACHE_SHA256} config-source SHA256 ${EXPECTED_CONFIG_SOURCE_SHA256} runtime fingerprint ${EXPECTED_RUNTIME_FINGERPRINT_SHA256} source bundle SHA256 ${EXPECTED_SOURCE_BUNDLE_SHA256} malformed line count ${EXPECTED_MALFORMED_LINE_COUNT} malformed line set SHA256 ${EXPECTED_MALFORMED_LINE_SET_SHA256} candidate environment SHA256 ${EXPECTED_CANDIDATE_ENV_SHA256}; prefix only the exact malformed non-assignment lines with comment markers, preserve every environment assignment and owner/group/mode 0640, rebuild only Laravel config cache, no deploy/symlink/migration/CMS/database-authority/public-cache-revalidation/queue/service-restart/publication/sitemap/llms/search/PR23/automatic rollback."
[[ "$AUTHORIZATION_PHRASE" == "$EXPECTED_PHRASE" ]] || fail "AUTHORIZATION_PHRASE_MISMATCH"

write_candidate_env || fail "DOTENV_WRITE_FAILED"
WRITES_COMMITTED=true
MALFORMED_COMMENT_WRITE_COUNT="$EXPECTED_MALFORMED_LINE_COUNT"
sudo -n -u www-data -- test -r "$ENV_FILE" || fail "ENV_NOT_READABLE_BY_RUNTIME"
POST_DOTENV_STATE="$(dotenv_analysis)" || fail "POST_DOTENV_ANALYSIS_FAILED"
[[ "$(jq -r '.dotenv_valid' <<<"$POST_DOTENV_STATE")" == "true" ]] || fail "POST_DOTENV_INVALID"
[[ "$(jq -r '.malformed_line_count' <<<"$POST_DOTENV_STATE")" == "0" ]] \
  || fail "POST_MALFORMED_LINES_REMAIN"
[[ "$(file_sha256 "$ENV_FILE")" == "$EXPECTED_CANDIDATE_ENV_SHA256" ]] || fail "POST_ENV_SHA_MISMATCH"

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
POST_CACHED_STATE="$(cached_bundle_state)" || fail "POST_CONFIG_CACHE_INSPECTION_FAILED"
[[ "$(jq -r '.bundle_sha256' <<<"$POST_CACHED_STATE")" == "$SOURCE_BUNDLE_SHA256" ]] \
  || fail "POST_CONFIG_CACHE_BUNDLE_MISMATCH"

jq -cnS \
  --arg post_env_sha256 "$(file_sha256 "$ENV_FILE")" \
  --arg post_config_cache_sha256 "$(file_sha256 "$CONFIG_CACHE_FILE")" \
  --arg source_bundle_sha256 "$SOURCE_BUNDLE_SHA256" \
  --arg cached_bundle_sha256 "$(jq -r '.bundle_sha256' <<<"$POST_CACHED_STATE")" \
  --argjson malformed_comment_write_count "$MALFORMED_COMMENT_WRITE_COUNT" \
  '{
    schema_version: "content_release_revalidation_dotenv_recovery.v1",
    mode: "apply",
    ok: true,
    status: "PASS_DOTENV_RECOVERY_CONVERGED",
    post_env_sha256: $post_env_sha256,
    post_config_cache_sha256: $post_config_cache_sha256,
    source_bundle_sha256: $source_bundle_sha256,
    cached_bundle_sha256: $cached_bundle_sha256,
    malformed_comment_write_count: $malformed_comment_write_count,
    dotenv_valid: true,
    env_mode: "0640",
    env_runtime_readable: true,
    production_write_execution: true,
    writes_committed: true,
    config_cache_rebuild_attempted: true,
    config_cache_rebuild_committed: true,
    application_deploy: false,
    symlink_activation: false,
    migration: false,
    cms_or_database_authority_write: false,
    public_cache_revalidation: false,
    queue_or_service_restart: false,
    automatic_rollback: false,
    secret_values_output: false
  }'
