#!/usr/bin/env bash
set -euo pipefail

php_bin="/usr/bin/php"
deploy_path=""
crontab_bin="/usr/bin/crontab"
for argument in "$@"; do
  case "$argument" in
    --php-bin=*) php_bin="${argument#*=}" ;;
    --deploy-path=*) deploy_path="${argument#*=}" ;;
    --crontab=*) crontab_bin="${argument#*=}" ;;
    *) printf 'staging_sitemap_cron_invalid_argument\n' >&2; exit 2 ;;
  esac
done

fail() { printf 'staging_sitemap_cron_failed reason=%s\n' "$1" >&2; exit 1; }
for path in "$php_bin" "$deploy_path" "$crontab_bin"; do
  [[ "$path" =~ ^/[A-Za-z0-9._/-]+$ && "$path" != *".."* ]] || fail invalid_path
done
[[ -x "$php_bin" && -x "$crontab_bin" ]] || fail executable_missing
deploy_root="$(readlink -f "$deploy_path")"
release="$(readlink -f "$deploy_root/current")"
[[ "$release" == "$deploy_root"/releases/* ]] || fail active_release_scope
[[ -f "$release/REVISION" && -f "$release/backend/artisan" ]] || fail active_release_missing
revision="$(tr -d '\r\n' < "$release/REVISION")"
[[ "$revision" =~ ^[a-f0-9]{40}$ ]] || fail active_revision_invalid
[[ -r "$release/backend/scripts/deploy/verify_sitemap_source_cache_refresh.sh" ]] || fail warmer_missing
# Verify the actual application environment before installing any cron entry.
environment="$(cd "$release/backend" && "$php_bin" artisan env --no-ansi --no-interaction)"
[[ "$environment" == *"[staging]"* ]] || fail staging_environment_required

begin_marker="# BEGIN fap-api staging sitemap warmer"
end_marker="# END fap-api staging sitemap warmer"
backend="$deploy_root/current/backend"
canonical_line="*/5 * * * * SITEMAP_SOURCE_WARM_PHP_BIN=$php_bin SITEMAP_SOURCE_WARM_ARTISAN=$backend/artisan /bin/bash $backend/scripts/deploy/verify_sitemap_source_cache_refresh.sh >> /dev/null 2>&1"
error_file="$(mktemp)"
candidate="$(mktemp)"
trap 'rm -f "$error_file" "$candidate"' EXIT
set +e
current_crontab="$(LC_ALL=C "$crontab_bin" -l 2>"$error_file")"
crontab_rc=$?
set -e
if [[ "$crontab_rc" -eq 1 ]] && grep -Eq '^no crontab for ' "$error_file"; then
  current_crontab=""
elif [[ "$crontab_rc" -ne 0 ]]; then
  fail crontab_read_failed
fi
# Preserve every unrelated entry. Refuse malformed or duplicate owned blocks.
if ! awk -v begin="$begin_marker" -v end="$end_marker" '
  $0 == begin { if (inside || seen_begin++) exit 1; inside=1; next }
  $0 == end { if (!inside || seen_end++) exit 1; inside=0; next }
  !inside { print }
  END { if (inside || seen_begin != seen_end) exit 1 }
' <<< "$current_crontab" > "$candidate"; then
  fail managed_block_invalid
fi
printf '%s\n%s\n%s\n' "$begin_marker" "$canonical_line" "$end_marker" >> "$candidate"
"$crontab_bin" "$candidate" || fail crontab_install_failed
installed="$("$crontab_bin" -l)" || fail crontab_readback_failed
[[ "$installed" == "$(cat "$candidate")" ]] || fail crontab_readback_mismatch
printf 'staging_sitemap_cron_installed revision=%s interval_seconds=300\n' "$revision"
