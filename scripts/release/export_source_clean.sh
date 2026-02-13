#!/usr/bin/env bash
set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

bash scripts/security/assert_no_tracked_sensitive_files.sh
bash scripts/release/export_source_zip.sh
bash scripts/release/verify_source_zip_clean.sh dist/source_clean.zip

echo "[SEC-001] clean source exported: dist/source_clean.zip"
echo "[SEC-001] compatibility copy: dist/fap-api-source.zip"
