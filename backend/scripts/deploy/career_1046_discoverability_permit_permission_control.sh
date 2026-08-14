#!/usr/bin/env bash
set -euo pipefail

export LC_ALL=C

readonly schema_version="career.1046.discoverability_permit_permission_control.v1"
mode="${CAREER_PERMIT_PERMISSION_MODE:-inspect}"
authority_root="${CAREER_PERMIT_PERMISSION_ROOT:-}"
generation_id="${CAREER_PERMIT_PERMISSION_GENERATION_ID:-}"
expected_pointer_sha256="${CAREER_PERMIT_PERMISSION_POINTER_SHA256:-}"
expected_owner="${CAREER_PERMIT_PERMISSION_OWNER:-}"
expected_group="${CAREER_PERMIT_PERMISSION_GROUP:-}"
runtime_user="${CAREER_PERMIT_PERMISSION_RUNTIME_USER:-}"
deploy_path="${DEPLOY_PATH:-}"
expected_active_revision="${EXPECTED_ACTIVE_REVISION:-}"

fail() {
  jq -nc --arg schema "$schema_version" --arg reason "$1" \
    '{schema_version:$schema,status:"HOLD_DISCOVERABILITY_PERMIT_PERMISSION_CONTROL",reason:$reason,metadata_write_count:0,content_write_count:0,database_write_count:0,cms_write_count:0,cache_write_count:0,pointer_write_count:0,sitemap_write_count:0,llms_write_count:0,search_submission_count:0,deployment_count:0,migration_count:0,restart_count:0,automatic_retry_allowed:false}'
  exit "${2:-1}"
}

sha256_text() { printf '%s' "$1" | sha256sum | awk '{print $1}'; }
safe_account() { [[ "$1" =~ ^[A-Za-z_][A-Za-z0-9_.-]{0,31}$ ]]; }

validate_runtime_fence() {
  local active revision processes
  [[ "$deploy_path" =~ ^/[A-Za-z0-9._/-]+$ && "$deploy_path" != *"/../"* ]] || fail DEPLOY_PATH_INVALID
  [[ "$expected_active_revision" =~ ^[0-9a-f]{40}$ ]] || fail ACTIVE_REVISION_INVALID
  active="$(readlink -f "$deploy_path/current")" || fail ACTIVE_RELEASE_UNRESOLVED
  [ -d "$active" ] && [ ! -L "$active" ] || fail ACTIVE_RELEASE_INVALID
  [ -f "$active/REVISION" ] && [ ! -L "$active/REVISION" ] || fail ACTIVE_REVISION_UNREADABLE
  revision="$(tr -d '\r\n' < "$active/REVISION")"
  [ "$revision" = "$expected_active_revision" ] || fail ACTIVE_REVISION_DRIFT
  [ ! -e "$deploy_path/.dep/deploy.lock" ] || fail DEPLOY_LOCK_PRESENT
  processes="$(ps -eo comm=,args= | awk '$1=="php" && ($0 ~ /dep(\.phar)? .* production/ || $0 ~ /artisan migrate/) {n++} END {print n+0}')"
  [ "$processes" -eq 0 ] || fail DEPLOY_OR_MIGRATION_PROCESS_PRESENT
}

can_runtime_read() {
  sudo -n -u "$runtime_user" -- php -r '
    $value = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $payload = $value["payload"] ?? null;
    exit(is_array($payload)
      && ($value["schema_version"] ?? null) === "career.1046.discoverability_release_permit.v1"
      && ($payload["generation_id"] ?? null) === $argv[2]
      && ($payload["active_pointer_sha256"] ?? null) === $argv[3]
      && ($payload["immutable_pointer_sha256"] ?? null) === $argv[3]
      && ($payload["slug_count"] ?? null) === 1046
      && ($payload["locale_row_count"] ?? null) === 2092
      && ($payload["sitemap_released"] ?? null) === true
      && ($payload["llms_released"] ?? null) === true
      && ($payload["search_submission_enabled"] ?? null) === false
      ? 0 : 1);
  ' "$permit_file" "$generation_id" "$expected_pointer_sha256" >/dev/null 2>&1
}

node_record() {
  local label="$1" relative="$2" target="$3" content_sha="$4"
  printf '%s|%s|%s|%s\n' "$label" "$(sha256_text "$relative")" "$(stat -c '%d|%i|%h|%u|%g|%a|%s' "$target")" "$content_sha"
}

needs_repair() {
  local target="$1" desired_mode="$2"
  [ "$(stat -c '%u' "$target")" != "$expected_uid" ] \
    || [ "$(stat -c '%g' "$target")" != "$expected_gid" ] \
    || [ "$(stat -c '%a' "$target")" != "$desired_mode" ]
}

