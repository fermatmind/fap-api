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
export FAP_PACKS_DRIVER=local
export FAP_PACKS_ROOT="$(pwd)/content_packages"
export FAP_DEFAULT_PACK_ID=MBTI.cn-mainland.zh-CN.v0.2.2
export FAP_DEFAULT_DIR_VERSION=MBTI-CN-v0.2.2
export FAP_CONTENT_PACKAGE_VERSION=v0.2.2

ART_DIR="backend/artifacts/pr35"
mkdir -p "${ART_DIR}"

API_BASE="http://127.0.0.1:1835"

echo "[PR] API_BASE=${API_BASE}" | tee "${ART_DIR}/verify.log"

php -v | tee -a "${ART_DIR}/verify.log"

php backend/artisan serve --host=127.0.0.1 --port=1835 >"${ART_DIR}/server.log" 2>&1 &
SRV_PID=$!
echo "${SRV_PID}" > "${ART_DIR}/server.pid"
trap 'kill ${SRV_PID} >/dev/null 2>&1 || true' EXIT

for i in $(seq 1 40); do
  curl -sS "${API_BASE}/api/v0.2/health" >/dev/null && break
  sleep 1
done

echo "[PR] health OK" | tee -a "${ART_DIR}/verify.log"

API="${API_BASE}" ANON_ID="pr35_verify" bash backend/scripts/verify_mbti.sh 2>&1 | tee -a "${ART_DIR}/verify.log"

cd backend
php artisan test --filter GenericLikertDriverTest 2>&1 | tee -a "../${ART_DIR}/verify.log"
php artisan test --filter PaymentWebhookStripeSignatureTest 2>&1 | tee -a "../${ART_DIR}/verify.log"
cd ..

kill ${SRV_PID} >/dev/null 2>&1 || true
sleep 1
lsof -nP -iTCP:1835 -sTCP:LISTEN || true
