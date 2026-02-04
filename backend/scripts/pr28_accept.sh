#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1
export PAGER=cat
export GIT_PAGER=cat
export TERM=dumb
export XDEBUG_MODE=off

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${REPO_DIR}/backend"
ART_DIR="${BACKEND_DIR}/artifacts/pr28"
SERVE_PORT="${SERVE_PORT:-18028}"
DB_PATH="/tmp/pr28.sqlite"

mkdir -p "${ART_DIR}"

# Port cleanup
for p in "${SERVE_PORT}" 18000; do
  lsof -ti tcp:${p} | xargs -r kill -9 || true
done

# sqlite fresh
export DB_CONNECTION=sqlite
export DB_DATABASE="${DB_PATH}"
rm -f "${DB_PATH}"
touch "${DB_PATH}"

cd "${BACKEND_DIR}"
composer install --no-interaction --no-progress

php artisan migrate:fresh --force --no-interaction
php artisan db:seed --force --class=ScaleRegistrySeeder

cd "${REPO_DIR}"
SERVE_PORT="${SERVE_PORT}" bash "${BACKEND_DIR}/scripts/pr28_verify.sh"

SUMMARY_TXT="${ART_DIR}/summary.txt"
DEFAULT_PACK_ID="$(cat "${ART_DIR}/default_pack_id.txt")"
DEFAULT_DIR_VERSION="$(cat "${ART_DIR}/default_dir_version.txt")"
SCALES_PACK_ID="$(cat "${ART_DIR}/scales_registry_default_pack_id.txt")"
SCALES_DIR_VERSION="$(cat "${ART_DIR}/scales_registry_default_dir_version.txt")"
PACK_DIR_REL="$(cat "${ART_DIR}/pack_dir.txt")"
ATTEMPT_ID="$(cat "${ART_DIR}/attempt_id.txt")"
QUESTION_COUNT="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); $q=$j["questions"]["items"] ?? $j["questions"] ?? $j["data"] ?? $j; echo is_array($q) ? count($q) : 0;' "${ART_DIR}/questions.json")"
SCALE_CODE="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["scale_code"] ?? "";' "${ART_DIR}/questions.json")"

cat > "${SUMMARY_TXT}" <<TXT
PR28 Acceptance Summary
- verify: backend/scripts/pr28_verify.sh
- serve_port: ${SERVE_PORT}
- smoke_url: http://127.0.0.1:${SERVE_PORT}/api/healthz
- default_pack_id: ${DEFAULT_PACK_ID}
- default_dir_version: ${DEFAULT_DIR_VERSION}
- scales_registry_default_pack_id: ${SCALES_PACK_ID}
- scales_registry_default_dir_version: ${SCALES_DIR_VERSION}
- pack_dir: ${PACK_DIR_REL}
- scale_code: ${SCALE_CODE}
- attempt_id: ${ATTEMPT_ID}
- question_count: ${QUESTION_COUNT}
- schema_changes: none
Artifacts:
- backend/artifacts/pr28/health.json
- backend/artifacts/pr28/default_pack_id.txt
- backend/artifacts/pr28/default_dir_version.txt
- backend/artifacts/pr28/scales_registry_default_pack_id.txt
- backend/artifacts/pr28/scales_registry_default_dir_version.txt
- backend/artifacts/pr28/pack_dir.txt
- backend/artifacts/pr28/scales.json
- backend/artifacts/pr28/questions.json
- backend/artifacts/pr28/answers.json
- backend/artifacts/pr28/attempt_start.json
- backend/artifacts/pr28/submit.json
- backend/artifacts/pr28/report.json
TXT

bash "${BACKEND_DIR}/scripts/sanitize_artifacts.sh" 28

rm -f "${DB_PATH}"

# Shellcheck: verify scripts are syntactically valid
bash -n "${BACKEND_DIR}/scripts/pr28_verify.sh"
bash -n "${BACKEND_DIR}/scripts/pr28_accept.sh"
