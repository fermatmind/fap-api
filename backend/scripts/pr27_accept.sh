#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${ROOT_DIR}/backend"
ART_DIR="${BACKEND_DIR}/artifacts/pr27"

# PR27 固定端口 1827
SERVE_PORT="${SERVE_PORT:-1827}"
API_BASE="http://127.0.0.1:${SERVE_PORT}"

# sqlite 每次运行唯一
DB_PATH="${DB_PATH:-/tmp/pr27_${GITHUB_RUN_ID:-local}_${GITHUB_RUN_ATTEMPT:-0}.sqlite}"
export DB_CONNECTION=sqlite
export DB_DATABASE="${DB_PATH}"
export SERVE_PORT

mkdir -p "${ART_DIR}"

cleanup_port() {
  local port="$1"
  local pids
  pids="$(lsof -ti tcp:"${port}" 2>/dev/null || true)"
  if [[ -n "${pids}" ]]; then
    kill -9 ${pids} >/dev/null 2>&1 || true
  fi
}

wait_for_up() {
  local url="$1"
  local ok=0
  for _ in {1..40}; do
    curl -sS "${url}" >/dev/null 2>&1 && ok=1 && break
    sleep 0.25
  done
  [[ "${ok}" -eq 1 ]]
}

# 清端口：1827/18000
cleanup_port "${SERVE_PORT}"
cleanup_port 18000

# 清 DB
rm -f "${DB_PATH}"
touch "${DB_PATH}"

cd "${BACKEND_DIR}"
composer install --no-interaction --no-progress

php artisan migrate --force
php artisan db:seed --force --class=ScaleRegistrySeeder
php artisan db:seed --force --class=Pr19CommerceSeeder

# verify：负责启动 1827 服务 + 产出 artifacts
cd "${ROOT_DIR}"
DB_CONNECTION=sqlite DB_DATABASE="${DB_PATH}" bash "${BACKEND_DIR}/scripts/pr27_verify.sh"

# verify 结束后确认服务可用：用 /up 作为健康口
if ! wait_for_up "${API_BASE}/up"; then
  echo "[PR27][fail] server not ready: ${API_BASE}/up"
  [[ -f "${ART_DIR}/server.log" ]] && tail -n 120 "${ART_DIR}/server.log" || true
  exit 1
fi

SUMMARY_TXT="${ART_DIR}/summary.txt"
REPORT_JSON="${ART_DIR}/report.json"
QUESTIONS_JSON="${ART_DIR}/questions.json"
ATTEMPT_START_JSON="${ART_DIR}/attempt_start.json"

# 产物存在性校验
[[ -s "${ATTEMPT_START_JSON}" ]] || { echo "[PR27][fail] missing ${ATTEMPT_START_JSON}"; exit 1; }
[[ -s "${QUESTIONS_JSON}" ]]     || { echo "[PR27][fail] missing ${QUESTIONS_JSON}"; exit 1; }
[[ -s "${REPORT_JSON}" ]]        || { echo "[PR27][fail] missing ${REPORT_JSON}"; exit 1; }

ATTEMPT_ID="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["attempt_id"] ?? "";' "${ATTEMPT_START_JSON}")"
[[ -n "${ATTEMPT_ID}" ]] || { echo "[PR27][fail] attempt_id missing"; exit 1; }

QUESTION_COUNT="$(php -r '
$j=json_decode(file_get_contents($argv[1]), true);
$q=$j["questions"] ?? [];
$items=[];
if (is_array($q) && array_key_exists("items", $q) && is_array($q["items"])) { $items=$q["items"]; }
elseif (is_array($q) && array_key_exists("questions", $q) && is_array($q["questions"])) { $items=$q["questions"]; }
elseif (is_array($q) && array_key_exists("data", $q) && is_array($q["data"])) { $items=$q["data"]; }
elseif (is_array($q)) { $items=$q; }
echo count($items);
' "${QUESTIONS_JSON}")"

UPGRADE_SKU="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["upgrade_sku"] ?? "";' "${REPORT_JSON}")"
UPGRADE_SKU_EFFECTIVE="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["upgrade_sku_effective"] ?? "";' "${REPORT_JSON}")"
VIEW_POLICY_SKU="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); $v=$j["view_policy"] ?? []; echo $v["upgrade_sku"] ?? "";' "${REPORT_JSON}")"
OFFERS_SKUS="$(php -r '
$j=json_decode(file_get_contents($argv[1]), true);
$offers=$j["offers"] ?? [];
$out=[];
if (is_array($offers)) {
  foreach ($offers as $offer) {
    if (is_array($offer) && isset($offer["sku"])) { $out[]=$offer["sku"]; }
  }
}
echo implode(",", $out);
' "${REPORT_JSON}")"

{
  echo "PR27 Acceptance Summary"
  echo "api=${API_BASE}"
  echo "checks=up,questions,attempt_start,attempt_submit,report_paywall"
  echo "attempt_id=${ATTEMPT_ID}"
  echo "question_count=${QUESTION_COUNT}"
  echo "upgrade_sku=${UPGRADE_SKU}"
  echo "upgrade_sku_effective=${UPGRADE_SKU_EFFECTIVE}"
  echo "view_policy_upgrade_sku=${VIEW_POLICY_SKU}"
  echo "offers_skus=${OFFERS_SKUS}"
  echo "smoke_url=/api/v0.3/attempts/${ATTEMPT_ID}/report"
} > "${SUMMARY_TXT}"

bash "${BACKEND_DIR}/scripts/sanitize_artifacts.sh" 27

# 停服务：存在才 kill，避免 No such process
if [[ -f "${ART_DIR}/server.pid" ]]; then
  PID="$(cat "${ART_DIR}/server.pid" | tr -d '[:space:]' || true)"
  if [[ -n "${PID}" ]] && ps -p "${PID}" >/dev/null 2>&1; then
    kill "${PID}" >/dev/null 2>&1 || true
    sleep 1
    ps -p "${PID}" >/dev/null 2>&1 && kill -9 "${PID}" >/dev/null 2>&1 || true
  fi
fi

cleanup_port "${SERVE_PORT}"
rm -f "${DB_PATH}"
