#!/usr/bin/env bash
set -euo pipefail

export LC_ALL=C

schema_version="career.runtime_authority_permission_control.v1"
mode="${CAREER_AUTHORITY_PERMISSION_MODE:-inspect}"
authority_root="${CAREER_AUTHORITY_PERMISSION_ROOT:-}"
expected_owner="${CAREER_AUTHORITY_PERMISSION_OWNER:-}"
expected_group="${CAREER_AUTHORITY_PERMISSION_GROUP:-}"
runtime_user="${CAREER_AUTHORITY_PERMISSION_RUNTIME_USER:-}"

fail() {
  jq -nc \
    --arg schema_version "$schema_version" \
    --arg status "HOLD_PERMISSION_CONTROL" \
    --arg reason "$1" \
    '{schema_version:$schema_version,status:$status,reason:$reason}'
  exit "${2:-1}"
}

safe_account() {
  [[ "$1" =~ ^[A-Za-z_][A-Za-z0-9_.-]{0,31}$ ]]
}

sha256_text() {
  printf '%s' "$1" | sha256sum | awk '{print $1}'
}

can_access_directory() {
  local user="$1"
  local target="$2"

  if [ "$(id -u)" -eq "$(id -u "$user")" ]; then
    [ -r "$target" ] && [ -x "$target" ]
    return
  fi

  command -v sudo >/dev/null 2>&1 || return 1
  sudo -n -u "$user" -- sh -c 'test -r "$1" && test -x "$1"' sh "$target" \
    >/dev/null 2>&1
}

can_access_file() {
  local user="$1"
  local target="$2"

  if [ "$(id -u)" -eq "$(id -u "$user")" ]; then
    [ -r "$target" ]
    return
  fi

  command -v sudo >/dev/null 2>&1 || return 1
  sudo -n -u "$user" -- test -r "$target" >/dev/null 2>&1
}

