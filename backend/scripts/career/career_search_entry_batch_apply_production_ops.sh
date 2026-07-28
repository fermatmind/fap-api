#!/usr/bin/env bash
set -Eeuo pipefail

contract_version="career.search_entry_batch.apply.production_ops.v1"
mode="${CAREER_APPLY_MODE:-}"
deploy_path="${DEPLOY_PATH:-}"
expected_release_sha="${EXPECTED_RELEASE_SHA:-}"
expected_release_name="${EXPECTED_RELEASE_NAME:-}"
actor_admin_user_id="${ACTOR_ADMIN_USER_ID:-}"
operation_id="${OPERATION_ID:-}"
rollback_identifier="${ROLLBACK_IDENTIFIER:-}"
review_evidence_sha256="${EXPECTED_REVIEW_EVIDENCE_SHA256:-}"
expected_apply_receipt_sha256="${EXPECTED_APPLY_RECEIPT_SHA256:-}"
expected_rollback_authorization_sha256="${EXPECTED_ROLLBACK_AUTHORIZATION_SHA256:-}"
stage="input_validation"
tmp_dir=""

emit_failure() {
  local exit_code=$?
  trap - ERR
  jq -n \
    --arg contract "$contract_version" \
    --arg mode "$mode" \
    --arg release "$expected_release_sha" \
    --arg release_name "$expected_release_name" \
    --arg stage "$stage" \
    '{
      contract_version: $contract,
      status: "FAIL_CLOSED",
      mode: $mode,
      release_sha: $release,
      release_name: $release_name,
      failed_stage: $stage,
      write_state: "indeterminate",
      operation_write_count: 0,
      publication_write_count: 0,
      indexability_write_count: 0,
      cache_write_count: 0,
      queue_dispatch_count: 0,
      sitemap_write_count: 0,
      llms_write_count: 0,
      search_channel_action_count: 0,
      url_submission_count: 0,
      deploy_count: 0
    }'
  exit "$exit_code"
}
trap emit_failure ERR

[[ "$mode" == "preflight" || "$mode" == "apply" || "$mode" == "rollback" ]]
[[ "$deploy_path" =~ ^/[A-Za-z0-9._/-]+$ ]]
[[ "$deploy_path" != *".."* ]]
[[ "$expected_release_sha" =~ ^[0-9a-f]{40}$ ]]
[[ "$expected_release_name" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ ]]
[[ "$actor_admin_user_id" =~ ^[1-9][0-9]*$ ]]
[[ "$operation_id" =~ ^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$ ]]
[[ "$rollback_identifier" =~ ^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$ ]]
[[ "$review_evidence_sha256" =~ ^[0-9a-f]{64}$ ]]
if [[ "$mode" == "rollback" ]]; then
  [[ "$expected_apply_receipt_sha256" =~ ^[0-9a-f]{64}$ ]]
  [[ "$expected_rollback_authorization_sha256" =~ ^[0-9a-f]{64}$ ]]
fi

stage="active_release_identity"
current_release="$(readlink -f "$deploy_path/current")"
test -n "$current_release"
test "$(basename "$current_release")" = "$expected_release_name"
test "$(tr -d '[:space:]' < "$current_release/REVISION")" = "$expected_release_sha"

stage="exact_package_build"
tmp_dir="$(mktemp -d)"
trap 'test -z "$tmp_dir" || rm -rf "$tmp_dir"' EXIT
package_path="$tmp_dir/career-search-entry-quality-batch.json"
build_receipt="$tmp_dir/build.json"
cd "$current_release/backend"
php artisan career:build-search-entry-quality-batch \
  --output="$package_path" \
  --json \
  --no-interaction \
  --no-ansi > "$build_receipt"
jq -e \
  '.status == "PASS_CAREER_SEARCH_ENTRY_QUALITY_BATCH"
   and .candidate_count == 50
   and .bilingual_url_count == 100
   and .target_count == 300' "$build_receipt" >/dev/null

stage="revalidate_active_release_before_write"
latest_current_release="$(readlink -f "$deploy_path/current")"
test "$latest_current_release" = "$current_release"
test "$(tr -d '[:space:]' < "$latest_current_release/REVISION")" = "$expected_release_sha"

