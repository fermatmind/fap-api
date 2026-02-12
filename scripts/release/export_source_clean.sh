#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

echo "[deprecated] scripts/release/export_source_clean.sh delegates to SEC-001 flow"
bash scripts/security/assert_no_tracked_sensitive_files.sh
bash scripts/release/export_source_zip.sh
bash scripts/release/verify_source_zip_clean.sh dist/fap-api-source.zip
echo "[SEC-001] clean source exported: dist/fap-api-source.zip"
