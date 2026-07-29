#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
paths_file="$script_dir/shared_permissions_paths.txt"
shared_root="${SHARED_PERMISSIONS_ROOT:-}"
expected_owner="${SHARED_PERMISSIONS_OWNER:-}"
expected_group="${SHARED_PERMISSIONS_GROUP:-}"
apply="${SHARED_PERMISSIONS_APPLY:-false}"

fail() {
  printf 'shared_permissions_provisioning_status=failure\n' >&2
  printf 'shared_permissions_provisioning_reason=%s\n' "$1" >&2
  exit "${2:-1}"
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

[ "$apply" = "true" ] || fail "EXPLICIT_PROVISIONING_REQUIRED" 2
[ -n "$shared_root" ] || fail "ROOT_REQUIRED" 2
[ -n "$expected_owner" ] || fail "OWNER_REQUIRED" 2
[ -n "$expected_group" ] || fail "GROUP_REQUIRED" 2
account_is_safe "$expected_owner" || fail "OWNER_INVALID" 2
account_is_safe "$expected_group" || fail "GROUP_INVALID" 2
[[ "$shared_root" == /*/shared ]] || fail "ROOT_INVALID" 2
[[ "$shared_root" != *$'\n'* && "$shared_root" != *"/../"* ]] || fail "ROOT_INVALID" 2
[ -d "$(dirname "$shared_root")" ] || fail "ROOT_PARENT_MISSING" 2
[ -f "$paths_file" ] && [ ! -L "$paths_file" ] || fail "PATH_MANIFEST_INVALID" 2

if [ -e "$shared_root" ] && { [ ! -d "$shared_root" ] || [ -L "$shared_root" ]; }; then
  fail "ROOT_INVALID" 2
fi

mkdir -p -- "$shared_root"

checked=0
while IFS= read -r relative_path || [ -n "$relative_path" ]; do
  [[ "$relative_path" =~ ^[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)*$ ]] \
    || fail "PATH_MANIFEST_INVALID" 2
  [[ "$relative_path" != *".."* ]] || fail "PATH_MANIFEST_INVALID" 2

  target="$shared_root/$relative_path"
  if [ -e "$target" ] && { [ ! -d "$target" ] || [ -L "$target" ]; }; then
    fail "TARGET_INVALID" 3
  fi

  mkdir -p -- "$target"
  current_owner_group="$(stat_owner_group "$target")" || fail "OWNER_GROUP_UNOBSERVABLE" 3
  if [ "$current_owner_group" != "$expected_owner:$expected_group" ]; then
    chown "$expected_owner:$expected_group" "$target"
  fi
  current_mode="$(stat_mode "$target")" || fail "DIRECTORY_MODE_UNOBSERVABLE" 3
  if [ "$current_mode" != "2775" ]; then
    chmod 2775 "$target"
  fi
  checked=$((checked + 1))
done < "$paths_file"

[ "$checked" -ge 1 ] && [ "$checked" -le 32 ] || fail "PATH_MANIFEST_INVALID" 2

current_owner_group="$(stat_owner_group "$shared_root")" || fail "OWNER_GROUP_UNOBSERVABLE" 3
if [ "$current_owner_group" != "$expected_owner:$expected_group" ]; then
  chown "$expected_owner:$expected_group" "$shared_root"
fi
current_mode="$(stat_mode "$shared_root")" || fail "DIRECTORY_MODE_UNOBSERVABLE" 3
if [ "$current_mode" != "2775" ]; then
  chmod 2775 "$shared_root"
fi

printf 'shared_permissions_provisioning_status=success\n'
printf 'shared_permissions_provisioned=%s\n' "$((checked + 1))"
