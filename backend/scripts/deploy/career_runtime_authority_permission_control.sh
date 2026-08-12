#!/usr/bin/env bash
set -euo pipefail

export LC_ALL=C

readonly schema_version="career.runtime_authority_pointer_permission_control.v2"
readonly generation_id="${CAREER_AUTHORITY_PERMISSION_GENERATION_ID:-career-current-342-30-bootstrap-v1}"
readonly pointer_document_sha256="${CAREER_AUTHORITY_PERMISSION_POINTER_SHA256:-1ebfd2826be9d3b63d810d33050034e3d424c95b3db81fa49b0822c5e6b2ec08}"
readonly projection_sha256_expected="${CAREER_AUTHORITY_PERMISSION_PROJECTION_SHA256:-397f2a4ec284e9c0a6cd610447541ad4773fa7a7f3045008fab5efb334ec85c6}"
readonly ledger_sha256_expected="${CAREER_AUTHORITY_PERMISSION_LEDGER_SHA256:-975b311bb346a090f1add678d5a6d9f1be230f87b223e2c3c829f4c7fd7aac6e}"
readonly projection_path_sha256_expected="${CAREER_AUTHORITY_PERMISSION_PROJECTION_PATH_SHA256:-32e4e5f30b56f96c7fda9b3eff03b02e23a406869d78ed9d223d59f0749586d1}"
readonly ledger_path_sha256_expected="${CAREER_AUTHORITY_PERMISSION_LEDGER_PATH_SHA256:-a7501519b6ca3f8c529e0ca2283985bd3900c0b9679c871c2c56197f324e013e}"
readonly frozen_manifest_sha256="${CAREER_AUTHORITY_PERMISSION_FROZEN_MANIFEST_SHA256:-b570ec0cdda65278aa543431886b3529d072de8d67a8e79f1cafbb1c4c8dfc0e}"
readonly receipt_set_sha256="${CAREER_AUTHORITY_PERMISSION_RECEIPT_SET_SHA256:-504646d8317e30279688d361e0dfbdb3104b11baea93cae21cacb1b6dc7da098}"
readonly slug_set_sha256_expected="${CAREER_AUTHORITY_PERMISSION_SLUG_SET_SHA256:-8b328b2e002875a9f92d4c406981f3c3724f066ee817d2d5bd1a61915e1eddf5}"
readonly locale_row_set_sha256_expected="${CAREER_AUTHORITY_PERMISSION_LOCALE_ROW_SET_SHA256:-607926991fa51c74d6d6c9606ab3b7f8f35918996006a39c68963c16765d5697}"
readonly expected_slug_count="${CAREER_AUTHORITY_PERMISSION_SLUG_COUNT:-342}"
readonly expected_locale_row_count="${CAREER_AUTHORITY_PERMISSION_LOCALE_ROW_COUNT:-684}"
readonly expected_published_slug_count="${CAREER_AUTHORITY_PERMISSION_PUBLISHED_SLUG_COUNT:-30}"
readonly expected_published_locale_row_count="${CAREER_AUTHORITY_PERMISSION_PUBLISHED_LOCALE_ROW_COUNT:-60}"

mode="${CAREER_AUTHORITY_PERMISSION_MODE:-inspect}"
authority_root="${CAREER_AUTHORITY_PERMISSION_ROOT:-}"
deploy_path="${DEPLOY_PATH:-}"
expected_active_revision="${EXPECTED_ACTIVE_REVISION:-}"
expected_owner="${CAREER_AUTHORITY_PERMISSION_OWNER:-}"
expected_group="${CAREER_AUTHORITY_PERMISSION_GROUP:-}"
runtime_user="${CAREER_AUTHORITY_PERMISSION_RUNTIME_USER:-}"

fail() {
  jq -nc --arg schema_version "$schema_version" --arg status "HOLD_POINTER_BOUND_PERMISSION_CONTROL" --arg reason "$1" \
    '{schema_version:$schema_version,status:$status,reason:$reason,content_write_count:0,database_write_count:0,cms_write_count:0,cache_write_count:0,publication_write_count:0,discoverability_write_count:0,migration_count:0,deployment_count:0,restart_count:0}'
  exit "${2:-1}"
}

