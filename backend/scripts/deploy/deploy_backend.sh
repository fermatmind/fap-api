#!/usr/bin/env bash
set -euo pipefail

BACKEND_SHA="${BACKEND_SHA:-}"
RELEASE_NAME="${RELEASE_NAME:-}"
DEPLOY_TARGET="${DEPLOY_TARGET:-production}"
ALLOW_PRODUCTION_DEPLOY="${ALLOW_PRODUCTION_DEPLOY:-false}"

if [ -z "$BACKEND_SHA" ]; then
  echo "DEPLOY_BACKEND_CONFIG_MISSING"
  exit 2
fi

if [ -z "$RELEASE_NAME" ]; then
  echo "DEPLOY_BACKEND_CONFIG_MISSING"
  exit 2
fi

if [ "$DEPLOY_TARGET" != "production" ] && [ "$DEPLOY_TARGET" != "staging" ]; then
  echo "DEPLOY_TARGET_INVALID"
  exit 2
fi

if [ "${ALLOW_PRODUCTION_DEPLOY}" != "true" ]; then
  echo "DEPLOY_COMMAND_NOT_CONFIGURED"
  exit 3
fi

if [ "${ALLOW_REAL_DEPLOY:-false}" != "true" ]; then
  echo "DEPLOY_COMMAND_NOT_CONFIGURED"
  echo "Set ALLOW_REAL_DEPLOY=true and DEPLOY_COMMAND to execute this wrapper."
  exit 3
fi

DEPLOY_COMMAND="${DEPLOY_COMMAND:-}"
if [ -z "$DEPLOY_COMMAND" ]; then
  echo "DEPLOY_COMMAND_NOT_CONFIGURED"
  exit 3
fi

echo "Running deployment for release ${RELEASE_NAME} on ${DEPLOY_TARGET}"
echo "Note: wrapper keeps cache/route/config contracts as part of backend deployment."
echo "Required cache refresh items: optimize:clear, config:cache, route:cache, view:cache"

export BACKEND_SHA
export RELEASE_NAME
export DEPLOY_TARGET
eval "$DEPLOY_COMMAND"
