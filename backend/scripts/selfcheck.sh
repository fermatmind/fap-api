#!/usr/bin/env bash
set -euo pipefail

# 用法：
#   ./scripts/selfcheck.sh
#   ./scripts/selfcheck.sh ../content_packages/MBTI/CN_MAINLAND/zh-CN/v0.2.1-TEST/manifest.json

MANIFEST="${1:-../content_packages/MBTI/CN_MAINLAND/zh-CN/v0.2.1-TEST/manifest.json}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

cd "${BACKEND_DIR}"
php artisan fap:self-check --path="${MANIFEST}"
