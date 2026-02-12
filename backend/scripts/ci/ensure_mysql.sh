#!/usr/bin/env bash
set -euo pipefail

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-33306}"
DB_DATABASE="${DB_DATABASE:-fap_ci}"
DB_USERNAME="${DB_USERNAME:-root}"
DB_PASSWORD="${DB_PASSWORD:-root}"

MYSQL_CONTAINER_NAME="${MYSQL_CONTAINER_NAME:-fap-selfcheck-mysql}"
MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.0}"

can_connect() {
  php -r '
    $host = getenv("DB_HOST") ?: "127.0.0.1";
    $port = (int) (getenv("DB_PORT") ?: 33306);
    $db = getenv("DB_DATABASE") ?: "fap_ci";
    $user = getenv("DB_USERNAME") ?: "root";
    $pass = getenv("DB_PASSWORD");
    if ($pass === false) { $pass = ""; }
    try {
      new PDO(
        "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_TIMEOUT => 2]
      );
      exit(0);
    } catch (Throwable $e) {
      exit(1);
    }
  ' >/dev/null 2>&1
}

if [[ "$DB_HOST" != "127.0.0.1" && "$DB_HOST" != "localhost" ]]; then
  echo "[ensure_mysql] skip bootstrap for remote host: ${DB_HOST}"
  exit 0
fi

if ! command -v docker >/dev/null 2>&1; then
  echo "[ensure_mysql] docker not found and mysql not configured."
  echo "[ensure_mysql] install docker or set DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD to a reachable mysql."
  exit 1
fi

if can_connect; then
  echo "[ensure_mysql] mysql already reachable at ${DB_HOST}:${DB_PORT} (db=${DB_DATABASE})"
  exit 0
fi

echo "[ensure_mysql] starting mysql container ${MYSQL_CONTAINER_NAME} (${MYSQL_IMAGE}) on port ${DB_PORT}"
docker rm -f "${MYSQL_CONTAINER_NAME}" >/dev/null 2>&1 || true

if [[ -n "${DB_PASSWORD}" ]]; then
  docker run -d \
    --name "${MYSQL_CONTAINER_NAME}" \
    -e MYSQL_ROOT_PASSWORD="${DB_PASSWORD}" \
    -e MYSQL_DATABASE="${DB_DATABASE}" \
    -p "${DB_PORT}:3306" \
    "${MYSQL_IMAGE}" >/dev/null
else
  docker run -d \
    --name "${MYSQL_CONTAINER_NAME}" \
    -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
    -e MYSQL_DATABASE="${DB_DATABASE}" \
    -p "${DB_PORT}:3306" \
    "${MYSQL_IMAGE}" >/dev/null
fi

for i in $(seq 1 60); do
  if can_connect; then
    echo "[ensure_mysql] mysql ready at ${DB_HOST}:${DB_PORT} (attempt=${i})"
    exit 0
  fi
  sleep 1
done

echo "[ensure_mysql] mysql did not become ready in time"
docker logs --tail 50 "${MYSQL_CONTAINER_NAME}" || true
exit 1
