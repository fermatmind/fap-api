#!/usr/bin/env bash
set -Eeuo pipefail

contract_version="career.search_entry_batch.cache_refresh.recovery_preflight.v1"
deploy_path="${DEPLOY_PATH:-}"
expected_release_sha="${EXPECTED_RELEASE_SHA:-}"
expected_release_name="${EXPECTED_RELEASE_NAME:-}"
failed_run_id="${FAILED_RUN_ID:-}"
failed_run_attempt="${FAILED_RUN_ATTEMPT:-}"
failed_receipt_sha256="${FAILED_RECEIPT_SHA256:-}"
stage="input_validation"
tmp_dir=""
heartbeat_pid=""
observed_readback_url_count=0
observed_http_200_count=0
observed_transport_failure_count=0
observed_non_200_response_count=0
observed_canonical_ok_count=0
observed_robots_ok_count=0
observed_locale_ok_count=0
observed_bad_href_url_count=0
observed_low_module_url_count=0

cleanup() {
  if [[ -n "$heartbeat_pid" ]]; then
    kill "$heartbeat_pid" 2>/dev/null || true
    wait "$heartbeat_pid" 2>/dev/null || true
  fi
  test -z "$tmp_dir" || rm -rf "$tmp_dir"
}
trap cleanup EXIT

start_sanitized_heartbeat() {
  (
    while true; do
      sleep 20
      printf '%s\n' "career_cache_refresh_recovery_preflight_status=running" >&2
    done
  ) &
  heartbeat_pid="$!"
}

emit_failure() {
  local exit_code=$?
  trap - ERR
  jq -n \
    --arg contract "$contract_version" \
    --arg release "$expected_release_sha" \
    --arg release_name "$expected_release_name" \
    --arg failed_run_id "$failed_run_id" \
    --arg failed_run_attempt "$failed_run_attempt" \
    --arg failed_receipt "$failed_receipt_sha256" \
    --arg stage "$stage" \
    --argjson observed_readback_url_count "$observed_readback_url_count" \
    --argjson observed_http_200_count "$observed_http_200_count" \
    --argjson observed_transport_failure_count "$observed_transport_failure_count" \
    --argjson observed_non_200_response_count "$observed_non_200_response_count" \
    --argjson observed_canonical_ok_count "$observed_canonical_ok_count" \
    --argjson observed_robots_ok_count "$observed_robots_ok_count" \
    --argjson observed_locale_ok_count "$observed_locale_ok_count" \
    --argjson observed_bad_href_url_count "$observed_bad_href_url_count" \
    --argjson observed_low_module_url_count "$observed_low_module_url_count" \
    '{
      contract_version: $contract,
      status: "HOLD_RECOVERY_STATE_UNCERTAIN",
      release_sha: $release,
      release_name: $release_name,
      failed_run_id: ($failed_run_id | tonumber?),
      failed_run_attempt: ($failed_run_attempt | tonumber?),
      failed_receipt_sha256: $failed_receipt,
      failed_stage: $stage,
      observed_readback_url_count: $observed_readback_url_count,
      observed_http_200_count: $observed_http_200_count,
      observed_transport_failure_count: $observed_transport_failure_count,
      observed_non_200_response_count: $observed_non_200_response_count,
      observed_canonical_ok_count: $observed_canonical_ok_count,
      observed_robots_ok_count: $observed_robots_ok_count,
      observed_locale_ok_count: $observed_locale_ok_count,
      observed_bad_href_url_count: $observed_bad_href_url_count,
      observed_low_module_url_count: $observed_low_module_url_count,
      write_state: "none",
      production_write_execution: false,
      recovery_action_authorized: false,
      retry_execution_count: 0,
      cache_refresh_target_count: 0,
      database_write_count: 0,
      cms_write_count: 0,
      publication_write_count: 0,
      indexability_write_count: 0,
      queue_dispatch_count: 0,
      sitemap_write_count: 0,
      llms_write_count: 0,
      search_channel_action_count: 0,
      url_submission_count: 0,
      non_target_write_count: 0,
      deploy_count: 0,
      rollback_count: 0
    }'
  exit "$exit_code"
}
trap emit_failure ERR

