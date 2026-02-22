#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

cd "$BACKEND_DIR"

php artisan content:lint --pack=CLINICAL_COMBO_68 --pack-version=v1
php artisan content:compile --pack=CLINICAL_COMBO_68 --pack-version=v1
php artisan test --filter ClinicalCombo
