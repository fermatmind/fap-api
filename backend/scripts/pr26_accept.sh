#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${REPO_DIR}/backend"
ART_DIR="${BACKEND_DIR}/artifacts/pr26"
SERVE_PORT="${SERVE_PORT:-1826}"

mkdir -p "${ART_DIR}"

# Port cleanup (1826 + 18000)
for p in "${SERVE_PORT}" 18000; do
  lsof -nP -iTCP:${p} -sTCP:LISTEN || true
  lsof -ti tcp:${p} | xargs -r kill -9 || true
  lsof -nP -iTCP:${p} -sTCP:LISTEN || true
done

# SQLite temp DB
export APP_ENV=testing
export DB_CONNECTION=sqlite
export DB_DATABASE="/tmp/pr26.sqlite"
export CACHE_DRIVER=array
export QUEUE_CONNECTION=sync

rm -f "${DB_DATABASE}"
touch "${DB_DATABASE}"

# Install deps + migrate
cd "${BACKEND_DIR}"
composer install --no-interaction --no-progress >> "${ART_DIR}/accept.log" 2>&1
php artisan migrate:fresh --force >> "${ART_DIR}/accept.log" 2>&1

# Run PR26 core verification
cd "${REPO_DIR}"
bash backend/scripts/pr26_verify.sh >> "${ART_DIR}/accept.log" 2>&1

# Summary (avoid absolute paths/sensitive info)
cat > "${ART_DIR}/summary.txt" <<'TXT'
PR26 ACCEPT SUMMARY

- guard_release_integrity: PASS
- deploy.php hooks: PASS
- selfcheck workflow guard step: PASS

Verify:
- bash backend/scripts/pr26_accept.sh
- bash backend/scripts/ci_verify_mbti.sh

Artifacts:
- backend/artifacts/pr26/accept.log
- backend/artifacts/pr26/verify.log
TXT

# Artifacts sanitize (if repo script exists)
if test -x "${BACKEND_DIR}/scripts/sanitize_artifacts.sh"; then
  bash "${BACKEND_DIR}/scripts/sanitize_artifacts.sh" 26 >> "${ART_DIR}/accept.log" 2>&1 || true
fi

# Fallback sanitize (paths/tokens/keys)
for f in "${ART_DIR}"/*.log; do
  test -f "${f}" || continue
  php -r '
    $f=$argv[1];
    $s=file_get_contents($f);
    $s=preg_replace("#/(Users|home|var|tmp)/[^\\s]+#", "<REDACTED_PATH>", $s);
    $s=preg_replace("#Authorization: Bearer [^\\s]+#", "Authorization: Bearer <REDACTED>", $s);
    $s=preg_replace("#FAP_ADMIN_TOKEN=([^\\s]+)#", "FAP_ADMIN_TOKEN=<REDACTED>", $s);
    $s=preg_replace("#(DB_PASSWORD=|password=)([^\\s]+)#i", "$1<REDACTED>", $s);
    $s=preg_replace("/-----BEGIN [A-Z ]*PRIVATE KEY-----.+?-----END [A-Z ]*PRIVATE KEY-----/s", "<REDACTED_PRIVATE_KEY>", $s);
    file_put_contents($f, $s);
  ' "${f}"
done

# Cleanup temp DB
rm -f "${DB_DATABASE}"
