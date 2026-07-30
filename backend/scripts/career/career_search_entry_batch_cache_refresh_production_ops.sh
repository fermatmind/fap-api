#!/usr/bin/env bash
set -Eeuo pipefail

contract_version="career.search_entry_batch.cache_refresh.production_ops.v1"
mode="${CAREER_CACHE_REFRESH_MODE:-}"
deploy_path="${DEPLOY_PATH:-}"
expected_release_sha="${EXPECTED_RELEASE_SHA:-}"
expected_release_name="${EXPECTED_RELEASE_NAME:-}"
expected_manifest_sha256="${EXPECTED_MANIFEST_SHA256:-}"
expected_pre_refresh_readback_sha256="${EXPECTED_PRE_REFRESH_READBACK_SHA256:-}"
expected_bad_href_url_count="${EXPECTED_BAD_HREF_URL_COUNT:-}"
expected_low_module_url_count="${EXPECTED_LOW_MODULE_URL_COUNT:-}"
stage="input_validation"
write_state="none"
tmp_dir=""

cleanup() {
  test -z "$tmp_dir" || rm -rf "$tmp_dir"
}
trap cleanup EXIT

emit_failure() {
  local exit_code=$?
  trap - ERR
  jq -n \
    --arg contract "$contract_version" \
    --arg mode "$mode" \
    --arg release "$expected_release_sha" \
    --arg release_name "$expected_release_name" \
    --arg stage "$stage" \
    --arg write_state "$write_state" \
    '{
      contract_version: $contract,
      status: "FAIL_CLOSED",
      mode: $mode,
      release_sha: $release,
      release_name: $release_name,
      failed_stage: $stage,
      write_state: $write_state,
      production_write_execution: ($write_state != "none"),
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

[[ "$mode" == "preflight" || "$mode" == "execute" ]]
[[ "$deploy_path" =~ ^/[A-Za-z0-9._/-]+$ ]]
[[ "$deploy_path" != *".."* ]]
[[ "$expected_release_sha" =~ ^[0-9a-f]{40}$ ]]
[[ "$expected_release_name" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ ]]
if [[ "$mode" == "preflight" ]]; then
  test -z "$expected_manifest_sha256"
  test -z "$expected_pre_refresh_readback_sha256"
  test -z "$expected_bad_href_url_count"
  test -z "$expected_low_module_url_count"
else
  [[ "$expected_manifest_sha256" =~ ^[0-9a-f]{64}$ ]]
  [[ "$expected_pre_refresh_readback_sha256" =~ ^[0-9a-f]{64}$ ]]
  [[ "$expected_bad_href_url_count" =~ ^[1-9][0-9]{0,2}$ ]]
  test "$expected_bad_href_url_count" -le 100
  [[ "$expected_low_module_url_count" =~ ^(0|[1-9][0-9]{0,2})$ ]]
  test "$expected_low_module_url_count" -le 100
fi

stage="active_release_identity"
current_release="$(readlink -f "$deploy_path/current")"
test -n "$current_release"
test "$(basename "$current_release")" = "$expected_release_name"
test "$(tr -d '[:space:]' < "$current_release/REVISION")" = "$expected_release_sha"

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

read_public_batch() {
  local output_path="$1"
  local observations="$tmp_dir/observations.jsonl"
  local response="$tmp_dir/response.json"
  local slug locale locale_path expected_canonical expected_prefix http_code
  local canonical robots page_locale bad_href_count module_count payload_sha256
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
      if ! http_code="$(
        curl --silent --show-error --location \
          --connect-timeout 5 --max-time 20 \
          --header 'Accept: application/json' \
          --output "$response" --write-out '%{http_code}' \
          "https://api.fermatmind.com/api/v0.5/career/jobs/${slug}?locale=${locale}" \
          2>/dev/null
      )"; then
        http_code="000"
        printf '{}' > "$response"
      fi
      if ! jq -e 'type == "object"' "$response" >/dev/null 2>&1; then
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
      module_count="$(jq '.display_surface_v1.page.content | if type == "object" then keys | length else 0 end' "$response")"
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

  jq -s \
    --arg manifest_sha256 "$manifest_sha256" \
    '{
      manifest_sha256: $manifest_sha256,
      slug_count: ([.[].slug] | unique | length),
      url_count: length,
      http_200_count: ([.[] | select(.http == "200")] | length),
      canonical_ok_count: ([.[] | select(.canonical_ok)] | length),
      robots_ok_count: ([.[] | select(.robots_ok)] | length),
      locale_ok_count: ([.[] | select(.locale_ok)] | length),
      bad_href_url_count: ([.[] | select(.bad_href_count > 0)] | length),
      low_module_url_count: ([.[] | select(.module_count < 20)] | length),
      payload_set_sha256: null,
      observations: .
    }' "$observations" > "$output_path"
  payload_set_sha256="$(
    jq -c '[.observations[] | {slug,locale,payload_sha256}]' "$output_path" \
      | sha256sum | awk '{print $1}'
  )"
  jq --arg payload_set_sha256 "$payload_set_sha256" \
    '.payload_set_sha256 = $payload_set_sha256' "$output_path" > "$tmp_dir/readback-final.json"
  mv "$tmp_dir/readback-final.json" "$output_path"
}

stage="pre_refresh_public_readback"
pre_readback="$tmp_dir/pre-readback.json"
read_public_batch "$pre_readback"
pre_refresh_readback_sha256="$(
  jq -c . "$pre_readback" | sha256sum | awk '{print $1}'
)"
pre_bad_href_url_count="$(jq -r '.bad_href_url_count' "$pre_readback")"
pre_low_module_url_count="$(jq -r '.low_module_url_count' "$pre_readback")"
jq -e \
  '.slug_count == 50
   and .url_count == 100
   and .http_200_count == 100
   and .canonical_ok_count == 100
   and .robots_ok_count == 100
   and .locale_ok_count == 100
   and (.payload_set_sha256 | test("^[a-f0-9]{64}$"))' \
  "$pre_readback" >/dev/null

