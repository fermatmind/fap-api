#!/usr/bin/env bash
set -Eeuo pipefail

contract_version="career.search_entry_batch.review.production_ops.v1"
mode="${CAREER_REVIEW_MODE:-}"
deploy_path="${DEPLOY_PATH:-}"
expected_release_sha="${EXPECTED_RELEASE_SHA:-}"
expected_release_name="${EXPECTED_RELEASE_NAME:-}"
actor_admin_user_id="${ACTOR_ADMIN_USER_ID:-}"
expected_state_sha256="${EXPECTED_STATE_SHA256:-}"
expected_quality_package_sha256="${EXPECTED_QUALITY_PACKAGE_SHA256:-}"
expected_review_package_sha256="${EXPECTED_REVIEW_PACKAGE_SHA256:-}"
expected_target_set_sha256="${EXPECTED_TARGET_SET_SHA256:-}"
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
      production_write_execution: false,
      review_write_count: 0,
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

[[ "$mode" == "preflight" || "$mode" == "bind" ]]
[[ "$deploy_path" =~ ^/[A-Za-z0-9._/-]+$ ]]
[[ "$deploy_path" != *".."* ]]
[[ "$expected_release_sha" =~ ^[0-9a-f]{40}$ ]]
[[ "$expected_release_name" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ ]]
[[ "$actor_admin_user_id" =~ ^[1-9][0-9]*$ ]]
if [[ "$mode" == "preflight" ]]; then
  test -z "$expected_state_sha256"
  test -z "$expected_quality_package_sha256"
  test -z "$expected_review_package_sha256"
  test -z "$expected_target_set_sha256"
else
  [[ "$expected_state_sha256" =~ ^[0-9a-f]{64}$ ]]
  [[ "$expected_quality_package_sha256" =~ ^[0-9a-f]{64}$ ]]
  [[ "$expected_review_package_sha256" =~ ^[0-9a-f]{64}$ ]]
  [[ "$expected_target_set_sha256" =~ ^[0-9a-f]{64}$ ]]
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
   and .negative_guarantees.deploys == 0' "$build_receipt" >/dev/null

stage="review_gate"
review_receipt="$tmp_dir/review.json"
review_preflight_receipt="$tmp_dir/review-preflight.json"
review_args=(
  career:review-search-entry-quality-batch
  "--expected-package=$package_path"
  "--actor-admin-user-id=$actor_admin_user_id"
  --json
  --no-interaction
  --no-ansi
)
php artisan "${review_args[@]}" > "$review_preflight_receipt"
jq -e \
  '.status == "PASS_REVIEW_PREFLIGHT"
   and .review_write_count == 0
   and .production_write_execution == false
   and .candidate_count == 50
   and .bilingual_url_count == 100
   and .review_target_count == 300
   and .held_slug_count == 0
   and .unknown_target_count == 0
   and .target_drift_count == 0' "$review_preflight_receipt" >/dev/null

if [[ "$mode" == "bind" ]]; then
  stage="exact_preflight_receipt_match"
  jq -e \
    --arg state "$expected_state_sha256" \
    --arg quality "$expected_quality_package_sha256" \
    --arg review "$expected_review_package_sha256" \
    --arg targets "$expected_target_set_sha256" \
    '.preflight_state_sha256 == $state
     and .quality_package_sha256 == $quality
     and .review_package_sha256 == $review
     and .target_set_sha256 == $targets' "$review_preflight_receipt" >/dev/null
  stage="review_bind"
  review_args+=(--bind)
  php artisan "${review_args[@]}" > "$review_receipt"
else
  cp "$review_preflight_receipt" "$review_receipt"
fi
jq -e \
  --arg mode "$mode" \
  '(if $mode == "preflight"
    then .status == "PASS_REVIEW_PREFLIGHT"
         and .review_write_count == 0
         and .production_write_execution == false
    else (.status == "PASS_REVIEW_BOUND" or .status == "PASS_REVIEW_ALREADY_BOUND")
         and (.review_write_count == 301 or .review_write_count == 0)
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
   and .negative_guarantees.non_target_writes == 0' "$review_receipt" >/dev/null

stage="sanitized_receipt"
jq -n \
  --arg contract "$contract_version" \
  --arg mode "$mode" \
  --arg release "$expected_release_sha" \
  --arg release_name "$expected_release_name" \
  --argjson actor "$actor_admin_user_id" \
  --slurpfile review "$review_receipt" \
  '{
    contract_version: $contract,
    status: (if $mode == "preflight" then "PASS_PREFLIGHT" else "PASS_BIND" end),
    mode: $mode,
    release_sha: $release,
    release_name: $release_name,
    actor_admin_user_id: $actor,
    quality_package_sha256: $review[0].quality_package_sha256,
    review_package_sha256: $review[0].review_package_sha256,
    target_set_sha256: $review[0].target_set_sha256,
    candidate_count: $review[0].candidate_count,
    bilingual_url_count: $review[0].bilingual_url_count,
    review_target_count: $review[0].review_target_count,
    preflight_state_sha256: ($review[0].preflight_state_sha256 // null),
    review_state: $review[0].review_state,
    review_evidence_sha256: ($review[0].review_evidence_sha256 // null),
    production_write_execution: $review[0].production_write_execution,
    review_write_count: $review[0].review_write_count,
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