select_latest_artifact() {
  local family="$1"
  local filename="$2"
  local family_root="$authority_root/$family"
  local resolved_family_root
  local candidate
  local candidate_name
  local candidate_mtime
  local latest_dir=""
  local latest_name=""
  local latest_mtime=-1
  local artifact

  [ -d "$family_root" ] && [ ! -L "$family_root" ] || fail "AUTHORITY_FAMILY_ROOT_INVALID"
  resolved_family_root="$(readlink -f "$family_root")"
  [ "$resolved_family_root" = "$family_root" ] || fail "AUTHORITY_FAMILY_ROOT_INVALID"

  shopt -s nullglob dotglob
  for candidate in "$family_root"/*; do
    [ -d "$candidate" ] && [ ! -L "$candidate" ] || fail "AUTHORITY_FAMILY_ENTRY_INVALID"
    candidate_name="$(basename "$candidate")"
    [[ "$candidate_name" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ ]] \
      || fail "AUTHORITY_DIRECTORY_IDENTITY_INVALID"
    [ "$(readlink -f "$candidate")" = "$candidate" ] || fail "AUTHORITY_DIRECTORY_IDENTITY_INVALID"
    artifact="$candidate/$filename"
    if [ -f "$artifact" ] && [ ! -L "$artifact" ] && [ -r "$artifact" ]; then
      candidate_mtime="$(stat -c '%Y' "$artifact")" \
        || fail "AUTHORITY_ARTIFACT_METADATA_UNOBSERVABLE"
    else
      candidate_mtime="$(stat -c '%Y' "$candidate")" \
        || fail "AUTHORITY_DIRECTORY_METADATA_UNOBSERVABLE"
    fi
    [[ "$candidate_mtime" =~ ^[0-9]+$ ]] || fail "AUTHORITY_DIRECTORY_METADATA_UNOBSERVABLE"

    if [ "$candidate_mtime" -gt "$latest_mtime" ] \
      || { [ "$candidate_mtime" -eq "$latest_mtime" ] && [[ "$candidate_name" > "$latest_name" ]]; }; then
      latest_dir="$candidate"
      latest_name="$candidate_name"
      latest_mtime="$candidate_mtime"
    fi
  done
  shopt -u nullglob dotglob

  [ -n "$latest_dir" ] || fail "AUTHORITY_ARTIFACT_MISSING"
  artifact="$latest_dir/$filename"
  [ -f "$artifact" ] && [ ! -L "$artifact" ] || fail "AUTHORITY_ARTIFACT_FILE_INVALID"
  [ "$(readlink -f "$artifact")" = "$artifact" ] || fail "AUTHORITY_ARTIFACT_FILE_INVALID"
  [ "$(stat -c '%h' "$artifact")" = "1" ] || fail "AUTHORITY_ARTIFACT_LINK_COUNT_INVALID"

  selected_families+=("$family")
  selected_directories+=("$latest_dir")
  selected_directory_names+=("$latest_name")
  selected_files+=("$artifact")
  selected_filenames+=("$filename")
}

node_record() {
  local label="$1"
  local directory_name="$2"
  local target="$3"
  local content_sha="$4"
  local name_sha
  local metadata

  name_sha="$(sha256_text "$directory_name")"
  metadata="$(stat -c '%d|%i|%h|%u|%g|%a|%s|%Y' "$target")" \
    || fail "AUTHORITY_NODE_METADATA_UNOBSERVABLE"
  printf '%s|%s|%s|%s\n' "$label" "$name_sha" "$metadata" "$content_sha"
}

node_needs_repair() {
  local target="$1"
  local desired_mode="$2"
  local actual_uid
  local actual_gid
  local actual_mode

  actual_uid="$(stat -c '%u' "$target")"
  actual_gid="$(stat -c '%g' "$target")"
  actual_mode="$(stat -c '%a' "$target")"

  [ "$actual_uid" != "$expected_uid" ] \
    || [ "$actual_gid" != "$expected_gid" ] \
    || [ "$actual_mode" != "$desired_mode" ]
}

compute_state() {
  local index
  local directory
  local artifact
  local directory_record
  local file_record
  local target_records=""
  local target_identity_records=""

  selected_families=()
  selected_directories=()
  selected_directory_names=()
  selected_files=()
  selected_filenames=()

  select_latest_artifact \
    "career_runtime_publish_projection" \
    "career-runtime-publish-projection.json"
  select_latest_artifact \
    "career_release_ledger" \
    "career-full-release-ledger.json"

  projection_sha256="$(sha256sum "${selected_files[0]}" | awk '{print $1}')"
  ledger_sha256="$(sha256sum "${selected_files[1]}" | awk '{print $1}')"
  [[ "$projection_sha256" =~ ^[0-9a-f]{64}$ ]] || fail "PROJECTION_SHA_INVALID"
  [[ "$ledger_sha256" =~ ^[0-9a-f]{64}$ ]] || fail "LEDGER_SHA_INVALID"

  jq -e '
    .projection_kind == "career_runtime_publish_projection"
    and .projection_version == "career.runtime_publish_projection.v1"
    and (.items | type == "array")
    and (.items | length > 0)
    and ([.items[].locale] | unique | sort) == ["en", "zh"]
    and ([.items[] | (.slug + "|" + .locale)] | unique | length) == (.items | length)
  ' "${selected_files[0]}" >/dev/null || fail "PROJECTION_SCHEMA_INVALID"
  projection_locale_row_count="$(jq -r '.items | length' "${selected_files[0]}")"
  projection_unique_slug_count="$(jq -r '[.items[].slug] | unique | length' "${selected_files[0]}")"
  [ "$projection_locale_row_count" -eq $((projection_unique_slug_count * 2)) ] \
    || fail "PROJECTION_LOCALE_PARITY_INVALID"

  jq -e '
    .ledger_kind == "career_full_release_ledger"
    and (.ledger_version | type == "string")
    and (.members | type == "array")
    and (.members | length > 0)
  ' "${selected_files[1]}" >/dev/null || fail "LEDGER_SCHEMA_INVALID"
  ledger_member_count="$(jq -r '.members | length' "${selected_files[1]}")"

  for index in 0 1; do
    directory="${selected_directories[$index]}"
    artifact="${selected_files[$index]}"
    directory_record="$(node_record "${selected_families[$index]}:directory" "${selected_directory_names[$index]}" "$directory" "-")"
    file_record="$(node_record "${selected_families[$index]}:file" "${selected_directory_names[$index]}" "$artifact" "$([ "$index" -eq 0 ] && printf '%s' "$projection_sha256" || printf '%s' "$ledger_sha256")")"
    target_records+="${directory_record}"$'\n'"${file_record}"$'\n'
    target_identity_records+="$(
      printf '%s\n%s\n' "$directory_record" "$file_record" | cut -d '|' -f 1-5
    )"$'\n'
  done

  target_set_sha256="$(sha256_text "$target_identity_records")"
  snapshot_sha256="$(sha256_text "$target_records")"
  [[ "$target_set_sha256" =~ ^[0-9a-f]{64}$ ]] || fail "TARGET_SET_SHA_INVALID"
  [[ "$snapshot_sha256" =~ ^[0-9a-f]{64}$ ]] || fail "SNAPSHOT_SHA_INVALID"

  for index in 0 1; do
    can_access_directory "$expected_owner" "${selected_directories[$index]}" \
      || fail "DEPLOY_IDENTITY_ACCESS_MISSING"
    can_access_file "$expected_owner" "${selected_files[$index]}" \
      || fail "DEPLOY_IDENTITY_ACCESS_MISSING"
  done

  for directory in "$authority_root" \
    "$authority_root/career_runtime_publish_projection" \
    "$authority_root/career_release_ledger"; do
    can_access_directory "$runtime_user" "$directory" \
      || fail "RUNTIME_ANCESTOR_ACCESS_MISSING"
  done

  repair_target_count=0
  for index in 0 1; do
    if node_needs_repair "${selected_directories[$index]}" "2750"; then
      repair_target_count=$((repair_target_count + 1))
    fi
    if node_needs_repair "${selected_files[$index]}" "640"; then
      repair_target_count=$((repair_target_count + 1))
    fi
  done

  runtime_readable=true
  for index in 0 1; do
    if ! can_access_directory "$runtime_user" "${selected_directories[$index]}" \
      || ! can_access_file "$runtime_user" "${selected_files[$index]}"; then
      runtime_readable=false
    fi
  done
  if [ "$repair_target_count" -eq 0 ] && [ "$runtime_readable" != true ]; then
    fail "RUNTIME_ACCESS_UNREPAIRABLE_BY_TARGET_METADATA"
  fi
}

emit_state() {
  local status="$1"
  local metadata_write_count="$2"
  local bytes_unchanged="$3"

  jq -nc \
    --arg schema_version "$schema_version" \
    --arg status "$status" \
    --arg projection_sha256 "$projection_sha256" \
    --arg ledger_sha256 "$ledger_sha256" \
    --arg target_set_sha256 "$target_set_sha256" \
    --arg snapshot_sha256 "$snapshot_sha256" \
    --argjson projection_unique_slug_count "$projection_unique_slug_count" \
    --argjson projection_locale_row_count "$projection_locale_row_count" \
    --argjson ledger_member_count "$ledger_member_count" \
    --argjson repair_target_count "$repair_target_count" \
    --argjson runtime_readable "$runtime_readable" \
    --argjson metadata_write_count "$metadata_write_count" \
    --argjson bytes_unchanged "$bytes_unchanged" \
    '{
      schema_version:$schema_version,
      status:$status,
      projection_sha256:$projection_sha256,
      ledger_sha256:$ledger_sha256,
      projection_unique_slug_count:$projection_unique_slug_count,
      projection_locale_row_count:$projection_locale_row_count,
      ledger_member_count:$ledger_member_count,
      target_set_sha256:$target_set_sha256,
      snapshot_sha256:$snapshot_sha256,
      repair_target_count:$repair_target_count,
      runtime_readable:$runtime_readable,
      metadata_write_count:$metadata_write_count,
      bytes_unchanged:$bytes_unchanged,
      content_write_count:0,
      database_write_count:0,
      cache_write_count:0,
      publication_write_count:0,
      discoverability_write_count:0
    }'
}

case "$mode" in
  inspect|apply) ;;
  *) fail "MODE_INVALID" 2 ;;
esac
[ -n "$authority_root" ] || fail "ROOT_REQUIRED" 2
[[ "$authority_root" == /*/shared/backend/storage/app/private ]] || fail "ROOT_INVALID" 2
[[ "$authority_root" != *$'\n'* && "$authority_root" != *"/../"* ]] || fail "ROOT_INVALID" 2
[ -d "$authority_root" ] && [ ! -L "$authority_root" ] || fail "ROOT_INVALID" 2
[ "$(readlink -f "$authority_root")" = "$authority_root" ] || fail "ROOT_INVALID" 2
safe_account "$expected_owner" || fail "OWNER_INVALID" 2
safe_account "$expected_group" || fail "GROUP_INVALID" 2
safe_account "$runtime_user" || fail "RUNTIME_USER_INVALID" 2
expected_uid="$(id -u "$expected_owner")" || fail "OWNER_UNKNOWN" 2
expected_gid="$(getent group "$expected_group" | cut -d: -f3)" || fail "GROUP_UNKNOWN" 2
[[ "$expected_uid" =~ ^[0-9]+$ && "$expected_gid" =~ ^[0-9]+$ ]] || fail "ACCOUNT_IDENTITY_INVALID" 2
id -u "$runtime_user" >/dev/null 2>&1 || fail "RUNTIME_USER_UNKNOWN" 2

