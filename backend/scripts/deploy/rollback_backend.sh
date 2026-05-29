#!/usr/bin/env bash
set -euo pipefail

ROLLBACK_TARGET="${ROLLBACK_TARGET:-}"
ALLOW_PRODUCTION_ROLLBACK="${ALLOW_PRODUCTION_ROLLBACK:-false}"

if [ -z "$ROLLBACK_TARGET" ]; then
  echo "ROLLBACK_TARGET_MISSING"
  exit 2
fi

if [ "${ALLOW_PRODUCTION_ROLLBACK}" != "true" ]; then
  echo "ROLLBACK_NOT_ALLOWED"
  exit 3
fi

if [ "${ALLOW_REAL_ROLLBACK:-false}" != "true" ]; then
  echo "ROLLBACK_COMMAND_NOT_CONFIGURED"
  echo "Set ALLOW_REAL_ROLLBACK=true and ROLLBACK_COMMAND to execute this wrapper."
  exit 3
fi

ROLLBACK_COMMAND="${ROLLBACK_COMMAND:-}"
if [ -z "$ROLLBACK_COMMAND" ]; then
  echo "ROLLBACK_COMMAND_NOT_CONFIGURED"
  exit 3
fi

echo "Rolling back to ${ROLLBACK_TARGET}"
eval "$ROLLBACK_COMMAND"