[[ "$deploy_path" =~ ^/[A-Za-z0-9._/-]+$ ]]
[[ "$deploy_path" != *".."* ]]
[[ "$expected_release_sha" =~ ^[0-9a-f]{40}$ ]]
[[ "$expected_release_name" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ ]]
[[ "$failed_run_id" =~ ^[1-9][0-9]*$ ]]
[[ "$failed_run_attempt" =~ ^[1-9][0-9]*$ ]]
[[ "$failed_receipt_sha256" =~ ^[0-9a-f]{64}$ ]]

stage="active_release_identity"
current_release="$(readlink -f "$deploy_path/current")"
test -n "$current_release"
test "$(basename "$current_release")" = "$expected_release_name"
test "$(tr -d '[:space:]' < "$current_release/REVISION")" = "$expected_release_sha"
test ! -e "$deploy_path/.dep/deploy.lock"

stage="exact_manifest"
backend_path="$current_release/backend"
manifest_path="$backend_path/content_packs/career/CAREER-SEARCH-ENTRY-QUALITY-BATCH-01/manifest.json"
test -f "$manifest_path"
manifest_sha256="$(sha256sum "$manifest_path" | awk '{print $1}')"
[[ "$manifest_sha256" =~ ^[0-9a-f]{64}$ ]]
jq -e \
  '.schema_version == "career.search_entry_quality_batch_manifest.v1"
   and .task_id == "CAREER-SEARCH-ENTRY-QUALITY-BATCH-01"
   and .expected_candidate_count == 50
   and .max_candidate_count == 50
   and (.candidates | length) == 50
   and ([.candidates[].canonical_slug] | unique | length) == 50
   and all(.candidates[];
     (.canonical_slug | type) == "string"
     and (.canonical_slug | test("^[a-z0-9]+(-[a-z0-9]+)*$"))
     and (.expected_publish_track == "stable" or .expected_publish_track == "candidate"))' \
  "$manifest_path" >/dev/null
mapfile -t slugs < <(jq -r '.candidates[].canonical_slug' "$manifest_path")
test "${#slugs[@]}" -eq 50

tmp_dir="$(mktemp -d)"
start_sanitized_heartbeat

stage="current_public_readback"
observations="$tmp_dir/observations.jsonl"
response="$tmp_dir/response.json"
: > "$observations"
for slug in "${slugs[@]}"; do
  for locale in en zh-CN; do
    if [[ "$locale" == "en" ]]; then
      locale_path="en"
    else
      locale_path="zh"
    fi
    expected_prefix="/${locale_path}/"
    expected_canonical="/${locale_path}/career/jobs/${slug}"
    http_code="000"
    for curl_attempt in 1 2; do
      if http_code="$(
        curl --silent --show-error --location \
          --connect-timeout 5 --max-time 30 \
          --header 'Accept: application/json' \
          --output "$response" --write-out '%{http_code}' \
          "https://api.fermatmind.com/api/v0.5/career/jobs/${slug}?locale=${locale}" \
          2>/dev/null
      )"; then
        break
      fi
      http_code="000"
      if [[ "$curl_attempt" -lt 2 ]]; then
        sleep 1
      fi
    done
    if [[ "$http_code" == "000" ]] || ! jq -e 'type == "object"' "$response" >/dev/null 2>&1; then
      printf '{}' > "$response"
    fi
    canonical="$(jq -r '.seo_contract.canonical_path // ""' "$response")"
    robots="$(jq -r '.seo_contract.robots_policy // ""' "$response")"
    page_locale="$(jq -r '.display_surface_v1.page.locale // ""' "$response")"
    bad_href_count="$(
      jq \
        --arg prefix "$expected_prefix" \
        '[.display_surface_v1.page.content
          | ..
          | objects
          | .href?
          | select(type == "string")
          | select(contains(" | ") or (startswith("/") and (startswith($prefix) | not)))]
         | length' "$response"
    )"
    module_count="$(
      jq '.display_surface_v1.page.content | if type == "object" then keys | length else 0 end' \
        "$response"
    )"
    payload_sha256="$(sha256sum "$response" | awk '{print $1}')"
    jq -cn \
      --arg slug "$slug" \
      --arg locale "$locale" \
      --arg http "$http_code" \
      --arg canonical "$canonical" \
      --arg expected_canonical "$expected_canonical" \
      --arg robots "$robots" \
      --arg page_locale "$page_locale" \
      --arg payload_sha256 "$payload_sha256" \
      --argjson bad_href_count "$bad_href_count" \
      --argjson module_count "$module_count" \
      '{
        slug: $slug,
        locale: $locale,
        http: $http,
        canonical_ok: ($canonical == $expected_canonical),
        robots_ok: ($robots == "index,follow"),
        locale_ok: ($page_locale == $locale),
        bad_href_count: $bad_href_count,
        module_count: $module_count,
        payload_sha256: $payload_sha256
      }' >> "$observations"
  done
