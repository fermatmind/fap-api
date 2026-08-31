#!/usr/bin/env bash

set -Eeuo pipefail

healthcheck_host="${HEALTHCHECK_HOST:-}"
public_web_base_url="${PUBLIC_WEB_BASE_URL:-}"
deploy_revision="${DEPLOY_REVISION:-}"

fail() {
  printf 'STAGING_BIG_FIVE_REPORT_SMOKE_FAILED:%s\n' "$1" >&2
  exit 1
}

[[ "$healthcheck_host" =~ ^[A-Za-z0-9.-]+$ ]] || fail INVALID_API_HOST
[[ "$public_web_base_url" =~ ^https://[A-Za-z0-9.-]+(:[0-9]+)?$ ]] || fail INVALID_WEB_ORIGIN
[[ "$deploy_revision" =~ ^[0-9a-f]{40}$ ]] || fail INVALID_REVISION
command -v curl >/dev/null 2>&1 || fail CURL_UNAVAILABLE
command -v jq >/dev/null 2>&1 || fail JQ_UNAVAILABLE
command -v php >/dev/null 2>&1 || fail PHP_UNAVAILABLE

if [[ "$(id -un)" != www-data ]]; then
  exec /usr/bin/sudo -n -u www-data -- /usr/bin/env \
    HEALTHCHECK_HOST="$healthcheck_host" \
    PUBLIC_WEB_BASE_URL="$public_web_base_url" \
    DEPLOY_REVISION="$deploy_revision" \
    /usr/bin/bash "$0"
fi

tmp_dir="$(mktemp -d "${TMPDIR:-/tmp}/fermatmind-big5-report-smoke.XXXXXX")"
trap 'rm -rf -- "$tmp_dir"' EXIT
chmod 700 "$tmp_dir"

api_origin="https://${healthcheck_host}"
probe_anon_id="codex_probe_${deploy_revision:0:12}_$(date -u +%s)_$$"
probe_header='report-delivery-v1'
curl_local=(
  --silent
  --show-error
  --connect-timeout 5
  --max-time 15
  --resolve "${healthcheck_host}:443:127.0.0.1"
)

guest_body="$tmp_dir/guest.json"
guest_code="$(curl "${curl_local[@]}" -o "$guest_body" -w '%{http_code}' \
  -H 'Content-Type: application/json' \
  -H "X-FermatMind-Internal-Probe: ${probe_header}" \
  --data "$(jq -cn --arg anon_id "$probe_anon_id" '{anon_id:$anon_id}')" \
  "${api_origin}/api/v0.3/auth/guest" 2>/dev/null || true)"
[[ "$guest_code" == 200 ]] || fail GUEST_HTTP
jq -e --arg anon_id "$probe_anon_id" \
  '.ok == true and .anon_id == $anon_id and (.fm_token | test("^fm_[0-9a-fA-F-]{36}$"))' \
  "$guest_body" >/dev/null || fail GUEST_CONTRACT
fm_token="$(jq -r '.fm_token' "$guest_body")"

start_body="$tmp_dir/start.json"
start_code="$(curl "${curl_local[@]}" -o "$start_body" -w '%{http_code}' \
  -H 'Content-Type: application/json' \
  -H "X-Anon-Id: ${probe_anon_id}" \
  -H "X-FermatMind-Internal-Probe: ${probe_header}" \
  --data "$(jq -cn --arg anon_id "$probe_anon_id" '{scale_code:"BIG5_OCEAN",region:"CN_MAINLAND",locale:"zh-CN",anon_id:$anon_id,form_code:"big5_90"}')" \
  "${api_origin}/api/v0.3/attempts/start" 2>/dev/null || true)"
[[ "$start_code" == 200 ]] || fail START_HTTP
jq -e --arg anon_id "$probe_anon_id" '
  .question_count == 90
  and .form_code == "big5_90"
  and .anon_id == $anon_id
  and (.attempt_id | test("^[0-9a-fA-F-]{36}$"))
' "$start_body" >/dev/null || fail START_CONTRACT
attempt_id="$(jq -r '.attempt_id' "$start_body")"

questions_body="$tmp_dir/questions.json"
questions_code="$(curl "${curl_local[@]}" -o "$questions_body" -w '%{http_code}' \
  "${api_origin}/api/v0.3/scales/BIG5_OCEAN/questions?form=big5_90&locale=zh-CN" 2>/dev/null || true)"
[[ "$questions_code" == 200 ]] || fail QUESTIONS_HTTP
jq -e '.form_code == "big5_90" and (.questions.items | length) == 90' \
  "$questions_body" >/dev/null || fail QUESTIONS_CONTRACT

answers_body="$tmp_dir/answers.json"
jq -c '
  .questions.items
  | to_entries
  | map(
      .value as $question
      | ($question.options | length) as $option_count
      | select(($question.question_id | tostring | length) > 0 and $option_count > 0)
      | {
          question_id: ($question.question_id | tostring),
          code: ($question.options[(.key % $option_count)].code | tostring)
        }
    )
' "$questions_body" > "$answers_body"
jq -e 'length == 90 and all(.[]; (.question_id | length) > 0 and (.code | length) > 0)' \
  "$answers_body" >/dev/null || fail ANSWERS_CONTRACT

submit_body="$tmp_dir/submit.json"
submit_payload="$tmp_dir/submit-payload.json"
jq -cn --arg attempt_id "$attempt_id" --slurpfile answers "$answers_body" \
  '{attempt_id:$attempt_id,answers:$answers[0],duration_ms:270000}' > "$submit_payload"
submit_code="$(curl "${curl_local[@]}" -o "$submit_body" -w '%{http_code}' \
  -H 'Content-Type: application/json' \
  -H "Authorization: Bearer ${fm_token}" \
  -H "X-Anon-Id: ${probe_anon_id}" \
  -H "X-FermatMind-Internal-Probe: ${probe_header}" \
  --data-binary "@${submit_payload}" \
  "${api_origin}/api/v0.3/attempts/submit" 2>/dev/null || true)"
[[ "$submit_code" == 200 || "$submit_code" == 202 ]] || fail SUBMIT_HTTP
jq -e --arg attempt_id "$attempt_id" '.ok == true and .attempt_id == $attempt_id' \
  "$submit_body" >/dev/null || fail SUBMIT_CONTRACT

submission_body="$tmp_dir/submission.json"
result_body="$tmp_dir/result.json"
report_body="$tmp_dir/report.json"
deadline=$((SECONDS + 90))
delivery_ready=false

while (( SECONDS <= deadline )); do
  submission_code="$(curl "${curl_local[@]}" -o "$submission_body" -w '%{http_code}' \
    -H "Authorization: Bearer ${fm_token}" \
    -H "X-Anon-Id: ${probe_anon_id}" \
    "${api_origin}/api/v0.3/attempts/${attempt_id}/submission" 2>/dev/null || true)"
  result_code="$(curl "${curl_local[@]}" -o "$result_body" -w '%{http_code}' \
    -H "Authorization: Bearer ${fm_token}" \
    -H "X-Anon-Id: ${probe_anon_id}" \
    "${api_origin}/api/v0.3/attempts/${attempt_id}/result" 2>/dev/null || true)"
  report_code="$(curl "${curl_local[@]}" -o "$report_body" -w '%{http_code}' \
    -H "Authorization: Bearer ${fm_token}" \
    -H "X-Anon-Id: ${probe_anon_id}" \
    "${api_origin}/api/v0.3/attempts/${attempt_id}/report" 2>/dev/null || true)"

  # The single-quoted block is PHP source; shell expansion would expose probe state.
  # shellcheck disable=SC2016
  snapshot_status="$(printf '%s' "$attempt_id" | php -d display_errors=0 -r '
    try {
        $attemptId = trim(stream_get_contents(STDIN));
        if (preg_match("/\\A[0-9a-fA-F-]{36}\\z/D", $attemptId) !== 1) { exit(1); }
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $kernel = $app->make(Illuminate\\Contracts\\Console\\Kernel::class);
        $kernel->bootstrap();
        $status = Illuminate\\Support\\Facades\\DB::table("report_snapshots")
            ->where("org_id", 0)->where("attempt_id", $attemptId)->value("status");
        echo strtolower(trim((string) $status));
    } catch (Throwable) { exit(1); }
  ' 2>/dev/null || true)"

  if [[ "$submission_code" == 200 \
    && "$result_code" == 200 \
    && "$report_code" == 200 \
    && "$snapshot_status" == ready ]] \
    && jq -e '.submission.state == "succeeded" and .generating == false' "$submission_body" >/dev/null 2>&1 \
    && jq -e '.ok == true and .big5_form_v1.question_count == 90' "$result_body" >/dev/null 2>&1 \
    && jq -e '.ok == true and .generating != true and (.report | type) == "object"' "$report_body" >/dev/null 2>&1; then
    delivery_ready=true
    break
  fi

  sleep 3
done

if [[ "$delivery_ready" != true ]]; then
  submission_state="$(jq -r '.submission.state // "unknown"' "$submission_body" 2>/dev/null || printf unknown)"
  submission_generating="$(jq -r 'if (.generating | type) == "boolean" then .generating else "unknown" end' "$submission_body" 2>/dev/null || printf unknown)"
  result_ok="$(jq -r 'if (.ok | type) == "boolean" then .ok else "unknown" end' "$result_body" 2>/dev/null || printf unknown)"
  result_question_count="$(jq -r '.big5_form_v1.question_count // 0' "$result_body" 2>/dev/null || printf 0)"
  report_ok="$(jq -r 'if (.ok | type) == "boolean" then .ok else "unknown" end' "$report_body" 2>/dev/null || printf unknown)"
  report_generating="$(jq -r 'if (.generating | type) == "boolean" then .generating else "unknown" end' "$report_body" 2>/dev/null || printf unknown)"
  report_type="$(jq -r 'if has("report") then (.report | type) else "missing" end' "$report_body" 2>/dev/null || printf unknown)"

  [[ "$submission_code" =~ ^[0-9]{3}$ ]] || submission_code=000
  [[ "$result_code" =~ ^[0-9]{3}$ ]] || result_code=000
  [[ "$report_code" =~ ^[0-9]{3}$ ]] || report_code=000
  [[ "$submission_state" =~ ^(pending|running|succeeded|failed|unknown)$ ]] || submission_state=other
  [[ "$submission_generating" =~ ^(true|false|unknown)$ ]] || submission_generating=unknown
  [[ "$result_ok" =~ ^(true|false|unknown)$ ]] || result_ok=unknown
  [[ "$result_question_count" =~ ^[0-9]+$ ]] || result_question_count=0
  [[ "$report_ok" =~ ^(true|false|unknown)$ ]] || report_ok=unknown
  [[ "$report_generating" =~ ^(true|false|unknown)$ ]] || report_generating=unknown
  [[ "$report_type" =~ ^(object|array|null|string|number|boolean|missing|unknown)$ ]] || report_type=other
  [[ "$snapshot_status" =~ ^(pending|ready|failed)$ ]] || snapshot_status=missing_or_other

  printf 'staging_big_five_report_smoke=timeout submission_http=%s submission_state=%s submission_generating=%s snapshot=%s result_http=%s result_ok=%s result_question_count=%s report_http=%s report_ok=%s report_generating=%s report_type=%s\n' \
    "$submission_code" "$submission_state" "$submission_generating" "$snapshot_status" \
    "$result_code" "$result_ok" "$result_question_count" \
    "$report_code" "$report_ok" "$report_generating" "$report_type" >&2
  fail DELIVERY_TIMEOUT
fi

public_result_code="$(curl --silent --show-error --connect-timeout 5 --max-time 15 \
  -o /dev/null -w '%{http_code}' \
  "${public_web_base_url}/zh/result/${attempt_id}" 2>/dev/null || true)"
[[ "$public_result_code" == 200 ]] || fail PUBLIC_RESULT_HTTP

printf 'staging_big_five_report_smoke=pass question_count=90 submission=succeeded snapshot=ready result_api=200 public_result=200\n'
