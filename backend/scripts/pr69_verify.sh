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
export LANG=en_US.UTF-8

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BACKEND_DIR="${REPO_DIR}/backend"
ART_DIR="${ART_DIR:-${BACKEND_DIR}/artifacts/pr69}"

mkdir -p "${ART_DIR}"
exec > "${ART_DIR}/verify.log" 2>&1

fail() {
  echo "[PR69][VERIFY][FAIL] $*"
  exit 1
}

echo "[PR69][VERIFY] start"

QUEUE_ASSERT="${ART_DIR}/queue_assertions.txt"
APP_PROVIDER_ASSERT="${ART_DIR}/app_service_provider_assertions.txt"
REDACTOR_ASSERT="${ART_DIR}/redactor_assertions.txt"
ENV_ASSERT="${ART_DIR}/env_assertions.txt"
EVENT_ASSERT="${ART_DIR}/event_controller_assertions.txt"
MIGRATION_ASSERT="${ART_DIR}/migration_assertions.txt"

php -r '
require $argv[1];
$cfg = require $argv[2];
$errs = [];
if (($cfg["failed"]["driver"] ?? "") !== "database-uuids") {
    $errs[] = "failed.driver must be database-uuids";
}
if (($cfg["failed"]["table"] ?? "") !== "failed_jobs") {
    $errs[] = "failed.table must be failed_jobs";
}
foreach (["database", "redis", "beanstalkd"] as $name) {
    $retry = $cfg["connections"][$name]["retry_after"] ?? null;
    if ($retry !== 90) {
        $errs[] = "connections.{$name}.retry_after must be 90";
    }
}
if ($errs) {
    fwrite(STDERR, implode(PHP_EOL, $errs) . PHP_EOL);
    exit(1);
}
echo "queue_assert=pass" . PHP_EOL;
' "${BACKEND_DIR}/vendor/autoload.php" "${BACKEND_DIR}/config/queue.php" > "${QUEUE_ASSERT}" || fail "queue assertions failed"

grep -n -E "pushProcessor|SensitiveDataRedactor|context|extra" \
  "${BACKEND_DIR}/app/Providers/AppServiceProvider.php" > "${APP_PROVIDER_ASSERT}" || fail "AppServiceProvider assertions failed"
grep -q -E "pushProcessor" "${BACKEND_DIR}/app/Providers/AppServiceProvider.php" || fail "pushProcessor missing"
grep -q -E "SensitiveDataRedactor" "${BACKEND_DIR}/app/Providers/AppServiceProvider.php" || fail "SensitiveDataRedactor missing in AppServiceProvider"
grep -q -E "record->context|\\['context'\\]" "${BACKEND_DIR}/app/Providers/AppServiceProvider.php" || fail "context redaction missing"
grep -q -E "record->extra|\\['extra'\\]" "${BACKEND_DIR}/app/Providers/AppServiceProvider.php" || fail "extra redaction missing"

grep -n -E "password|token|secret|credit_card|authorization" \
  "${BACKEND_DIR}/app/Support/SensitiveDataRedactor.php" > "${REDACTOR_ASSERT}" || fail "SensitiveDataRedactor key assertions failed"
for key in password token secret credit_card authorization; do
  grep -q -E "'${key}'" "${BACKEND_DIR}/app/Support/SensitiveDataRedactor.php" || fail "SensitiveDataRedactor missing ${key}"
done

{
  grep -n -E "^APP_DEBUG=false$" "${BACKEND_DIR}/.env.example"
  grep -n -E "^FAP_ADMIN_TOKEN=$" "${BACKEND_DIR}/.env.example"
  grep -n -E "^EVENT_INGEST_TOKEN=$" "${BACKEND_DIR}/.env.example"
} > "${ENV_ASSERT}" || fail ".env.example assertions failed"

grep -n -E "hash_equals|ingest_token|fm_tokens|Schema::hasTable|DB::table\\('fm_tokens'\\)" \
  "${BACKEND_DIR}/app/Http/Controllers/EventController.php" "${BACKEND_DIR}/config/fap.php" > "${EVENT_ASSERT}" || fail "EventController assertions failed"
grep -q -E "hash_equals" "${BACKEND_DIR}/app/Http/Controllers/EventController.php" || fail "hash_equals missing"
grep -q -E "config\\('fap\\.events\\.ingest_token'" "${BACKEND_DIR}/app/Http/Controllers/EventController.php" || fail "ingest_token config read missing"
grep -q -E "DB::table\\('fm_tokens'\\)" "${BACKEND_DIR}/app/Http/Controllers/EventController.php" || fail "fm_tokens DB exists check missing"

ls -1 "${BACKEND_DIR}"/database/migrations/*failed_jobs* > "${MIGRATION_ASSERT}" 2>/dev/null || fail "failed_jobs migration missing"

echo "verify=pass" > "${ART_DIR}/verify_done.txt"
echo "[PR69][VERIFY] pass"
