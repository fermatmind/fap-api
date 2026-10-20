#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=backend/scripts/ci/test_resources.sh
source "$SCRIPT_DIR/test_resources.sh"
created_id=""
create_attempted=0
keep_container=0
command_pid=""
command_identity=""

can_connect() {
  # shellcheck disable=SC2016
  php -r '
    try {
      new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_DATABASE").";charset=utf8mb4",
        getenv("DB_USERNAME"),getenv("DB_PASSWORD"),[PDO::ATTR_TIMEOUT=>2]); exit(0);
    } catch (Throwable $e) { exit(1); }
  ' >/dev/null 2>&1
}

cleanup_created() {
  if [[ -z "$created_id" && "$create_attempted" == 1 ]]; then
    created_id="$(docker inspect --format '{{.Id}}' "$MYSQL_CONTAINER_NAME" 2>/dev/null || true)"
  fi
  [[ -n "$created_id" ]] || return 0
  local owner
  owner="$(docker inspect --format '{{index .Config.Labels "fermatmind.test-owner"}}' "$created_id" 2>/dev/null || true)"
  if [[ "$owner" == "${FM_TEST_RESOURCE_TOKEN:-github-ci}" ]]; then
    docker rm -f "$created_id" >/dev/null
    created_id=""
    create_attempted=0
  else
    echo '[ensure_mysql] refusing cleanup: container ownership changed' >&2
    return 73
  fi
}

finish() {
  local rc=$?
  trap - EXIT
  if [[ -n "$command_pid" ]]; then
    fm_test_stop_server "$command_pid" "$command_identity"
    wait "$command_pid" 2>/dev/null || true
  fi
  if [[ "$keep_container" == 0 ]]; then
    cleanup_created || rc=$?
    fm_test_cleanup || rc=$?
  fi
  exit "$rc"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
fm_test_init
local_mode=0
[[ "${GITHUB_ACTIONS:-false}" == true ]] || local_mode=1
export DB_HOST="${DB_HOST:-127.0.0.1}"
export DB_PORT="${DB_PORT:-$(if [[ "$local_mode" == 1 ]]; then echo 0; else echo 33306; fi)}"
export DB_DATABASE="${DB_DATABASE:-fap_ci${FM_TEST_RESOURCE_TOKEN:+_${FM_TEST_RESOURCE_TOKEN:0:16}}}"
export DB_USERNAME="${DB_USERNAME:-root}"
export DB_PASSWORD="${DB_PASSWORD-root}"
export MYSQL_CONTAINER_NAME="${MYSQL_CONTAINER_NAME:-fap-selfcheck-mysql${FM_TEST_RESOURCE_TOKEN:+-${FM_TEST_RESOURCE_TOKEN:0:16}}}"
MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.0}"

if [[ "$DB_HOST" != 127.0.0.1 && "$DB_HOST" != localhost ]]; then
  if [[ "$local_mode" == 1 ]]; then
    echo '[ensure_mysql] local tests require a task-owned loopback MySQL' >&2; exit 73
  fi
  echo '[ensure_mysql] skip bootstrap for configured remote CI service'
  exit 0
fi
[[ "$DB_DATABASE" =~ ^[a-zA-Z0-9_]+$ && "$MYSQL_CONTAINER_NAME" =~ ^[a-zA-Z0-9][a-zA-Z0-9_.-]+$ && "$DB_PORT" =~ ^[0-9]+$ && "$DB_PORT" -le 65535 ]] || exit 64
if [[ "$local_mode" == 0 ]] && can_connect; then
  echo '[ensure_mysql] configured CI MySQL is reachable'; exit 0
fi
command -v docker >/dev/null || { echo '[ensure_mysql] docker is required' >&2; exit 1; }
docker info >/dev/null 2>&1 || { echo '[ensure_mysql] Docker daemon is unavailable' >&2; exit 1; }
if docker inspect "$MYSQL_CONTAINER_NAME" >/dev/null 2>&1; then
  owner="$(docker inspect --format '{{index .Config.Labels "fermatmind.test-owner"}}' "$MYSQL_CONTAINER_NAME")"
  [[ "$owner" == "${FM_TEST_RESOURCE_TOKEN:-github-ci}" ]] || {
    echo '[ensure_mysql] container name belongs to another context; refusing reuse or removal' >&2; exit 73;
  }
else
  password_env=( -e "MYSQL_ROOT_PASSWORD=$DB_PASSWORD" )
  [[ -n "$DB_PASSWORD" ]] || password_env=( -e MYSQL_ALLOW_EMPTY_PASSWORD=yes )
  published="$DB_PORT"
  [[ "$DB_PORT" != 0 ]] || published=""
  create_attempted=1
  created_id="$(docker run -d --name "$MYSQL_CONTAINER_NAME" --label "fermatmind.test-owner=${FM_TEST_RESOURCE_TOKEN:-github-ci}" \
    "${password_env[@]}" -e "MYSQL_DATABASE=$DB_DATABASE" -p "127.0.0.1:${published}:3306" "$MYSQL_IMAGE")"
fi
port_binding="$(docker port "$MYSQL_CONTAINER_NAME" 3306/tcp)"
[[ "$port_binding" =~ ^127\.0\.0\.1:([0-9]+)$ ]] || { echo '[ensure_mysql] invalid loopback binding' >&2; exit 73; }
export DB_PORT="${BASH_REMATCH[1]}"
ready=0
for i in $(seq 1 60); do
  if can_connect; then ready=1; echo "[ensure_mysql] ready attempt=$i"; break; fi
  sleep 1
done
[[ "$ready" == 1 ]] || { echo '[ensure_mysql] MySQL readiness failed' >&2; cleanup_created; exit 1; }
if [[ "$local_mode" == 1 ]]; then
  env_file="$FM_TEST_RESOURCE_ROOT/mysql.env"
  (umask 077; for key in DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD MYSQL_CONTAINER_NAME; do printf 'export %s=%q\n' "$key" "${!key}"; done > "$env_file")
  echo "[ensure_mysql] task connection environment: $env_file"
fi
echo "[ensure_mysql] ready on loopback port $DB_PORT"
if [[ $# -gt 0 ]]; then
  [[ "$1" == -- && $# -gt 1 ]] || exit 64
  shift
  "$@" &
  command_pid=$!
  command_identity="$(fm_test_process_identity "$command_pid" || true)"
  rc=0
  wait "$command_pid" || rc=$?
  command_pid=""
  exit "$rc"
else
  keep_container=1
fi