done

readback="$tmp_dir/current-readback.json"
jq -s \
  --arg manifest_sha256 "$manifest_sha256" \
  '{
    manifest_sha256: $manifest_sha256,
    slug_count: ([.[].slug] | unique | length),
    url_count: length,
    http_200_count: ([.[] | select(.http == "200")] | length),
    transport_failure_count: ([.[] | select(.http == "000")] | length),
    non_200_response_count: ([.[] | select(.http != "000" and .http != "200")] | length),
    canonical_ok_count: ([.[] | select(.canonical_ok)] | length),
    robots_ok_count: ([.[] | select(.robots_ok)] | length),
    locale_ok_count: ([.[] | select(.locale_ok)] | length),
    bad_href_url_count: ([.[] | select(.bad_href_count > 0)] | length),
    low_module_url_count: ([.[] | select(.module_count < 20)] | length),
    payload_set_sha256: null,
    observations: .
  }' "$observations" > "$readback"
payload_set_sha256="$(
  jq -c '[.observations[] | {slug,locale,payload_sha256}]' "$readback" \
    | sha256sum | awk '{print $1}'
)"
jq --arg payload_set_sha256 "$payload_set_sha256" \
  '.payload_set_sha256 = $payload_set_sha256' "$readback" > "$tmp_dir/readback-final.json"
mv "$tmp_dir/readback-final.json" "$readback"

observed_readback_url_count="$(jq -r '.url_count' "$readback")"
observed_http_200_count="$(jq -r '.http_200_count' "$readback")"
observed_transport_failure_count="$(jq -r '.transport_failure_count' "$readback")"
observed_non_200_response_count="$(jq -r '.non_200_response_count' "$readback")"
observed_canonical_ok_count="$(jq -r '.canonical_ok_count' "$readback")"
observed_robots_ok_count="$(jq -r '.robots_ok_count' "$readback")"
observed_locale_ok_count="$(jq -r '.locale_ok_count' "$readback")"
observed_bad_href_url_count="$(jq -r '.bad_href_url_count' "$readback")"
observed_low_module_url_count="$(jq -r '.low_module_url_count' "$readback")"
current_readback_sha256="$(jq -c . "$readback" | sha256sum | awk '{print $1}')"

jq -e \
  '.slug_count == 50
   and .url_count == 100
   and .http_200_count == 100
   and .transport_failure_count == 0
   and .non_200_response_count == 0
   and .canonical_ok_count == 100
   and .robots_ok_count == 100
   and .locale_ok_count == 100
   and (.payload_set_sha256 | test("^[a-f0-9]{64}$"))' \
  "$readback" >/dev/null

