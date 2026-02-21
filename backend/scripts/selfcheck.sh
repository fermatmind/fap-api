#!/usr/bin/env bash
set -euo pipefail

# 用法：
#   ./scripts/selfcheck.sh
#   ./scripts/selfcheck.sh ../content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3/manifest.json

MANIFEST="${1:-../content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3/manifest.json}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

cd "${BACKEND_DIR}"
php artisan fap:self-check --path="${MANIFEST}"