declare -a selected_families=()
declare -a selected_directories=()
declare -a selected_directory_names=()
declare -a selected_files=()
declare -a selected_filenames=()

compute_state

if [ "$mode" = "inspect" ]; then
  if [ "$repair_target_count" -eq 0 ] && [ "$runtime_readable" = true ]; then
    emit_state "PASS_ALREADY_RUNTIME_READABLE" 0 true
  else
    emit_state "PASS_PERMISSION_REPAIR_REQUIRED" 0 true
  fi
  exit 0
fi

[ "${CAREER_AUTHORITY_PERMISSION_APPLY_CONFIRMATION:-}" = "true" ] \
  || fail "EXPLICIT_APPLY_CONFIRMATION_REQUIRED" 2
expected_target_set_sha256="${CAREER_AUTHORITY_PERMISSION_EXPECTED_TARGET_SET_SHA256:-}"
expected_snapshot_sha256="${CAREER_AUTHORITY_PERMISSION_EXPECTED_SNAPSHOT_SHA256:-}"
expected_projection_sha256="${CAREER_AUTHORITY_PERMISSION_EXPECTED_PROJECTION_SHA256:-}"
expected_ledger_sha256="${CAREER_AUTHORITY_PERMISSION_EXPECTED_LEDGER_SHA256:-}"
expected_repair_target_count="${CAREER_AUTHORITY_PERMISSION_EXPECTED_REPAIR_TARGET_COUNT:-}"
for expected_sha in "$expected_target_set_sha256" "$expected_snapshot_sha256" \
  "$expected_projection_sha256" "$expected_ledger_sha256"; do
  [[ "$expected_sha" =~ ^[0-9a-f]{64}$ ]] || fail "EXPECTED_SHA_INVALID" 2