status="PASS_RECOVERY_RESUME_REQUIRED"
quality_package_evaluated=false
quality_package_sha256=""
review_package_sha256=""
target_set_sha256=""
if [[ "$observed_bad_href_url_count" -eq 0 && "$observed_low_module_url_count" -eq 0 ]]; then
  stage="current_exact_quality_package"
  quality_receipt="$tmp_dir/quality-receipt.json"
  cd "$backend_path"
  php artisan career:build-search-entry-quality-batch \
    --json \
    --no-interaction \
    --no-ansi > "$quality_receipt"
  jq -e \
    '.status == "PASS_CAREER_SEARCH_ENTRY_QUALITY_BATCH"
     and .candidate_count == 50
     and .bilingual_url_count == 100
     and .target_count == 300
     and .negative_guarantees.database_writes == 0
     and .negative_guarantees.cms_writes == 0
     and .negative_guarantees.cache_writes == 0
     and .negative_guarantees.queue_dispatches == 0
     and .negative_guarantees.publication_writes == 0
     and .negative_guarantees.indexability_writes == 0
     and .negative_guarantees.sitemap_writes == 0
     and .negative_guarantees.llms_writes == 0
     and .negative_guarantees.search_channel_actions == 0
     and .negative_guarantees.url_submissions == 0
     and .negative_guarantees.deploys == 0' "$quality_receipt" >/dev/null
  quality_package_evaluated=true
  quality_package_sha256="$(jq -er '.quality_package_sha256' "$quality_receipt")"
  review_package_sha256="$(jq -er '.package_sha256' "$quality_receipt")"
  target_set_sha256="$(jq -er '.target_set_sha256' "$quality_receipt")"
  status="PASS_RECOVERY_CURRENT_STATE_COMPLETE"
fi

stage="sanitized_recovery_receipt"
jq -n \
  --arg contract "$contract_version" \
  --arg status "$status" \
  --arg release "$expected_release_sha" \
  --arg release_name "$expected_release_name" \
  --argjson failed_run_id "$failed_run_id" \
  --argjson failed_run_attempt "$failed_run_attempt" \
  --arg failed_receipt "$failed_receipt_sha256" \
  --arg manifest "$manifest_sha256" \
  --arg current_readback "$current_readback_sha256" \
  --arg payload_set "$payload_set_sha256" \
  --argjson bad_href "$observed_bad_href_url_count" \
  --argjson low_module "$observed_low_module_url_count" \
  --argjson quality_evaluated "$quality_package_evaluated" \
  --arg quality "$quality_package_sha256" \
  --arg review "$review_package_sha256" \
  --arg targets "$target_set_sha256" \
  '{
    contract_version: $contract,
    status: $status,
    release_sha: $release,
    release_name: $release_name,
    failed_run_id: $failed_run_id,
    failed_run_attempt: $failed_run_attempt,
    failed_receipt_sha256: $failed_receipt,
    manifest_sha256: $manifest,
    current_readback_sha256: $current_readback,
    current_payload_set_sha256: $payload_set,
    candidate_count: 50,
    bilingual_url_count: 100,
    http_200_count: 100,
    canonical_ok_count: 100,
    robots_ok_count: 100,
    locale_ok_count: 100,
    bad_href_url_count: $bad_href,
    low_module_url_count: $low_module,
    quality_package_evaluated: $quality_evaluated,
    quality_package_sha256: (if $quality == "" then null else $quality end),
    review_package_sha256: (if $review == "" then null else $review end),
    target_set_sha256: (if $targets == "" then null else $targets end),
    write_state: "none",
    production_write_execution: false,
    recovery_action_authorized: false,
    retry_execution_count: 0,
    cache_refresh_target_count: 0,
    database_write_count: 0,
    cms_write_count: 0,
    publication_write_count: 0,
    indexability_write_count: 0,
    queue_dispatch_count: 0,
    sitemap_write_count: 0,
    llms_write_count: 0,
    search_channel_action_count: 0,
    url_submission_count: 0,
    non_target_write_count: 0,
    deploy_count: 0,
    rollback_count: 0
  }'
