#!/usr/bin/env bash

require_tools() {
  local tool

  for tool in "$@"; do
    if ! command -v "$tool" >/dev/null 2>&1; then
      echo "[GUARD][FAIL] missing required tool: ${tool}" >&2
      return 127
    fi
  done
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  set -euo pipefail
  require_tools "$@"
fi
