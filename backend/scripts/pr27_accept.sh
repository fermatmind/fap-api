#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${REPO_DIR}/backend"
ART_DIR="${BACKEND_DIR}/artifacts/pr27"
SERVE_PORT="${SERVE_PORT:-1827}"
DB_PATH="/tmp/pr27.sqlite"

mkdir -p "${ART_DIR}"

# Port cleanup
for p in "${SERVE_PORT}" 18000; do
  lsof -ti tcp:${p} | xargs -r kill -9 || true
done

# sqlite fresh
export DB_CONNECTION=sqlite
export DB_DATABASE="${DB_PATH}"
rm -f "${DB_PATH}"

cd "${BACKEND_DIR}"
composer install --no-interaction --no-progress

php artisan migrate --force --no-interaction
php artisan db:seed --force --class=ScaleRegistrySeeder
php artisan db:seed --force --class=Pr19CommerceSeeder

cd "${REPO_DIR}"
SERVE_PORT="${SERVE_PORT}" bash "${BACKEND_DIR}/scripts/pr27_verify.sh"

SUMMARY_TXT="${ART_DIR}/summary.txt"
DEFAULT_PACK_ID="$(cat "${ART_DIR}/default_pack_id.txt")"
SCALES_PACK_ID="$(cat "${ART_DIR}/scales_registry_default_pack_id.txt")"
PACK_DIR_REL="$(cat "${ART_DIR}/pack_dir.txt")"
ATTEMPT_ID="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); echo $j["attempt_id"] ?? "";' "${ART_DIR}/attempt_start.json")"
QUESTION_COUNT="$(php -r '$j=json_decode(file_get_contents($argv[1]), true); $q=$j["questions"]["items"] ?? $j["questions"] ?? $j["data"] ?? $j; echo is_array($q) ? count($q) : 0;' "${ART_DIR}/questions.json")"

cat > "${SUMMARY_TXT}" <<TXT
PR27 Acceptance Summary
- selfcheck: backend/scripts/ci_selfcheck_packs.sh
- verify: backend/scripts/pr27_verify.sh
- serve_port: ${SERVE_PORT}
- default_pack_id: ${DEFAULT_PACK_ID}
- scales_registry_default_pack_id: ${SCALES_PACK_ID}
- pack_dir: ${PACK_DIR_REL}
- attempt_id: ${ATTEMPT_ID}
- question_count: ${QUESTION_COUNT}
Artifacts:
- backend/artifacts/pr27/health.json
- backend/artifacts/pr27/default_pack_id.txt
- backend/artifacts/pr27/scales_registry_default_pack_id.txt
- backend/artifacts/pr27/pack_dir.txt
- backend/artifacts/pr27/questions.json
- backend/artifacts/pr27/answers.json
- backend/artifacts/pr27/attempt_start.json
- backend/artifacts/pr27/submit.json
- backend/artifacts/pr27/report.json
TXT

# Sanitize artifacts (paths/tokens)
for f in "${ART_DIR}"/*; do
  case "${f}" in
    *.txt|*.log|*.json)
      sed -i '' -E \
        -e 's#/Users/[^ "\n]+#<REDACTED_PATH>#g' \
        -e 's#/home/[^ "\n]+#<REDACTED_PATH>#g' \
        -e 's#Authorization: Bearer [A-Za-z0-9._-]+#Authorization: Bearer <REDACTED>#g' \
        -e 's#FAP_ADMIN_TOKEN=[^ "\n]+#FAP_ADMIN_TOKEN=<REDACTED>#g' \
        -e 's#DB_PASSWORD=[^ "\n]+#DB_PASSWORD=<REDACTED>#g' \
        -e 's#password=[^ "\n]+#password=<REDACTED>#g' \
        "${f}" || true
      ;;
  esac
done

rm -f "${DB_PATH}"
