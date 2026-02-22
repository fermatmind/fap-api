#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

cd "$BACKEND_DIR"

php artisan content:lint --pack=CLINICAL_COMBO_68 --pack-version=v1
php artisan content:compile --pack=CLINICAL_COMBO_68 --pack-version=v1
if command -v rg >/dev/null 2>&1; then
  php artisan test --testsuite=Feature --list-tests | rg -q "ClinicalCombo" || { echo "[FAIL] ClinicalCombo tests not discovered"; exit 31; }
else
  php artisan test --testsuite=Feature --list-tests | grep -q "ClinicalCombo" || { echo "[FAIL] ClinicalCombo tests not discovered"; exit 31; }
fi
php artisan test --testsuite=Feature --filter ClinicalCombo
