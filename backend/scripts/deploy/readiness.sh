#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-https://fermatmind.com}"
BACKEND_SHA="${BACKEND_SHA:-}"
RELEASE_NAME="${RELEASE_NAME:-}"
TIMEOUT="${TIMEOUT:-10}"

printf '[readiness] start\n'

if [ -z "$BACKEND_SHA" ]; then
  echo "readiness failed: BACKEND_SHA is required"
  exit 2
fi

if [ -z "$RELEASE_NAME" ]; then
  echo "readiness failed: RELEASE_NAME is required"
  exit 2
fi

if ! command -v curl >/dev/null 2>&1; then
  echo "readiness failed: curl is required"
  exit 2
fi

if ! command -v bash >/dev/null 2>&1; then
  echo "readiness failed: bash is required"
  exit 2
fi

echo "backend sha: ${BACKEND_SHA}"
echo "release name: ${RELEASE_NAME}"
echo "base url: ${BASE_URL}"

if ! curl -fsS --max-time "$TIMEOUT" "${BASE_URL}/api/healthz" >/dev/null 2>&1; then
  echo "readiness failed: healthz check failed"
  exit 1
fi

echo "[readiness] ok"
