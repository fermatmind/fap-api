#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
paths_file="$script_dir/shared_permissions_paths.txt"
shared_root="${SHARED_PERMISSIONS_ROOT:-}"
expected_owner="${SHARED_PERMISSIONS_OWNER:-}"
expected_group="${SHARED_PERMISSIONS_GROUP:-}"
runtime_user="${SHARED_PERMISSIONS_RUNTIME_USER:-}"

fail() {
  printf 'shared_permissions_status=failure\n' >&2
  printf 'shared_permissions_reason=%s\n' "$1" >&2
  printf 'shared_permissions_target_index=%s\n' "${2:-0}" >&2
  printf 'shared_permissions_action=run_explicit_shared_permissions_provisioning\n' >&2
  exit "${3:-1}"
}

account_is_safe() {
  [[ "$1" =~ ^[A-Za-z0-9_.-]+$ ]]
}

stat_owner_group() {
  stat -c '%U:%G' "$1" 2>/dev/null || stat -f '%Su:%Sg' "$1" 2>/dev/null
}

stat_mode() {
  local mode

  if mode="$(stat -c '%a' "$1" 2>/dev/null)"; then
    printf '%s\n' "$mode"
    return
  fi

  mode="$(stat -f '%p' "$1" 2>/dev/null)" || return 1
  printf '%s\n' "${mode: -4}"
}

runtime_can_access() {
  local target="$1"

  if [ "$(id -un)" = "$runtime_user" ]; then
    [ -r "$target" ] && [ -w "$target" ] && [ -x "$target" ]
    return
  fi

  command -v sudo >/dev/null 2>&1 || return 1
  sudo -n -u "$runtime_user" -- sh -c \
    'test -r "$1" && test -w "$1" && test -x "$1"' sh "$target" \
    >/dev/null 2>&1
}

verify_target() {
  local target="$1"
  local target_index="$2"
  local owner_group
  local mode
  local mode_value

  [ -d "$target" ] && [ ! -L "$target" ] || fail "DIRECTORY_MISSING" "$target_index" 3

  owner_group="$(stat_owner_group "$target")" \
    || fail "OWNER_GROUP_UNOBSERVABLE" "$target_index" 3
  [ "$owner_group" = "$expected_owner:$expected_group" ] \
    || fail "OWNER_GROUP_MISMATCH" "$target_index" 3

  mode="$(stat_mode "$target")" || fail "DIRECTORY_MODE_UNOBSERVABLE" "$target_index" 3
  [[ "$mode" =~ ^[0-7]{3,4}$ ]] || fail "DIRECTORY_MODE_UNOBSERVABLE" "$target_index" 3
  mode_value=$((8#$mode))
  if (( (mode_value & 02070) != 02070 || (mode_value & 0002) != 0 )); then
    fail "DIRECTORY_MODE_INVALID" "$target_index" 3
  fi

  [ -r "$target" ] && [ -w "$target" ] && [ -x "$target" ] \
    || fail "DEPLOY_USER_CAPABILITY_MISSING" "$target_index" 3
  runtime_can_access "$target" || fail "RUNTIME_USER_CAPABILITY_MISSING" "$target_index" 3
}

[ -n "$shared_root" ] || fail "ROOT_REQUIRED" 0 2
[ -n "$expected_owner" ] || fail "OWNER_REQUIRED" 0 2
[ -n "$expected_group" ] || fail "GROUP_REQUIRED" 0 2
[ -n "$runtime_user" ] || fail "RUNTIME_USER_REQUIRED" 0 2
account_is_safe "$expected_owner" || fail "OWNER_INVALID" 0 2
account_is_safe "$expected_group" || fail "GROUP_INVALID" 0 2
account_is_safe "$runtime_user" || fail "RUNTIME_USER_INVALID" 0 2
[[ "$shared_root" == /*/shared ]] || fail "ROOT_INVALID" 0 2
[[ "$shared_root" != *$'\n'* && "$shared_root" != *"/../"* ]] || fail "ROOT_INVALID" 0 2
[ -f "$paths_file" ] && [ ! -L "$paths_file" ] || fail "PATH_MANIFEST_INVALID" 0 2
id -u "$runtime_user" >/dev/null 2>&1 || fail "RUNTIME_USER_UNKNOWN" 0 2

verify_target "$shared_root" 0

checked=0
while IFS= read -r relative_path || [ -n "$relative_path" ]; do
  [[ "$relative_path" =~ ^[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)*$ ]] \
    || fail "PATH_MANIFEST_INVALID" 0 2
  [[ "$relative_path" != *".."* ]] || fail "PATH_MANIFEST_INVALID" 0 2

  checked=$((checked + 1))
  [ "$checked" -le 32 ] || fail "PATH_MANIFEST_INVALID" 0 2
  verify_target "$shared_root/$relative_path" "$checked"
done < "$paths_file"

[ "$checked" -ge 1 ] || fail "PATH_MANIFEST_INVALID" 0 2

printf 'shared_permissions_status=success\n'
printf 'shared_permissions_checked=%s\n' "$((checked + 1))"
