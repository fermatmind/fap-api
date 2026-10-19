#!/usr/bin/env bash
# Retry only an unambiguous rate-limited download, never package installation.
set -euo pipefail

composer_bin="${1:?Usage: composer_install.sh COMPOSER [install options]}"
shift
export COMPOSER_MAX_PARALLEL_HTTP=3
download_log="$(mktemp "${TMPDIR:-/tmp}/fermatmind-composer-download.XXXXXX")"
trap 'rm -f "$download_log"' EXIT

only_http_429() {
  # Composer emits both the package failure and the final exception. Reject
  # mixed transport failures and other fatal diagnostics rather than guessing.
  sed $'s/\033\\[[0-9;]*[[:alpha:]]//g' "$download_log" | awk '
    /Failed to download/ { seen = 1 }
    {
      line = tolower($0)
      rate = line ~ /http(\/[0-9.]+)?[[:space:]]+429([[:space:])]|$)/
      if (line ~ /http(\/[0-9.]+)?[[:space:]]+[0-9][0-9][0-9]/ && !rate) bad = 1
      # Package names such as symfony/error-handler are normal progress, not
      # diagnostics. Their failure (if any) is reported separately by Composer.
      if (line ~ /^[[:space:]]*-[[:space:]]+(downloading|installing|upgrading|downgrading|removing|syncing)[[:space:]]/) next
      if (line ~ /failed to|error|exception|could not|cannot|invalid|mismatch|denied|certificate|checksum|signature/ && !rate) bad = 1
    }
    END { exit !(seen && !bad) }
  '
}

for attempt in 1 2 3; do
  if "$composer_bin" install "$@" --download-only --no-scripts >"$download_log" 2>&1; then
    cat "$download_log"
    break
  else
    status=$?
    cat "$download_log" >&2
    if [ "$status" -ne 100 ] || ! only_http_429 || [ "$attempt" -eq 3 ]; then
      exit "$status"
    fi
    delay=20
    if [ "$attempt" -eq 2 ]; then delay=60; fi
    printf 'Composer download was rate limited; retry %s/3 in %ss.\n' "$((attempt + 1))" "$delay" >&2
    sleep "$delay"
  fi
done

# Keep all caller options, plugin/script policy and the installation exit code.
"$composer_bin" install "$@"