if [[ "$mode" == "preflight" ]]; then
  stage="preflight_refresh_requirement"
  test "$pre_bad_href_url_count" -gt 0
  jq -n \
    --arg contract "$contract_version" \
    --arg release "$expected_release_sha" \
    --arg release_name "$expected_release_name" \
    --arg manifest "$manifest_sha256" \
    --arg pre_readback "$pre_refresh_readback_sha256" \
    --arg payload_set "$(jq -r '.payload_set_sha256' "$pre_readback")" \
    --argjson bad_href "$pre_bad_href_url_count" \
    --argjson low_module "$pre_low_module_url_count" \
    '{
      contract_version: $contract,
      status: "PASS_PREFLIGHT_REFRESH_REQUIRED",
      mode: "preflight",
      release_sha: $release,
      release_name: $release_name,
      manifest_sha256: $manifest,
      pre_refresh_readback_sha256: $pre_readback,
      pre_refresh_payload_set_sha256: $payload_set,
      candidate_count: 50,
      bilingual_url_count: 100,
      bad_href_url_count: $bad_href,
      low_module_url_count: $low_module,
      write_state: "none",
      production_write_execution: false,
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
  exit 0
fi

stage="bound_pre_refresh_state"
test "$manifest_sha256" = "$expected_manifest_sha256"
test "$pre_refresh_readback_sha256" = "$expected_pre_refresh_readback_sha256"
test "$pre_bad_href_url_count" -eq "$expected_bad_href_url_count"
test "$pre_low_module_url_count" -eq "$expected_low_module_url_count"

stage="exact_cache_refresh"
slug_csv="$(IFS=,; printf '%s' "${slugs[*]}")"
warm_receipt="$tmp_dir/warm.json"
write_state="indeterminate"
cd "$backend_path"
php artisan career:warm-public-authority-cache \
  --job-detail-slugs="$slug_csv" \
  --job-detail-locales=en,zh-CN \
  --job-detail-only \
  --json \
  --no-interaction \
  --no-ansi > "$warm_receipt"
jq -e \
  '.status == "warmed"
   and .job_detail_refresh.slug_count == 50
   and .job_detail_refresh.locale_count == 2
   and .job_detail_refresh.expected_cache_entries == 100
   and .job_detail_refresh.observed_cache_entries == 100
   and ([.job_detail_refresh.status_counts[]] | add) == 100' \
  "$warm_receipt" >/dev/null
write_state="committed"

stage="post_refresh_exact_package"
quality_package="$tmp_dir/quality-package.json"
quality_receipt="$tmp_dir/quality-receipt.json"
php artisan career:build-search-entry-quality-batch \
  --output="$quality_package" \
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
   and .negative_guarantees.deploys == 0' \
  "$quality_receipt" >/dev/null

stage="post_refresh_public_readback"
post_readback="$tmp_dir/post-readback.json"
read_public_batch "$post_readback"
post_refresh_readback_sha256="$(
  jq -c . "$post_readback" | sha256sum | awk '{print $1}'
)"
jq -e \
  '.slug_count == 50
   and .url_count == 100
   and .http_200_count == 100
   and .canonical_ok_count == 100
   and .robots_ok_count == 100
   and .locale_ok_count == 100
   and .bad_href_url_count == 0
   and .low_module_url_count == 0
   and (.payload_set_sha256 | test("^[a-f0-9]{64}$"))' \
  "$post_readback" >/dev/null

stage="sanitized_receipt"
jq -n \
  --arg contract "$contract_version" \
  --arg release "$expected_release_sha" \
  --arg release_name "$expected_release_name" \
  --arg manifest "$manifest_sha256" \
  --arg pre_readback "$pre_refresh_readback_sha256" \
  --arg post_readback "$post_refresh_readback_sha256" \
  --arg post_payload_set "$(jq -r '.payload_set_sha256' "$post_readback")" \
  --arg quality "$(jq -r '.quality_package_sha256' "$quality_receipt")" \
  --arg review "$(jq -r '.package_sha256' "$quality_receipt")" \
  --arg targets "$(jq -r '.target_set_sha256' "$quality_receipt")" \
  --argjson pre_bad_href "$pre_bad_href_url_count" \
  --argjson pre_low_module "$pre_low_module_url_count" \
  '{
    contract_version: $contract,
    status: "PASS_EXECUTE_AND_READBACK",
    mode: "execute",
    release_sha: $release,
    release_name: $release_name,
    manifest_sha256: $manifest,
    pre_refresh_readback_sha256: $pre_readback,
    post_refresh_readback_sha256: $post_readback,
    post_refresh_payload_set_sha256: $post_payload_set,
    quality_package_sha256: $quality,
    review_package_sha256: $review,
    target_set_sha256: $targets,
    candidate_count: 50,
    bilingual_url_count: 100,
    review_target_count: 300,
    pre_bad_href_url_count: $pre_bad_href,
    pre_low_module_url_count: $pre_low_module,
    post_http_200_count: 100,
    post_canonical_readback_count: 100,
    post_robots_readback_count: 100,
    post_locale_readback_count: 100,
    post_locale_safe_href_readback_count: 100,
    post_full_module_readback_count: 100,
    write_state: "committed",
    production_write_execution: true,
    cache_refresh_target_count: 100,
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
