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
ART_DIR="${ART_DIR:-${REPO_DIR}/backend/artifacts/pr56}"

mkdir -p "${ART_DIR}"
exec >"${ART_DIR}/pr56_verify.log" 2>&1

fail() {
  echo "[PR56][VERIFY][FAIL] $*"
  exit 1
}

echo "[PR56][VERIFY] start"
echo "[PR56][VERIFY] repo=${REPO_DIR}"
echo "[PR56][VERIFY] art_dir=${ART_DIR}"

WORKFLOW_LIST="${ART_DIR}/workflows_list.txt"
WORKFLOW_PHP_VERSION_LINES="${ART_DIR}/workflow_php_version_lines.txt"
WORKFLOW_PHP_VERSION_NON84="${ART_DIR}/workflow_php_version_non84.txt"
WORKFLOW_INSTALL_FILES="${ART_DIR}/workflow_composer_install_files.txt"
WORKFLOW_GATE_REPORT="${ART_DIR}/workflow_composer_gate_report.txt"
COMPOSER_PLATFORM_FILE="${ART_DIR}/composer_platform_php.txt"

find "${REPO_DIR}/.github/workflows" -type f \( -name "*.yml" -o -name "*.yaml" \) | sort >"${WORKFLOW_LIST}"

# Check 1: all workflow php-version lines must be 8.4.
grep -R -n -E 'php-version:' "${REPO_DIR}/.github/workflows" >"${WORKFLOW_PHP_VERSION_LINES}" || true
if [ ! -s "${WORKFLOW_PHP_VERSION_LINES}" ]; then
  fail "no php-version lines found in workflows"
fi
grep -E -v '8\.4' "${WORKFLOW_PHP_VERSION_LINES}" >"${WORKFLOW_PHP_VERSION_NON84}" || true
if [ -s "${WORKFLOW_PHP_VERSION_NON84}" ]; then
  fail "found non-8.4 php-version lines; see ${WORKFLOW_PHP_VERSION_NON84}"
fi

# Check 2: workflows containing composer install must also contain validate/audit gates.
grep -R -l -E 'composer install' "${REPO_DIR}/.github/workflows" | sort >"${WORKFLOW_INSTALL_FILES}" || true
if [ ! -s "${WORKFLOW_INSTALL_FILES}" ]; then
  fail "no workflow contains composer install"
fi

: >"${WORKFLOW_GATE_REPORT}"
for wf in $(cat "${WORKFLOW_INSTALL_FILES}"); do
  install_count="$(grep -c -E 'composer install' "${wf}")"
  validate_count="$(grep -c -E 'composer validate --strict' "${wf}" || true)"
  audit_count="$(grep -c -E 'composer audit --no-interaction' "${wf}" || true)"

  echo "${wf}: install=${install_count} validate=${validate_count} audit=${audit_count}" >>"${WORKFLOW_GATE_REPORT}"

  if [ "${validate_count}" -ne "${install_count}" ]; then
    fail "validate gate count mismatch in ${wf}"
  fi
  if [ "${audit_count}" -ne "${install_count}" ]; then
    fail "audit gate count mismatch in ${wf}"
  fi
done

# Check 3: backend/composer.json config.platform.php must be 8.4.0.
php -r '$p=$argv[1]; if(!file_exists($p)){fwrite(STDERR, "missing composer.json\n"); exit(2);} $c=json_decode(file_get_contents($p), true); if(!is_array($c)){fwrite(STDERR, "composer.json decode failed\n"); exit(3);} $v=$c["config"]["platform"]["php"] ?? null; echo "config.platform.php=".(string)$v.PHP_EOL; if($v!=="8.4.0"){fwrite(STDERR, "config.platform.php must be 8.4.0\n"); exit(4);} ' "${REPO_DIR}/backend/composer.json" >"${COMPOSER_PLATFORM_FILE}"

echo "PASS" >"${ART_DIR}/pr56_verify_status.txt"
echo "[PR56][VERIFY] pass"