compute_state() {
  local resolved_root resolved_parent resolved_generation resolved_file records identities index target label relative content_sha
  validate_runtime_fence
  permit_parent="$authority_root/discoverability-releases"
  permit_generation="$permit_parent/$generation_id"
  permit_file="$permit_generation/release.json"
  [ -d "$authority_root" ] && [ ! -L "$authority_root" ] || fail AUTHORITY_ROOT_INVALID
  [ -d "$permit_parent" ] && [ ! -L "$permit_parent" ] || fail PERMIT_PARENT_INVALID
  [ -d "$permit_generation" ] && [ ! -L "$permit_generation" ] || fail PERMIT_GENERATION_INVALID
  [ -f "$permit_file" ] && [ ! -L "$permit_file" ] || fail PERMIT_FILE_INVALID
  [ "$(stat -c '%h' "$permit_file")" = 1 ] || fail PERMIT_FILE_LINK_COUNT_INVALID
  resolved_root="$(readlink -f "$authority_root")" || fail AUTHORITY_ROOT_UNRESOLVED
  resolved_parent="$(readlink -f "$permit_parent")" || fail PERMIT_PARENT_UNRESOLVED
  resolved_generation="$(readlink -f "$permit_generation")" || fail PERMIT_GENERATION_UNRESOLVED
  resolved_file="$(readlink -f "$permit_file")" || fail PERMIT_FILE_UNRESOLVED
  [ "$resolved_root" = "$authority_root" ] || fail AUTHORITY_ROOT_IDENTITY_INVALID
  [ "$resolved_parent" = "$permit_parent" ] && [ "$resolved_generation" = "$permit_generation" ] && [ "$resolved_file" = "$permit_file" ] || fail PERMIT_PATH_ESCAPE

  permit_sha256="$(sha256sum "$permit_file" | awk '{print $1}')"
  jq -e --arg generation "$generation_id" --arg pointer "$expected_pointer_sha256" '
    .schema_version == "career.1046.discoverability_release_permit.v1"
    and (.payload_sha256 | test("^[0-9a-f]{64}$"))
    and .payload.generation_id == $generation
    and .payload.active_pointer_sha256 == $pointer
    and .payload.immutable_pointer_sha256 == $pointer
    and .payload.slug_count == 1046
    and .payload.locale_row_count == 2092
    and (.payload.released_locale_rows | type == "array" and length == 2092)
    and ((.payload.released_locale_rows | unique | length) == 2092)
    and (.payload.document_sha256 | type == "object" and length == 5 and all(.[]; test("^[0-9a-f]{64}$")))
    and .payload.sitemap_released == true
    and .payload.llms_released == true
    and .payload.search_submission_enabled == false
  ' "$permit_file" >/dev/null || fail PERMIT_CONTRACT_INVALID

  targets=("$permit_parent" "$permit_generation" "$permit_file")
  modes=(2750 2750 640)
  labels=(permit_parent permit_generation permit_file)
  relatives=(discoverability-releases "discoverability-releases/$generation_id" "discoverability-releases/$generation_id/release.json")
  records=""; identities=""
  for index in 0 1 2; do
    target="${targets[$index]}"; label="${labels[$index]}"; relative="${relatives[$index]}"
    content_sha="-"; [ "$index" -eq 2 ] && content_sha="$permit_sha256"
    record="$(node_record "$label" "$relative" "$target" "$content_sha")"
    records+="${record}"$'\n'
    identities+="$(printf '%s\n' "$record" | cut -d '|' -f 1-5)"$'\n'
  done
  target_set_sha256="$(sha256_text "$identities")"
  snapshot_sha256="$(sha256_text "$records")"
  repair_target_count=0
  for index in 0 1 2; do
    needs_repair "${targets[$index]}" "${modes[$index]}" && repair_target_count=$((repair_target_count + 1))
  done
  runtime_readable=false
  can_runtime_read && runtime_readable=true
  if [ "$repair_target_count" -eq 0 ] && [ "$runtime_readable" != true ]; then
    fail RUNTIME_ACCESS_UNREPAIRABLE_BY_BOUND_METADATA
  fi
}

