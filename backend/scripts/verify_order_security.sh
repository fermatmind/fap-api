#!/usr/bin/env bash
set -euo pipefail

export CI=true
export APP_ENV=testing
export QUEUE_CONNECTION=sync
export XDEBUG_MODE=off

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

php artisan test --filter=CommerceOrderLookupSecurityTest
php artisan test --filter=CommerceOrderReadFallbackTest
php artisan test --filter=HighIdorOwnership404Test