stage="bounded_operation"
operation_receipt="$tmp_dir/operation.json"
operation_args=(
  career:control-search-entry-quality-batch
  "--mode=$mode"
  "--expected-package=$package_path"
  "--active-release-sha=$expected_release_sha"
  "--active-release-name=$expected_release_name"
  "--operation-id=$operation_id"
  "--rollback-identifier=$rollback_identifier"
  "--actor-admin-user-id=$actor_admin_user_id"
  "--expected-review-evidence-sha256=$review_evidence_sha256"
  --json
  --no-interaction
  --no-ansi
)
if [[ "$mode" == "rollback" ]]; then
  operation_args+=(
    "--expected-apply-receipt-sha256=$expected_apply_receipt_sha256"
    "--expected-rollback-authorization-sha256=$expected_rollback_authorization_sha256"
  )
fi
php artisan "${operation_args[@]}" > "$operation_receipt"
jq -e \
  --arg mode "$mode" \
  '(if $mode == "preflight"
    then .status == "PASS_APPLY_PREFLIGHT"
         and .operation_write_count == 0
         and .production_write_execution == false
    elif $mode == "apply"
    then (.status == "PASS_APPLY_COMMITTED" or .status == "PASS_APPLY_ALREADY_COMMITTED")
         and (.operation_write_count == 1 or .operation_write_count == 0)
         and .production_write_execution == true
    else (.status == "PASS_ROLLBACK_COMMITTED" or .status == "PASS_ROLLBACK_ALREADY_COMMITTED")
         and (.operation_write_count == 1 or .operation_write_count == 0)
         and .production_write_execution == true
    end)
   and .candidate_count == 50
   and .bilingual_url_count == 100
   and .review_target_count == 300
   and .held_slug_count == 0
   and .unknown_target_count == 0
   and .target_drift_count == 0
   and .negative_guarantees.cms_writes == 0
   and .negative_guarantees.cache_writes == 0
   and .negative_guarantees.queue_dispatches == 0
   and .negative_guarantees.publication_writes == 0
   and .negative_guarantees.indexability_writes == 0
   and .negative_guarantees.sitemap_writes == 0
   and .negative_guarantees.llms_writes == 0
   and .negative_guarantees.search_channel_actions == 0
   and .negative_guarantees.url_submissions == 0
   and .negative_guarantees.deploys == 0
   and .negative_guarantees.non_target_writes == 0' "$operation_receipt" >/dev/null

public_detail_readback_count=0
sitemap_membership_readback_count=0
readback_receipt="$operation_receipt"
if [[ "$mode" == "apply" ]]; then
  stage="cache_backed_authority_readback"
  readback_receipt="$tmp_dir/readback.json"
  readback_args=("${operation_args[@]}")
  readback_args[1]="--mode=readback"
  php artisan "${readback_args[@]}" > "$readback_receipt"
  jq -e \
    '.status == "PASS_APPLY_READBACK"
     and .cache_backed_detail_readback_count == 100
     and .cache_backed_index_readback_count == 100
     and .canonical_readback_count == 100
     and .robots_readback_count == 100
     and .indexability_readback_count == 100
     and .operation_write_count == 0' "$readback_receipt" >/dev/null

  stage="public_api_readback"
  while IFS= read -r slug; do
    expected_tier="$(jq -er --arg slug "$slug" \
      '.candidates[] | select(.canonical_slug == $slug) | .target_search_entry_tier' \
      "$package_path")"
    for locale in en zh-CN; do
      public_payload="$tmp_dir/public-${public_detail_readback_count}.json"
      curl --retry 3 --retry-all-errors --retry-delay 2 -fsS \
        --connect-timeout 5 --max-time 45 \
        "https://api.fermatmind.com/api/v0.5/career/jobs/${slug}?locale=${locale}" \
        > "$public_payload"
      jq -e \
        --arg tier "$expected_tier" \
        --arg locale "$locale" \
        '.search_entry_tier == $tier
         and .search_entry_authority.search_entry_eligible == true
         and .search_entry_authority.review_state == "approved"
         and .search_entry_authority.content_quality_tier
             == "tier_a_controlled_search_entry_candidate"
         and .seo_contract.index_eligible == true
         and (.seo_contract.robots_policy | contains("index"))
         and (.seo_contract.robots_policy | contains("follow"))
         and (if $locale == "en"
              then (.seo_contract.canonical_path | startswith("/en/"))
              else (.seo_contract.canonical_path | startswith("/zh/"))
              end)' "$public_payload" >/dev/null
      public_detail_readback_count=$((public_detail_readback_count + 1))
    done
  done < <(jq -er '.slugs[]' "$package_path")
  test "$public_detail_readback_count" -eq 100

  stage="sitemap_membership_readback"
  sitemap_path="$tmp_dir/career-sitemap.xml"
  curl --retry 3 --retry-all-errors --retry-delay 2 -fsS \
    --connect-timeout 5 --max-time 60 \
    "https://fermatmind.com/sitemaps/career" > "$sitemap_path"
  while IFS= read -r canonical_path; do
    grep -Fq "https://fermatmind.com${canonical_path}<" "$sitemap_path"
    sitemap_membership_readback_count=$((sitemap_membership_readback_count + 1))
  done < <(jq -er '.canonical_urls[]' "$package_path")
  test "$sitemap_membership_readback_count" -eq 100
