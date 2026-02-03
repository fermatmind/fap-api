#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# Active packs only (deprecated packs are excluded from strict-assets gate)
PKGS=(
  "default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.2"
  "default/CN_MAINLAND/zh-CN/IQ-RAVEN-CN-v0.3.0-DEMO"
  "default/CN_MAINLAND/zh-CN/SIMPLE-SCORE-CN-v0.3.0-DEMO"
  "default/CN_MAINLAND/zh-CN/DEMO-ANSWERS-CN-v0.3.0-DEMO"
)

cd "${ROOT_DIR}"
for pkg in "${PKGS[@]}"; do
  echo "== SELF-CHECK pkg=${pkg}"
  php backend/artisan fap:self-check --strict-assets --pkg="${pkg}"
done
