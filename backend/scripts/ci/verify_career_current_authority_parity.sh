#!/usr/bin/env bash

set -euo pipefail

backend_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
repo_root="$(cd "$backend_root/.." && pwd)"
redis_mode="${CAREER_PARITY_REDIS_MODE:-disposable}"
redis_image="redis@sha256:d0c875bdacfb5c4d2c2d9124de3f53cee1dc9ceff8936bd459fabc135cb33015"
redis_maxmemory=2147483648
container_id=""

cleanup() {
  if [[ -n "$container_id" ]]; then
    docker rm -f "$container_id" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

export CAREER_PARITY_BACKEND_ROOT="$backend_root"
export CAREER_PARITY_RELEASE_SHA="${CAREER_PARITY_RELEASE_SHA:-$(git -C "$repo_root" rev-parse HEAD)}"
export CAREER_PARITY_REDIS_MODE="$redis_mode"

if [[ "$redis_mode" == "disposable" ]]; then
  command -v docker >/dev/null
  docker image inspect "$redis_image" >/dev/null 2>&1 || docker pull "$redis_image" >/dev/null
  container_id="$(docker run --rm -d -p 127.0.0.1::6379 "$redis_image" \
    redis-server --save '' --appendonly no --maxmemory "$redis_maxmemory" --maxmemory-policy noeviction)"
  redis_port="$(docker port "$container_id" 6379/tcp | awk -F: 'NR == 1 {print $NF}')"
  [[ "$redis_port" =~ ^[0-9]+$ ]]
  for _ in {1..30}; do
    if docker exec "$container_id" redis-cli ping 2>/dev/null | grep -qx PONG; then
      break
    fi
    sleep 1
  done
  docker exec "$container_id" redis-server --version | grep -q 'v=6\.'
  export APP_ENV=testing
  export CACHE_STORE=redis
  export REDIS_CLIENT=phpredis
  export REDIS_HOST=127.0.0.1
  export REDIS_PORT="$redis_port"
  export REDIS_PASSWORD=null
  export REDIS_DB=0
  export REDIS_CACHE_DB=1
  export REDIS_PREFIX='fap:'
  export CACHE_PREFIX='fap_cache'
elif [[ "$redis_mode" != "readonly" && "$redis_mode" != "none" ]]; then
  echo '{"status":"fail","safe_error_code":"CAREER_PARITY_REDIS_MODE_INVALID"}'
  exit 1
fi

php -d memory_limit=1024M "$backend_root/scripts/ci/career_current_authority_parity.php"
