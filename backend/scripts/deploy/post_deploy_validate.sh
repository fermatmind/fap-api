#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${BASE_URL:-https://fermatmind.com}"
TIMEOUT="${TIMEOUT:-10}"

check_url() {
  local url="$1"
  local expected="$2"

  local code
  code="$(curl -sS --max-time "$TIMEOUT" -o /tmp/rt_body.$$ -w "%{http_code}" "$url" || true)"
  if [ "$code" != "$expected" ]; then
    echo "[smoke] fail ${url} expected ${expected} got ${code}"
    return 1
  fi
}

ok=1
check_url "${BASE_URL}/healthz" "200" || ok=0
check_url "${BASE_URL}/api/v0.5/seo/sitemap-source" "200" || ok=0
check_url "${BASE_URL}/sitemap.xml" "200" || ok=0
check_url "${BASE_URL}/llms.txt" "200" || ok=0
check_url "${BASE_URL}/llms-full.txt" "200" || ok=0
check_url "${BASE_URL}/robots.txt" "200" || ok=0
check_url "${BASE_URL}/api/v0.5/career/datasets/occupations?locale=zh-CN" "200" || ok=0
check_url "${BASE_URL}/api/v0.5/career/jobs?locale=zh-CN" "200" || ok=0
check_url "${BASE_URL}/api/v0.5/career/jobs/software-developers?locale=zh-CN" "404" || ok=0

rm -f /tmp/rt_body.*

if [ "$ok" -eq 1 ]; then
  echo "[smoke] all checks passed"
  exit 0
fi

echo "[smoke] one or more checks failed"
exit 1
