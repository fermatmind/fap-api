#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
REPO_ROOT="$(cd "$BACKEND_DIR/.." && pwd)"

DEPLOY_DRY_RUN="${DEPLOY_DRY_RUN:-false}"
ALLOW_PRODUCTION_DEPLOY="${ALLOW_PRODUCTION_DEPLOY:-false}"
ALLOW_REAL_DEPLOY="${ALLOW_REAL_DEPLOY:-false}"
DEPLOY_ENV="${DEPLOY_ENV:-${DEPLOY_TARGET:-production}}"
BACKEND_DEPLOY_SHA="${BACKEND_DEPLOY_SHA:-${BACKEND_SHA:-}}"
RELEASE_NAME="${RELEASE_NAME:-}"
DEPLOYER_FILE="${DEPLOYER_FILE:-$REPO_ROOT/deploy.php}"
DEPLOYER_BIN="${DEPLOYER_BIN:-}"

status_line() {
  printf '%s=%s\n' "$1" "$2"
}

fail() {
  local code="$1"
  local reason="$2"
  status_line "deploy_result" "failure"
  echo "$reason"
  exit "$code"
}

shell_join() {
  local joined=""
  local arg
  for arg in "$@"; do
    printf -v arg '%q' "$arg"
    joined="${joined}${joined:+ }${arg}"
  done
  printf '%s\n' "$joined"
}

resolve_deployer_bin() {
  if [ -n "$DEPLOYER_BIN" ]; then
    printf '%s\n' "$DEPLOYER_BIN"
    return
  fi

  if [ -x "$BACKEND_DIR/vendor/bin/dep" ]; then
    printf '%s\n' "$BACKEND_DIR/vendor/bin/dep"
    return
  fi

  if [ -x "$REPO_ROOT/vendor/bin/dep" ]; then
    printf '%s\n' "$REPO_ROOT/vendor/bin/dep"
    return
  fi

  printf '%s\n' "$BACKEND_DIR/vendor/bin/dep"
}

if [ -z "$RELEASE_NAME" ]; then
  fail 2 "MISSING_RELEASE_NAME"
fi

if [ -z "$BACKEND_DEPLOY_SHA" ]; then
  fail 2 "MISSING_BACKEND_DEPLOY_SHA"
fi

if [ "$DEPLOY_ENV" != "production" ] && [ "$DEPLOY_ENV" != "staging" ]; then
  fail 2 "INVALID_DEPLOY_ENV"
fi

if [ ! -f "$DEPLOYER_FILE" ]; then
  fail 2 "DEPLOYER_FILE_NOT_FOUND"
fi

CURRENT_SHA="$(git -C "$REPO_ROOT" rev-parse HEAD)"
if [ "$CURRENT_SHA" != "$BACKEND_DEPLOY_SHA" ]; then
  status_line "current_sha" "$CURRENT_SHA"
  fail 2 "BACKEND_DEPLOY_SHA_MISMATCH"
fi

DEPLOYER_BIN_RESOLVED="$(resolve_deployer_bin)"
DEPLOY_CMD=(
  "$DEPLOYER_BIN_RESOLVED"
  deploy
  "$DEPLOY_ENV"
  -f
  "$DEPLOYER_FILE"
  -o
  "release_name=$RELEASE_NAME"
  --no-interaction
)

status_line "deploy_adapter_mode" "$([ "$DEPLOY_DRY_RUN" = "true" ] && echo "dry-run" || echo "real")"
status_line "deploy_env" "$DEPLOY_ENV"
status_line "backend_deploy_sha" "$BACKEND_DEPLOY_SHA"
status_line "release_name" "$RELEASE_NAME"
status_line "deploy_command" "$(shell_join "${DEPLOY_CMD[@]}")"

if [ "$DEPLOY_DRY_RUN" = "true" ]; then
  status_line "deploy_command_ready" "true"
  status_line "deploy_result" "skipped"
  exit 0
fi

if [ "$DEPLOY_ENV" = "production" ] && [ "$ALLOW_PRODUCTION_DEPLOY" != "true" ]; then
  status_line "deploy_command_ready" "false"
  fail 3 "PRODUCTION_DEPLOY_NOT_ALLOWED"
fi

if [ "$ALLOW_REAL_DEPLOY" != "true" ]; then
  status_line "deploy_command_ready" "false"
  fail 3 "REAL_DEPLOY_NOT_ALLOWED"
fi

if [ ! -x "$DEPLOYER_BIN_RESOLVED" ]; then
  status_line "deploy_command_ready" "false"
  fail 3 "DEPLOYER_BIN_NOT_EXECUTABLE"
fi

status_line "deploy_command_ready" "true"
DEPLOYER_NO_INTERACTION="${DEPLOYER_NO_INTERACTION:-1}" "${DEPLOY_CMD[@]}"
status_line "deploy_result" "success"
