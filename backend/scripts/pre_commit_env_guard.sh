#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
cd "${REPO_DIR}"

if [ -f "backend/.env" ]; then
  echo "[ENV_GUARD][FAIL] backend/.env exists in workspace; remove it before commit." >&2
  exit 1
fi

tracked_env_hits="$(
  git ls-files \
    | grep -E '^backend/\.env(\.[^/]+)?$' \
    | grep -vE '^backend/\.env\.example$' \
    || true
)"
if [ -n "${tracked_env_hits}" ]; then
  echo "[ENV_GUARD][FAIL] tracked backend .env files detected:" >&2
  echo "${tracked_env_hits}" >&2
  exit 1
fi

echo "[ENV_GUARD] PASS"