emit_state() {
  jq -nc --arg schema "$schema_version" --arg status "$1" --arg generation "$generation_id" \
    --arg pointer "$expected_pointer_sha256" --arg permit "$permit_sha256" --arg target "$target_set_sha256" --arg snapshot "$snapshot_sha256" \
    --argjson repair "$repair_target_count" --argjson readable "$runtime_readable" --argjson writes "$2" \
    '{schema_version:$schema,status:$status,generation_id:$generation,active_pointer_sha256:$pointer,permit_sha256:$permit,target_set_sha256:$target,snapshot_sha256:$snapshot,slug_count:1046,locale_row_count:2092,repair_target_count:$repair,runtime_readable:$readable,metadata_write_count:$writes,bytes_unchanged:true,active_revision_unchanged:true,deploy_lock_absent:true,deploy_process_absent:true,migration_process_absent:true,content_write_count:0,database_write_count:0,cms_write_count:0,cache_write_count:0,pointer_write_count:0,sitemap_write_count:0,llms_write_count:0,search_submission_count:0,deployment_count:0,migration_count:0,restart_count:0,automatic_retry_allowed:false}'
}

case "$mode" in preflight|inspect|apply) ;; *) fail MODE_INVALID 2 ;; esac
[ -n "$authority_root" ] && [[ "$authority_root" == /*/shared/backend/storage/app/private/career_generation_authority ]] || fail ROOT_INVALID 2
[[ "$authority_root" != *$'\n'* && "$authority_root" != *"/../"* ]] || fail ROOT_INVALID 2
[[ "$generation_id" =~ ^career-1046-[0-9a-f]{32}$ ]] || fail GENERATION_INVALID 2
[[ "$expected_pointer_sha256" =~ ^[0-9a-f]{64}$ ]] || fail POINTER_INVALID 2
safe_account "$expected_owner" || fail OWNER_INVALID 2
safe_account "$expected_group" || fail GROUP_INVALID 2
safe_account "$runtime_user" || fail RUNTIME_USER_INVALID 2
expected_uid="$(id -u "$expected_owner")" || fail OWNER_UNKNOWN 2
expected_gid="$(getent group "$expected_group" | cut -d: -f3)" || fail GROUP_UNKNOWN 2
id -u "$runtime_user" >/dev/null 2>&1 || fail RUNTIME_USER_UNKNOWN 2
declare -a targets=() modes=() labels=() relatives=()
compute_state

if [ "$mode" != apply ]; then
  if [ "$repair_target_count" -eq 0 ] && [ "$runtime_readable" = true ]; then
    emit_state PASS_DISCOVERABILITY_PERMIT_ALREADY_RUNTIME_READABLE 0
  else
    emit_state PASS_DISCOVERABILITY_PERMIT_PERMISSION_REPAIR_REQUIRED 0
  fi
  exit 0
fi

[ "${CAREER_PERMIT_PERMISSION_APPLY_CONFIRMATION:-}" = true ] || fail EXPLICIT_APPLY_CONFIRMATION_REQUIRED 2
[ "${CAREER_PERMIT_PERMISSION_EXPECTED_TARGET_SET_SHA256:-}" = "$target_set_sha256" ] || fail TARGET_SET_DRIFT
[ "${CAREER_PERMIT_PERMISSION_EXPECTED_SNAPSHOT_SHA256:-}" = "$snapshot_sha256" ] || fail SNAPSHOT_DRIFT
[ "${CAREER_PERMIT_PERMISSION_EXPECTED_PERMIT_SHA256:-}" = "$permit_sha256" ] || fail PERMIT_BYTES_DRIFT
expected_repair_count="${CAREER_PERMIT_PERMISSION_EXPECTED_REPAIR_TARGET_COUNT:-}"
[[ "$expected_repair_count" =~ ^[1-3]$ ]] && [ "$expected_repair_count" -eq "$repair_target_count" ] || fail REPAIR_COUNT_DRIFT
before_permit="$permit_sha256"; before_target="$target_set_sha256"; metadata_write_count=0
for index in 0 1 2; do
  if needs_repair "${targets[$index]}" "${modes[$index]}"; then
    chown "$expected_owner:$expected_group" "${targets[$index]}"
    chmod "${modes[$index]}" "${targets[$index]}"
    metadata_write_count=$((metadata_write_count + 1))
  fi
done
[ "$metadata_write_count" -eq "$expected_repair_count" ] || fail METADATA_WRITE_COUNT_INVALID
compute_state
[ "$permit_sha256" = "$before_permit" ] || fail PERMIT_BYTES_CHANGED_AFTER_APPLY
[ "$target_set_sha256" = "$before_target" ] || fail TARGET_IDENTITY_CHANGED_AFTER_APPLY
[ "$repair_target_count" -eq 0 ] && [ "$runtime_readable" = true ] || fail PERMISSION_REPAIR_READBACK_FAILED
emit_state PASS_DISCOVERABILITY_PERMIT_PERMISSION_REPAIR_VERIFIED "$metadata_write_count"
