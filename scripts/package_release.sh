#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

echo "[compat] scripts/package_release.sh delegates to scripts/release_pack.sh"
bash scripts/release_pack.sh
echo "[package_release] dist/fap-api-release.zip"