done
[[ "$expected_repair_target_count" =~ ^[1-4]$ ]] || fail "EXPECTED_REPAIR_COUNT_INVALID" 2
[ "$target_set_sha256" = "$expected_target_set_sha256" ] || fail "TARGET_SET_DRIFT"
[ "$snapshot_sha256" = "$expected_snapshot_sha256" ] || fail "SNAPSHOT_DRIFT"
[ "$projection_sha256" = "$expected_projection_sha256" ] || fail "PROJECTION_BYTES_DRIFT"
[ "$ledger_sha256" = "$expected_ledger_sha256" ] || fail "LEDGER_BYTES_DRIFT"
[ "$repair_target_count" = "$expected_repair_target_count" ] || fail "REPAIR_COUNT_DRIFT"

before_target_set_sha256="$target_set_sha256"
before_projection_sha256="$projection_sha256"
before_ledger_sha256="$ledger_sha256"
metadata_write_count=0
for index in 0 1; do
  if node_needs_repair "${selected_directories[$index]}" "2750"; then
    chown "$expected_owner:$expected_group" "${selected_directories[$index]}"
    chmod 2750 "${selected_directories[$index]}"
    metadata_write_count=$((metadata_write_count + 1))
  fi
  if node_needs_repair "${selected_files[$index]}" "640"; then
    chown "$expected_owner:$expected_group" "${selected_files[$index]}"
    chmod 0640 "${selected_files[$index]}"
    metadata_write_count=$((metadata_write_count + 1))
  fi
done

[ "$metadata_write_count" = "$expected_repair_target_count" ] || fail "METADATA_WRITE_COUNT_INVALID"
compute_state
[ "$target_set_sha256" = "$before_target_set_sha256" ] || fail "TARGET_IDENTITY_CHANGED_AFTER_APPLY"
[ "$projection_sha256" = "$before_projection_sha256" ] || fail "PROJECTION_BYTES_CHANGED_AFTER_APPLY"
[ "$ledger_sha256" = "$before_ledger_sha256" ] || fail "LEDGER_BYTES_CHANGED_AFTER_APPLY"
[ "$repair_target_count" -eq 0 ] || fail "PERMISSION_REPAIR_INCOMPLETE"
[ "$runtime_readable" = true ] || fail "RUNTIME_READBACK_FAILED"

emit_state "PASS_PERMISSION_REPAIR_VERIFIED" "$metadata_write_count" true
