#!/usr/bin/env bash
set -euo pipefail

ENV_FILE="${ENV_FILE:-$(pwd)/.env}"

get_env() {
  local key="$1"
  if [[ -n "${!key:-}" ]]; then
    echo "${!key}"
    return
  fi
  if [[ -f "$ENV_FILE" ]]; then
    local line
    line=$(grep -E "^${key}=" "$ENV_FILE" | tail -n 1 || true)
    line=${line#${key}=}
    line=${line%\"}
    line=${line#\"}
    line=${line%\'}
    line=${line#\'}
    echo "$line"
    return
  fi
  echo ""
}

DB_HOST=$(get_env DB_HOST)
DB_PORT=$(get_env DB_PORT)
DB_DATABASE=$(get_env DB_DATABASE)
DB_USERNAME=$(get_env DB_USERNAME)
DB_PASSWORD=$(get_env DB_PASSWORD)

DB_HOST=${DB_HOST:-127.0.0.1}
DB_PORT=${DB_PORT:-3306}

if [[ -z "${DB_DATABASE}" || -z "${DB_USERNAME}" ]]; then
  echo "FAIL: Missing DB_DATABASE or DB_USERNAME"
  exit 1
fi

VIEWS=(
  v_funnel_daily
  v_question_dropoff
  v_question_duration_heatmap
  v_share_conversion
  v_content_pack_distribution
  v_deploy_events
  v_healthz_deps_daily
)

PHP_CMD=$(cat <<'PHP'
$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$db   = getenv('DB_DATABASE');
$user = getenv('DB_USERNAME');
$pass = getenv('DB_PASSWORD');
$views = explode(',', getenv('PR9_VIEWS'));
try {
    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $stmt = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
    foreach ($views as $view) {
        $view = trim($view);
        if ($view === '') continue;
        $stmt->execute([$db, $view]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo "FAIL: view missing {$view}\n";
            exit(1);
        }
        $pdo->query("SELECT 1 FROM {$view} LIMIT 1");
        echo "OK: {$view}\n";
    }
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    exit(1);
}
PHP
)

export DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD
export PR9_VIEWS="$(IFS=,; echo "${VIEWS[*]}")"

php -r "$PHP_CMD"

exit 0
