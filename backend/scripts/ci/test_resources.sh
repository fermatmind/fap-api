#!/usr/bin/env bash
# Local test resources only. Hosted CI retains its explicit service topology.
fm_test_init() {
  [[ "${GITHUB_ACTIONS:-false}" != true ]] || return 0
  FM_TEST_RESOURCE_CREATED=0
  if [[ -n "${FM_TEST_RESOURCE_ROOT:-}" ]]; then
    [[ -d "$FM_TEST_RESOURCE_ROOT" && ! -L "$FM_TEST_RESOURCE_ROOT" && "$(cat "$FM_TEST_RESOURCE_ROOT/owner" 2>/dev/null)" == "${FM_TEST_RESOURCE_TOKEN:-}" && -n "${FM_TEST_RESOURCE_TOKEN:-}" ]] || {
      echo '[test-resources] invalid resource ownership' >&2; return 73;
    }
    return 0
  fi
  FM_TEST_RESOURCE_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/fm-test.XXXXXXXX")" || return
  FM_TEST_RESOURCE_TOKEN="$(php -r 'echo bin2hex(random_bytes(16));')" || return
  printf '%s' "$FM_TEST_RESOURCE_TOKEN" > "$FM_TEST_RESOURCE_ROOT/owner"
  FM_TEST_RESOURCE_CREATED=1
  export FM_TEST_RESOURCE_ROOT FM_TEST_RESOURCE_TOKEN
  export APP_ENV=testing
  export CACHE_STORE=array CACHE_LIMITER=file QUEUE_CONNECTION=sync SESSION_DRIVER=array
  export DB_URL="" DB_SOCKET=""
  APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" || return
  export APP_KEY
  export LARAVEL_STORAGE_PATH="$FM_TEST_RESOURCE_ROOT/storage"
  export APP_CONFIG_CACHE="$FM_TEST_RESOURCE_ROOT/config.php"
  export APP_ROUTES_CACHE="$FM_TEST_RESOURCE_ROOT/routes.php"
  export APP_SERVICES_CACHE="$FM_TEST_RESOURCE_ROOT/services.php"
  export APP_PACKAGES_CACHE="$FM_TEST_RESOURCE_ROOT/packages.php"
  export XDG_CONFIG_HOME="$FM_TEST_RESOURCE_ROOT/psysh"
  export TMPDIR="$FM_TEST_RESOURCE_ROOT/tmp"
  mkdir -p "$TMPDIR" "$XDG_CONFIG_HOME" "$LARAVEL_STORAGE_PATH"/{app,logs,framework/cache/data,framework/sessions,framework/views,framework/testing}
}

fm_test_free_port() {
  # shellcheck disable=SC2016
  php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m); if(!$s)exit(1);echo substr(strrchr(stream_socket_get_name($s,false),":"),1);'
}

fm_test_require_free_port() {
  [[ "$1" =~ ^[0-9]+$ && "$1" -gt 0 && "$1" -le 65535 ]] || return 64
  if lsof -nP -iTCP:"$1" -sTCP:LISTEN -t >/dev/null 2>&1; then
    echo "[test-resources] port already in use: $1; refusing to stop its owner" >&2
    return 75
  fi
}

fm_test_process_identity() {
  [[ "$1" =~ ^[0-9]+$ ]] || return 1
  ps -p "$1" -o ppid=,lstart= | awk '{$1=$1; print}'
}

fm_test_stop_server() {
  local parent="$1" identity="${2:-}" child child_identity
  [[ -n "$identity" && "$(fm_test_process_identity "$parent")" == "$identity" ]] || return 0
  # Capture each child's identity before recursing, then recheck before signalling.
  for child in $(ps -axo pid=,ppid= | awk -v p="$parent" '$2==p {print $1}'); do
    child_identity="$(fm_test_process_identity "$child")"
    [[ "${child_identity%% *}" == "$parent" ]] || continue
    fm_test_stop_server "$child" "$child_identity"
  done
  [[ "$(fm_test_process_identity "$parent")" == "$identity" ]] || return 0
  kill "$parent" 2>/dev/null || true
}

fm_test_sqlite_path_owned() {
  # Resolve the parent before any reset: lexical prefixes permit ../ and symlink escapes.
  # shellcheck disable=SC2016
  php -r '
    $root=realpath(getenv("FM_TEST_RESOURCE_ROOT"));
    $path=getenv("DB_DATABASE"); $parent=realpath(dirname($path));
    if (!$root || !$parent || is_link($path) ||
        ($parent !== $root && !str_starts_with($parent, $root.DIRECTORY_SEPARATOR))) exit(73);
  '
}

fm_test_cleanup() {
  [[ "${FM_TEST_RESOURCE_CREATED:-0}" == 1 && -d "$FM_TEST_RESOURCE_ROOT" && ! -L "$FM_TEST_RESOURCE_ROOT" ]] || return 0
  [[ "$(cat "$FM_TEST_RESOURCE_ROOT/owner" 2>/dev/null)" == "$FM_TEST_RESOURCE_TOKEN" ]] || return 73
  rm -rf -- "$FM_TEST_RESOURCE_ROOT"
}

fm_test_port_owned() {
  local port="$1" owner="$2" pid parent depth listeners
  listeners="$(lsof -nP -iTCP:"$port" -sTCP:LISTEN -t 2>/dev/null)" || return 1
  for pid in $listeners; do
    for ((depth=0; depth<32; depth++)); do
      [[ "$pid" != "$owner" ]] || break
      parent="$(ps -p "$pid" -o ppid= | tr -d ' ')"
      [[ "$parent" =~ ^[0-9]+$ && "$parent" -gt 1 ]] || return 1
      pid="$parent"
    done
    [[ "$pid" == "$owner" ]] || return 1
  done
}