sha256_text() {
  printf '%s' "$1" | sha256sum | awk '{print $1}'
}

safe_account() {
  [[ "$1" =~ ^[A-Za-z_][A-Za-z0-9_.-]{0,31}$ ]]
}

can_access_directory() {
  local user="$1" target="$2"
  if [ "$(id -u)" -eq "$(id -u "$user")" ]; then
    [ -r "$target" ] && [ -x "$target" ]
    return
  fi
  command -v sudo >/dev/null 2>&1 || return 1
  sudo -n -u "$user" -- sh -c 'test -r "$1" && test -x "$1"' sh "$target" >/dev/null 2>&1
}

can_access_file() {
  local user="$1" target="$2"
  if [ "$(id -u)" -eq "$(id -u "$user")" ]; then
    [ -r "$target" ]
    return
  fi
  command -v sudo >/dev/null 2>&1 || return 1
  sudo -n -u "$user" -- test -r "$target" >/dev/null 2>&1
}

validate_runtime_fence() {
  local current_release revision process_count
  [[ "$deploy_path" =~ ^/[A-Za-z0-9._/-]+$ ]] || fail "DEPLOY_PATH_INVALID"
  [[ "$deploy_path" != *"/../"* ]] || fail "DEPLOY_PATH_INVALID"
  [[ "$expected_active_revision" =~ ^[0-9a-f]{40}$ ]] || fail "ACTIVE_REVISION_INVALID"
  current_release="$(readlink -f "$deploy_path/current")" || fail "ACTIVE_RELEASE_UNRESOLVED"
  [ -d "$current_release" ] && [ ! -L "$current_release" ] || fail "ACTIVE_RELEASE_INVALID"
  [ -f "$current_release/REVISION" ] && [ ! -L "$current_release/REVISION" ] || fail "ACTIVE_REVISION_UNREADABLE"
  revision="$(tr -d '\r\n' < "$current_release/REVISION")"
  [ "$revision" = "$expected_active_revision" ] || fail "ACTIVE_REVISION_DRIFT"
  [ ! -e "$deploy_path/.dep/deploy.lock" ] && [ ! -L "$deploy_path/.dep/deploy.lock" ] || fail "DEPLOY_LOCK_PRESENT"
  process_count="$(ps -eo comm=,args= | awk '$1=="php" && ($0 ~ /dep(\.phar)? .* production/ || $0 ~ /artisan migrate/ || $0 ~ /queue:reload-workers/) {n++} $1=="composer" && $0 ~ /install/ {n++} END {print n+0}')"
  [ "$process_count" -eq 0 ] || fail "DEPLOY_OR_MIGRATION_PROCESS_PRESENT"
}

