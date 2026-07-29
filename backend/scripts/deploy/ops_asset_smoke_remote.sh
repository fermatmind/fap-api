#!/usr/bin/env bash
set -euo pipefail

ops_host="${1:-}"
if [[ ! "$ops_host" =~ ^[A-Za-z0-9.-]+$ || "$ops_host" == *".."* ]]; then
  echo "ops asset batch: invalid host" >&2
  exit 64
fi

work_dir="$(mktemp -d)"
trap 'rm -rf "$work_dir"' EXIT
records_dir="$work_dir/records"
mkdir -p "$records_dir"

record_index=0
transport_failed=0
business_failed=0
login_body="$work_dir/login.body"

check_resource() {
  local path="$1"
  local requirement="$2"
  local body_file="$3"
  local content_policy="$4"
  local metrics
  local curl_status
  local http_status
  local latency_seconds
  local latency_ms=0
  local result
  local warning=""
  local reference_result="not_applicable"

  set +e
  metrics="$(
    curl \
      --silent \
      --show-error \
      --connect-timeout 5 \
      --max-time 15 \
      --resolve "${ops_host}:443:127.0.0.1" \
      -o "$body_file" \
      -w $'%{http_code}\t%{time_total}' \
      "https://${ops_host}${path}"
  )"
  curl_status=$?
  set -e

  IFS=$'\t' read -r http_status latency_seconds <<< "$metrics"
  http_status="${http_status:-000}"
  latency_seconds="${latency_seconds:-0}"
  if [[ "$latency_seconds" =~ ^[0-9]+([.][0-9]+)?$ ]]; then
    latency_ms="$(awk -v seconds="$latency_seconds" 'BEGIN { printf "%.0f", seconds * 1000 }')"
  fi

  if [ "$curl_status" -ne 0 ] || [[ ! "$http_status" =~ ^[0-9]{3}$ ]]; then
    result="transport_failure"
    transport_failed=1
  elif [ "$http_status" = "200" ]; then
    result="success"
  elif [ "$requirement" = "optional" ] && [ "$http_status" = "404" ]; then
    result="skipped"
    warning="optional asset returned 404"
  else
    result="failure"
    business_failed=1
  fi

  if [ "$result" = "success" ] && [ "$content_policy" = "compiled_theme" ]; then
    if grep -Eq '@tailwind|@config|vendor/filament/filament/resources/css/base\.css' "$body_file"; then
      result="failure"
      warning="served theme asset contains uncompiled source markers"
      business_failed=1
    fi
  fi

  if [ "$content_policy" = "login_page" ]; then
    reference_result="not_applicable"
  elif [ -r "$login_body" ]; then
    if grep -Fq "${path#/}" "$login_body"; then
      reference_result="present"
    else
      reference_result="missing"
      if [ -z "$warning" ]; then
        warning="asset reference is absent from the login page"
      fi
    fi
  else
    reference_result="unavailable"
  fi

  record_index=$((record_index + 1))
  jq -cn \
    --arg path "$path" \
    --arg requirement "$requirement" \
    --arg http_status "$http_status" \
    --argjson latency_ms "$latency_ms" \
    --arg result "$result" \
    --arg reference_result "$reference_result" \
    --arg warning "$warning" \
    '{
      path: $path,
      requirement: $requirement,
      http_status: (
        if ($http_status | test("^[0-9]{3}$")) and $http_status != "000"
        then ($http_status | tonumber)
        else null
        end
      ),
      latency_ms: $latency_ms,
      result: $result,
      reference_result: $reference_result,
      warning: (if $warning == "" then null else $warning end)
    }' > "$records_dir/$(printf '%02d' "$record_index").json"
}

check_resource "/ops/login" "required" "$login_body" "login_page"
check_resource "/css/app/ops-theme.css" "optional" "$work_dir/theme.css" "compiled_theme"
check_resource "/css/filament/filament/app.css" "required" "$work_dir/app.css" "asset"
check_resource "/css/filament/forms/forms.css" "required" "$work_dir/forms.css" "asset"
check_resource "/css/filament/support/support.css" "required" "$work_dir/support.css" "asset"
check_resource "/js/filament/filament/app.js" "required" "$work_dir/app.js" "asset"

overall_result="success"
exit_code=0
if [ "$transport_failed" -eq 1 ]; then
  overall_result="failure"
  exit_code=20
elif [ "$business_failed" -eq 1 ]; then
  overall_result="failure"
  exit_code=21
fi

jq -cs \
  --arg result "$overall_result" \
  '{
    schema_version: "fermatmind.ops-asset-smoke.v1",
    result: $result,
    assets: .
  }' "$records_dir"/*.json

exit "$exit_code"
