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
DB_PATH="/tmp/pr27.sqlite"

mkdir -p "${ART_DIR}"

for p in 1827 18000; do
  lsof -nP -iTCP:${p} -sTCP:LISTEN || true
  lsof -ti tcp:${p} | xargs -r kill -9 || true
  lsof -nP -iTCP:${p} -sTCP:LISTEN || true
done

cd "${BACKEND_DIR}"
composer install --no-interaction --no-progress

DB_CONNECTION=sqlite DB_DATABASE="${DB_PATH}" php artisan migrate --force
DB_CONNECTION=sqlite DB_DATABASE="${DB_PATH}" php artisan db:seed --force --class=ScaleRegistrySeeder
DB_CONNECTION=sqlite DB_DATABASE="${DB_PATH}" php artisan db:seed --force --class=Pr19CommerceSeeder

cd "${ROOT_DIR}"
DB_CONNECTION=sqlite DB_DATABASE="${DB_PATH}" bash "${BACKEND_DIR}/scripts/pr27_verify.sh"

SUMMARY_TXT="${ART_DIR}/summary.txt"
REPORT_JSON="${ART_DIR}/report.json"
QUESTIONS_JSON="${ART_DIR}/questions.json"
ATTEMPT_START_JSON="${ART_DIR}/attempt_start.json"

ATTEMPT_ID=$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["attempt_id"] ?? "";' "${ATTEMPT_START_JSON}")
QUESTION_COUNT=$(php -r '
$j=json_decode(file_get_contents($argv[1]), true);
$q=$j["questions"] ?? [];
$items=[];
if (is_array($q) && array_key_exists("items", $q) && is_array($q["items"])) { $items=$q["items"]; }
elseif (is_array($q) && array_key_exists("questions", $q) && is_array($q["questions"])) { $items=$q["questions"]; }
elseif (is_array($q) && array_key_exists("data", $q) && is_array($q["data"])) { $items=$q["data"]; }
elseif (is_array($q)) { $items=$q; }
echo count($items);
' "${QUESTIONS_JSON}")

UPGRADE_SKU=$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["upgrade_sku"] ?? "";' "${REPORT_JSON}")
UPGRADE_SKU_EFFECTIVE=$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["upgrade_sku_effective"] ?? "";' "${REPORT_JSON}")
VIEW_POLICY_SKU=$(php -r '$j=json_decode(file_get_contents($argv[1]), true); $v=$j["view_policy"] ?? []; echo $v["upgrade_sku"] ?? "";' "${REPORT_JSON}")
OFFERS_SKUS=$(php -r '
$j=json_decode(file_get_contents($argv[1]), true);
$offers=$j["offers"] ?? [];
$out=[];
if (is_array($offers)) {
  foreach ($offers as $offer) {
    if (is_array($offer) && isset($offer["sku"])) { $out[]=$offer["sku"]; }
  }
}
echo implode(",", $out);
' "${REPORT_JSON}")

{
  echo "PR27 Acceptance Summary";
  echo "api=http://127.0.0.1:1827";
  echo "checks=health,questions,attempt_start,attempt_submit,report_paywall";
  echo "attempt_id=${ATTEMPT_ID}";
  echo "question_count=${QUESTION_COUNT}";
  echo "upgrade_sku=${UPGRADE_SKU}";
  echo "upgrade_sku_effective=${UPGRADE_SKU_EFFECTIVE}";
  echo "view_policy_upgrade_sku=${VIEW_POLICY_SKU}";
  echo "offers_skus=${OFFERS_SKUS}";
  echo "smoke_url=/api/v0.3/attempts/${ATTEMPT_ID}/report";
} >"${SUMMARY_TXT}"

bash "${BACKEND_DIR}/scripts/sanitize_artifacts.sh" 27

if [[ -f "${ART_DIR}/server.pid" ]]; then
  PID=$(cat "${ART_DIR}/server.pid")
  kill "${PID}" || true
  sleep 1
  if kill -0 "${PID}" >/dev/null 2>&1; then
    kill -9 "${PID}" || true
  fi
fi

rm -f "${DB_PATH}"