validate_bound_path() {
  local relative="$1" family="$2" filename="$3" expected_path_sha="$4"
  local parent target resolved_root resolved_family resolved_parent resolved_target
  [[ "${relative%/*}" =~ ^${family}/[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ ]] \
    && [ "${relative##*/}" = "$filename" ] || fail "DESCRIPTOR_PATH_CONTRACT_INVALID"
  [ "$(sha256_text "$relative")" = "$expected_path_sha" ] || fail "DESCRIPTOR_PATH_IDENTITY_MISMATCH"
  parent="$authority_root/${relative%/*}"
  target="$authority_root/$relative"
  [ -d "$authority_root/$family" ] && [ ! -L "$authority_root/$family" ] || fail "AUTHORITY_FAMILY_ROOT_INVALID"
  [ -d "$parent" ] && [ ! -L "$parent" ] || fail "POINTER_BOUND_DIRECTORY_INVALID"
  [ -f "$target" ] && [ ! -L "$target" ] || fail "POINTER_BOUND_FILE_INVALID"
  [ "$(stat -c '%h' "$target")" = 1 ] || fail "POINTER_BOUND_FILE_LINK_COUNT_INVALID"
  resolved_root="$(readlink -f "$authority_root")" || fail "AUTHORITY_ROOT_UNRESOLVED"
  resolved_family="$(readlink -f "$authority_root/$family")" || fail "AUTHORITY_FAMILY_ROOT_UNRESOLVED"
  resolved_parent="$(readlink -f "$parent")" || fail "POINTER_BOUND_DIRECTORY_UNRESOLVED"
  resolved_target="$(readlink -f "$target")" || fail "POINTER_BOUND_FILE_UNRESOLVED"
  [ "$resolved_root" = "$authority_root" ] || fail "AUTHORITY_ROOT_IDENTITY_INVALID"
  [ "$resolved_family" = "$authority_root/$family" ] || fail "AUTHORITY_FAMILY_ROOT_ESCAPE"
  [[ "$resolved_parent" == "$resolved_family/"* ]] || fail "POINTER_BOUND_DIRECTORY_ESCAPE"
  [ "$resolved_target" = "$target" ] || fail "POINTER_BOUND_FILE_ESCAPE"
}

node_record() {
  local label="$1" relative="$2" target="$3" content_sha="$4" metadata
  metadata="$(stat -c '%d|%i|%h|%u|%g|%a|%s' "$target")" || fail "AUTHORITY_NODE_METADATA_UNOBSERVABLE"
  printf '%s|%s|%s|%s\n' "$label" "$(sha256_text "$relative")" "$metadata" "$content_sha"
}

node_needs_repair() {
  local target="$1" desired_mode="$2"
  [ "$(stat -c '%u' "$target")" != "$expected_uid" ] \
    || [ "$(stat -c '%g' "$target")" != "$expected_gid" ] \
    || [ "$(stat -c '%a' "$target")" != "$desired_mode" ]
}

compute_state() {
  local pointer_root active_pointer immutable_pointer active_sha immutable_sha
  local projection_relative ledger_relative projection_dir ledger_dir
  local projection_rows ledger_rows slug_rows locale_rows published_slug_rows published_locale_rows
  local target_records target_identity_records index target relative label content_sha

  validate_runtime_fence
  pointer_root="$authority_root/career_generation_authority"
  active_pointer="$pointer_root/active-generation.json"
  immutable_pointer="$pointer_root/generations/$generation_id/generation-pointer.json"
  for target in "$pointer_root" "$pointer_root/generations" "$pointer_root/generations/$generation_id"; do
    [ -d "$target" ] && [ ! -L "$target" ] || fail "POINTER_DIRECTORY_INVALID"
  done
  for target in "$active_pointer" "$immutable_pointer"; do
    [ -f "$target" ] && [ ! -L "$target" ] || fail "POINTER_DOCUMENT_INVALID"
    [ "$(stat -c '%h' "$target")" = 1 ] || fail "POINTER_DOCUMENT_LINK_COUNT_INVALID"
  done
  active_sha="$(sha256sum "$active_pointer" | awk '{print $1}')"
  immutable_sha="$(sha256sum "$immutable_pointer" | awk '{print $1}')"
  [ "$active_sha" = "$pointer_document_sha256" ] || fail "ACTIVE_POINTER_SHA_MISMATCH"
  [ "$immutable_sha" = "$pointer_document_sha256" ] || fail "IMMUTABLE_POINTER_SHA_MISMATCH"
  cmp -s "$active_pointer" "$immutable_pointer" || fail "POINTER_DOCUMENT_BYTES_MISMATCH"
  jq -e \
    --arg generation "$generation_id" \
    --arg pointer_sha "$pointer_document_sha256" \
    --arg projection_sha "$projection_sha256_expected" \
    --arg ledger_sha "$ledger_sha256_expected" \
    --arg manifest_sha "$frozen_manifest_sha256" \
    --arg receipt_set_sha "$receipt_set_sha256" \
    --arg slug_set_sha "$slug_set_sha256_expected" \
    --arg locale_set_sha "$locale_row_set_sha256_expected" \
    '.schema_version == "career.generation_pointer.v1"
     and (.payload_sha256 | test("^[0-9a-f]{64}$"))
     and .payload.generation_id == $generation
     and .payload.artifact_format == "legacy_exact_bytes_v1"
     and .payload.artifacts.projection.identity == ("career-runtime-publish-projection@" + $generation)
     and .payload.artifacts.projection.sha256 == $projection_sha
     and .payload.artifacts.ledger.identity == ("career-full-release-ledger@" + $generation)
     and .payload.artifacts.ledger.sha256 == $ledger_sha
     and .payload.authority.frozen_manifest_sha256 == $manifest_sha
     and .payload.authority.receipt_set_sha256 == $receipt_set_sha
     and .payload.authority.target_slug_set_sha256 == $slug_set_sha
     and .payload.authority.target_locale_row_set_sha256 == $locale_set_sha
     and .payload.counts.public_slug_count == ($published_slugs | tonumber)
     and .payload.counts.public_locale_row_count == ($published_rows | tonumber)
     and .payload.lineage.previous_generation_id == null
     and .payload.lineage.previous_pointer_sha256 == null
     and .payload.rollback.eligible == false
     and .payload.discoverability.sitemap_mutated == false
     and .payload.discoverability.llms_mutated == false
    and .payload.discoverability.search_mutated == false' \
    --arg published_slugs "$expected_published_slug_count" \
    --arg published_rows "$expected_published_locale_row_count" \
    "$active_pointer" >/dev/null \
    || fail "POINTER_CONTRACT_MISMATCH"

  projection_relative="$(jq -er '.payload.artifacts.projection.path' "$active_pointer")" || fail "PROJECTION_DESCRIPTOR_MISSING"
  ledger_relative="$(jq -er '.payload.artifacts.ledger.path' "$active_pointer")" || fail "LEDGER_DESCRIPTOR_MISSING"
  validate_bound_path "$projection_relative" "career_runtime_publish_projection" "career-runtime-publish-projection.json" "$projection_path_sha256_expected"
  validate_bound_path "$ledger_relative" "career_release_ledger" "career-full-release-ledger.json" "$ledger_path_sha256_expected"

  projection_path="$authority_root/$projection_relative"
  ledger_path="$authority_root/$ledger_relative"
  projection_dir="${projection_path%/*}"
  ledger_dir="${ledger_path%/*}"
  projection_sha256="$(sha256sum "$projection_path" | awk '{print $1}')"
  ledger_sha256="$(sha256sum "$ledger_path" | awk '{print $1}')"
  [ "$projection_sha256" = "$projection_sha256_expected" ] || fail "PROJECTION_BYTES_DRIFT"
  [ "$ledger_sha256" = "$ledger_sha256_expected" ] || fail "LEDGER_BYTES_DRIFT"

  jq -e '
    .projection_kind == "career_runtime_publish_projection"
    and .projection_version == "career.runtime_publish_projection.v1"
    and .source_authority == "CareerFullReleaseLedger"
    and (.items | type == "array" and length > 0)
    and all(.items[]; (.slug | type == "string" and test("^[a-z0-9]+(?:-[a-z0-9]+)*$"))
      and (.locale == "en" or .locale == "zh"))
    and ([.items[] | (.slug + "|" + .locale)] | unique | length) == (.items | length)
    and all(.items[] | select(.runtime_publish_state == "published");
      .public_resolution_type == "public_canonical_job" and .release_gate_pass == true)
  ' "$projection_path" >/dev/null || fail "PROJECTION_CONTRACT_INVALID"
  projection_rows="$(jq -r '.items | length' "$projection_path")"
  slug_rows="$(jq -r '[.items[].slug] | unique | length' "$projection_path")"
  locale_rows="$(jq -r '[.items[] | (.slug + "|" + .locale)] | unique | length' "$projection_path")"
  published_slug_rows="$(jq -r '[.items[] | select(.runtime_publish_state == "published") | .slug] | unique | length' "$projection_path")"
  published_locale_rows="$(jq -r '[.items[] | select(.runtime_publish_state == "published") | (.slug + "|" + .locale)] | unique | length' "$projection_path")"
  [ "$projection_rows" -eq "$expected_locale_row_count" ] && [ "$slug_rows" -eq "$expected_slug_count" ] \
    && [ "$locale_rows" -eq "$expected_locale_row_count" ] \
    && [ "$published_slug_rows" -eq "$expected_published_slug_count" ] \
    && [ "$published_locale_rows" -eq "$expected_published_locale_row_count" ] \
    || fail "PROJECTION_AUTHORITY_COUNT_MISMATCH"
  [ "$(jq -r '.items[].slug' "$projection_path" | sort -u | awk '{printf "%s\\n",$0}' | sha256sum | awk '{print $1}')" = "$slug_set_sha256_expected" ] \
    || fail "PROJECTION_SLUG_SET_MISMATCH"
  [ "$(jq -r '.items[] | (.slug + "|" + .locale)' "$projection_path" | tr '[:upper:]' '[:lower:]' | sort -u | awk '{printf "%s\\n",$0}' | sha256sum | awk '{print $1}')" = "$locale_row_set_sha256_expected" ] \
    || fail "PROJECTION_LOCALE_SET_MISMATCH"

  jq -e '
    .ledger_kind == "career_full_release_ledger"
    and (.ledger_version | type == "string" and length > 0)
    and (((.public_resolution.rows // .members) | type) == "array")
    and (((.public_resolution.rows // .members) | length) > 0)
  ' "$ledger_path" >/dev/null || fail "LEDGER_CONTRACT_INVALID"
  ledger_rows="$(jq -r '[((.public_resolution.rows // .members)[]) | (.source_slug // .canonical_slug // .slug)] | unique | length' "$ledger_path")"
  [ "$ledger_rows" -eq "$expected_slug_count" ] || fail "LEDGER_AUTHORITY_COUNT_MISMATCH"
  [ "$(jq -r '((.public_resolution.rows // .members)[]) | (.source_slug // .canonical_slug // .slug)' "$ledger_path" | sort -u | awk '{printf "%s\\n",$0}' | sha256sum | awk '{print $1}')" = "$slug_set_sha256_expected" ] \
    || fail "LEDGER_SLUG_SET_MISMATCH"

  selected_directories=("$projection_dir" "$ledger_dir")
  selected_files=("$projection_path" "$ledger_path")
  selected_relatives=("${projection_relative%/*}" "${ledger_relative%/*}" "$projection_relative" "$ledger_relative")
  target_records=""
  target_identity_records=""
  for index in 0 1 2 3; do
    if [ "$index" -lt 2 ]; then
      target="${selected_directories[$index]}"
      label="$([ "$index" -eq 0 ] && printf projection_directory || printf ledger_directory)"
      content_sha="-"
    else
      target="${selected_files[$((index - 2))]}"
      label="$([ "$index" -eq 2 ] && printf projection_file || printf ledger_file)"
      content_sha="$([ "$index" -eq 2 ] && printf '%s' "$projection_sha256" || printf '%s' "$ledger_sha256")"
    fi
    record="$(node_record "$label" "${selected_relatives[$index]}" "$target" "$content_sha")"
    target_records+="${record}"$'\n'
    target_identity_records+="$(printf '%s\n' "$record" | cut -d '|' -f 1-5)"$'\n'
  done
  target_set_sha256="$(sha256_text "$target_identity_records")"
  snapshot_sha256="$(sha256_text "$target_records")"

  for target in "$authority_root" "$pointer_root" "$pointer_root/generations" "$pointer_root/generations/$generation_id" \
    "$authority_root/career_runtime_publish_projection" "$authority_root/career_release_ledger"; do
    can_access_directory "$runtime_user" "$target" || fail "RUNTIME_ANCESTOR_ACCESS_MISSING"
  done
  for index in 0 1; do
    can_access_directory "$expected_owner" "${selected_directories[$index]}" || fail "DEPLOY_IDENTITY_ACCESS_MISSING"
    can_access_file "$expected_owner" "${selected_files[$index]}" || fail "DEPLOY_IDENTITY_ACCESS_MISSING"
  done

  repair_target_count=0
  for index in 0 1; do
    node_needs_repair "${selected_directories[$index]}" 2750 && repair_target_count=$((repair_target_count + 1))
    node_needs_repair "${selected_files[$index]}" 640 && repair_target_count=$((repair_target_count + 1))
  done
  runtime_readable=true
  for index in 0 1; do
    if ! can_access_directory "$runtime_user" "${selected_directories[$index]}" \
      || ! can_access_file "$runtime_user" "${selected_files[$index]}"; then
      runtime_readable=false
    fi
  done
  if [ "$repair_target_count" -eq 0 ] && [ "$runtime_readable" != true ]; then
    fail "RUNTIME_ACCESS_UNREPAIRABLE_BY_BOUND_METADATA"
  fi
}

emit_state() {
  local status="$1" metadata_write_count="$2"
  jq -nc \
    --arg schema_version "$schema_version" --arg status "$status" --arg generation_id "$generation_id" \
    --arg pointer_document_sha256 "$pointer_document_sha256" \
    --arg projection_path_sha256 "$projection_path_sha256_expected" --arg ledger_path_sha256 "$ledger_path_sha256_expected" \
    --arg projection_sha256 "$projection_sha256" --arg ledger_sha256 "$ledger_sha256" \
    --arg slug_set_sha256 "$slug_set_sha256_expected" --arg locale_row_set_sha256 "$locale_row_set_sha256_expected" \
    --arg target_set_sha256 "$target_set_sha256" --arg snapshot_sha256 "$snapshot_sha256" \
    --argjson repair_target_count "$repair_target_count" --argjson runtime_readable "$runtime_readable" \
    --argjson metadata_write_count "$metadata_write_count" \
    --argjson slug_count "$expected_slug_count" --argjson locale_count "$expected_locale_row_count" \
    --argjson published_slug_count "$expected_published_slug_count" --argjson published_locale_count "$expected_published_locale_row_count" \
    '{schema_version:$schema_version,status:$status,generation_id:$generation_id,pointer_document_sha256:$pointer_document_sha256,projection_path_sha256:$projection_path_sha256,ledger_path_sha256:$ledger_path_sha256,projection_sha256:$projection_sha256,ledger_sha256:$ledger_sha256,slug_set_sha256:$slug_set_sha256,locale_row_set_sha256:$locale_row_set_sha256,projection_unique_slug_count:$slug_count,projection_locale_row_count:$locale_count,published_slug_count:$published_slug_count,published_locale_row_count:$published_locale_count,ledger_member_count:$slug_count,target_set_sha256:$target_set_sha256,snapshot_sha256:$snapshot_sha256,repair_target_count:$repair_target_count,runtime_readable:$runtime_readable,metadata_write_count:$metadata_write_count,bytes_unchanged:true,active_revision_unchanged:true,deploy_lock_absent:true,deploy_process_absent:true,migration_process_absent:true,content_write_count:0,database_write_count:0,cms_write_count:0,cache_write_count:0,publication_write_count:0,discoverability_write_count:0,migration_count:0,deployment_count:0,restart_count:0,automatic_retry_allowed:false}'
}

case "$mode" in inspect|apply) ;; *) fail "MODE_INVALID" 2 ;; esac
[ -n "$authority_root" ] && [[ "$authority_root" == /*/shared/backend/storage/app/private ]] || fail "ROOT_INVALID" 2
[[ "$authority_root" != *$'\n'* && "$authority_root" != *"/../"* ]] || fail "ROOT_INVALID" 2
[ -d "$authority_root" ] && [ ! -L "$authority_root" ] && [ "$(readlink -f "$authority_root")" = "$authority_root" ] || fail "ROOT_INVALID" 2
safe_account "$expected_owner" || fail "OWNER_INVALID" 2
safe_account "$expected_group" || fail "GROUP_INVALID" 2
safe_account "$runtime_user" || fail "RUNTIME_USER_INVALID" 2
expected_uid="$(id -u "$expected_owner")" || fail "OWNER_UNKNOWN" 2
expected_gid="$(getent group "$expected_group" | cut -d: -f3)" || fail "GROUP_UNKNOWN" 2
[[ "$expected_uid" =~ ^[0-9]+$ && "$expected_gid" =~ ^[0-9]+$ ]] || fail "ACCOUNT_IDENTITY_INVALID" 2
id -u "$runtime_user" >/dev/null 2>&1 || fail "RUNTIME_USER_UNKNOWN" 2

declare -a selected_directories=() selected_files=() selected_relatives=()
compute_state

if [ "$mode" = inspect ]; then
  if [ "$repair_target_count" -eq 0 ] && [ "$runtime_readable" = true ]; then
    emit_state "PASS_POINTER_BOUND_ALREADY_RUNTIME_READABLE" 0
  else
    emit_state "PASS_POINTER_BOUND_PERMISSION_REPAIR_REQUIRED" 0
  fi
  exit 0
fi

[ "${CAREER_AUTHORITY_PERMISSION_APPLY_CONFIRMATION:-}" = true ] || fail "EXPLICIT_APPLY_CONFIRMATION_REQUIRED" 2
expected_target_set="${CAREER_AUTHORITY_PERMISSION_EXPECTED_TARGET_SET_SHA256:-}"
expected_snapshot="${CAREER_AUTHORITY_PERMISSION_EXPECTED_SNAPSHOT_SHA256:-}"
expected_repair_count="${CAREER_AUTHORITY_PERMISSION_EXPECTED_REPAIR_TARGET_COUNT:-}"
[ "$expected_target_set" = "$target_set_sha256" ] || fail "TARGET_SET_DRIFT"
[ "$expected_snapshot" = "$snapshot_sha256" ] || fail "SNAPSHOT_DRIFT"
[[ "$expected_repair_count" =~ ^[1-4]$ ]] && [ "$expected_repair_count" -eq "$repair_target_count" ] || fail "REPAIR_COUNT_DRIFT"
[ "${CAREER_AUTHORITY_PERMISSION_EXPECTED_POINTER_SHA256:-}" = "$pointer_document_sha256" ] || fail "POINTER_RECEIPT_BINDING_MISMATCH"
[ "${CAREER_AUTHORITY_PERMISSION_EXPECTED_PROJECTION_PATH_SHA256:-}" = "$projection_path_sha256_expected" ] || fail "PROJECTION_PATH_RECEIPT_BINDING_MISMATCH"
[ "${CAREER_AUTHORITY_PERMISSION_EXPECTED_LEDGER_PATH_SHA256:-}" = "$ledger_path_sha256_expected" ] || fail "LEDGER_PATH_RECEIPT_BINDING_MISMATCH"

before_target_set="$target_set_sha256"
before_projection="$projection_sha256"
before_ledger="$ledger_sha256"
metadata_write_count=0
for index in 0 1; do
  if node_needs_repair "${selected_directories[$index]}" 2750; then
    chown "$expected_owner:$expected_group" "${selected_directories[$index]}"
    chmod 2750 "${selected_directories[$index]}"
    metadata_write_count=$((metadata_write_count + 1))
  fi
  if node_needs_repair "${selected_files[$index]}" 640; then
    chown "$expected_owner:$expected_group" "${selected_files[$index]}"
    chmod 0640 "${selected_files[$index]}"
    metadata_write_count=$((metadata_write_count + 1))
  fi
done
[ "$metadata_write_count" -eq "$expected_repair_count" ] || fail "METADATA_WRITE_COUNT_INVALID"
compute_state
[ "$target_set_sha256" = "$before_target_set" ] || fail "TARGET_IDENTITY_CHANGED_AFTER_APPLY"
[ "$projection_sha256" = "$before_projection" ] || fail "PROJECTION_BYTES_CHANGED_AFTER_APPLY"
[ "$ledger_sha256" = "$before_ledger" ] || fail "LEDGER_BYTES_CHANGED_AFTER_APPLY"
[ "$repair_target_count" -eq 0 ] && [ "$runtime_readable" = true ] || fail "PERMISSION_REPAIR_READBACK_FAILED"
emit_state "PASS_POINTER_BOUND_PERMISSION_REPAIR_VERIFIED" "$metadata_write_count"