fi

stage="sanitized_receipt"
jq -n \
  --arg contract "$contract_version" \
  --arg mode "$mode" \
  --arg release "$expected_release_sha" \
  --arg release_name "$expected_release_name" \
  --arg operation "$operation_id" \
  --arg rollback "$rollback_identifier" \
  --argjson actor "$actor_admin_user_id" \
  --argjson public_count "$public_detail_readback_count" \
  --argjson sitemap_count "$sitemap_membership_readback_count" \
  --slurpfile operation_receipt "$operation_receipt" \
  --slurpfile readback "$readback_receipt" \
  '{
    contract_version: $contract,
    status: (if $mode == "preflight" then "PASS_PREFLIGHT"
             elif $mode == "apply" then "PASS_APPLY"
             else "PASS_ROLLBACK" end),
    mode: $mode,
    release_sha: $release,
    release_name: $release_name,
    operation_id: $operation,
    rollback_identifier: $rollback,
    actor_admin_user_id: $actor,
    quality_package_sha256: $operation_receipt[0].quality_package_sha256,
    review_package_sha256: $operation_receipt[0].review_package_sha256,
    target_set_sha256: $operation_receipt[0].target_set_sha256,
    candidate_count: $operation_receipt[0].candidate_count,
    bilingual_url_count: $operation_receipt[0].bilingual_url_count,
    review_target_count: $operation_receipt[0].review_target_count,
    review_evidence_sha256: $operation_receipt[0].review_evidence_sha256,
    preflight_state_sha256: ($operation_receipt[0].preflight_state_sha256 // null),
    operation_receipt_sha256: ($operation_receipt[0].operation_receipt_sha256 // null),
    rollback_authorization_sha256: ($operation_receipt[0].rollback_authorization_sha256 // null),
    rollback_receipt_sha256: ($operation_receipt[0].rollback_receipt_sha256 // null),
    publication_unchanged_receipt_sha256:
      ($readback[0].publication_unchanged_receipt_sha256
       // $operation_receipt[0].publication_unchanged_receipt_sha256
       // null),
    cache_backed_detail_readback_count:
      ($readback[0].cache_backed_detail_readback_count // 0),
    cache_backed_index_readback_count:
      ($readback[0].cache_backed_index_readback_count // 0),
    public_api_detail_readback_count: $public_count,
    sitemap_membership_readback_count: $sitemap_count,
    production_write_execution: $operation_receipt[0].production_write_execution,
    operation_write_count: $operation_receipt[0].operation_write_count,
    publication_write_count: 0,
    indexability_write_count: 0,
    cache_write_count: 0,
    queue_dispatch_count: 0,
    sitemap_write_count: 0,
    llms_write_count: 0,
    search_channel_action_count: 0,
    url_submission_count: 0,
    non_target_write_count: 0,
    deploy_count: 0
  }'
