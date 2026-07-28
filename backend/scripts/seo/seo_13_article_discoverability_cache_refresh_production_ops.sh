#!/usr/bin/env bash

set -Eeuo pipefail

contract_version='seo13.article_discoverability_cache_refresh.production_ops.v1'
stage='bootstrap'
write_state='none'
release_sha=''
release_name=''

emit_failure() {
    local failed_stage="$1"
    jq -n \
        --arg contract_version "$contract_version" \
        --arg mode "${SEO13_DISCOVERABILITY_MODE:-unknown}" \
        --arg release_sha "$release_sha" \
        --arg release_name "$release_name" \
        --arg failed_stage "$failed_stage" \
        --arg write_state "$write_state" \
        '{
            contract_version: $contract_version,
            status: "FAIL_CLOSED",
            mode: $mode,
            release_sha: $release_sha,
            release_name: $release_name,
            failed_stage: $failed_stage,
            write_state: $write_state,
            production_write_execution: (
                if $write_state == "committed" then true
                elif $write_state == "none" then false
                else null
                end
            ),
            target_count: 13,
            cache_invalidation_count: (
                if $write_state == "committed" then 6
                elif $write_state == "none" then 0
                else null
                end
            ),
            cache_warm_write_count: (
                if $write_state == "committed" then 5
                elif $write_state == "none" then 0
                else null
                end
            ),
            sitemap_cache_refresh_count: (
                if $write_state == "committed" then 4
                elif $write_state == "none" then 0
                else null
                end
            ),
            llms_cache_refresh_count: (
                if $write_state == "committed" then 2
                elif $write_state == "none" then 0
                else null
                end
            ),
            frontend_revalidation_count: (
                if $write_state == "committed" then 3
                elif $write_state == "none" then 0
                else null
                end
            ),
            cms_authority_write_count: 0,
            database_authority_write_count: 0,
            publication_write_count: 0,
            schema_write_count: 0,
            hreflang_write_count: 0,
            sitemap_eligibility_write_count: 0,
            llms_eligibility_write_count: 0,
            search_submission_count: 0,
            gsc_request_count: 0,
            url_inspection_count: 0,
            queue_dispatch_count: 0,
            deploy_count: 0
        }'
}

# shellcheck disable=SC2154
trap 'exit_code=$?; trap - ERR; emit_failure "$stage"; exit "$exit_code"' ERR

stage='validate_inputs'
mode="${SEO13_DISCOVERABILITY_MODE:-}"
deploy_path="${DEPLOY_PATH:-}"
expected_release_sha="${EXPECTED_RELEASE_SHA:-}"
expected_release_name="${EXPECTED_RELEASE_NAME:-}"
expected_state_sha256="${EXPECTED_STATE_SHA256:-}"
expected_content_set_sha256="${EXPECTED_CONTENT_SET_SHA256:-}"
expected_target_set_sha256="${EXPECTED_TARGET_SET_SHA256:-}"
preflight_run_id="${PREFLIGHT_RUN_ID:-}"
preflight_run_attempt="${PREFLIGHT_RUN_ATTEMPT:-}"
release_sha="$expected_release_sha"
release_name="$expected_release_name"

case "$mode" in
    preflight)
        test -z "$expected_state_sha256"
        test -z "$expected_content_set_sha256"
        test -z "$expected_target_set_sha256"
        ;;
    apply)
        [[ "$expected_state_sha256" =~ ^[0-9a-f]{64}$ ]]
        [[ "$expected_content_set_sha256" =~ ^[0-9a-f]{64}$ ]]
        [[ "$expected_target_set_sha256" =~ ^[0-9a-f]{64}$ ]]
        [[ "$preflight_run_id" =~ ^[0-9]+$ ]]
        [[ "$preflight_run_attempt" =~ ^[0-9]+$ ]]
        ;;
    *)
        exit 2
        ;;
esac

[[ "$deploy_path" =~ ^/[A-Za-z0-9._/-]+$ ]]
[[ "$deploy_path" != *".."* ]]
[[ "$expected_release_sha" =~ ^[0-9a-f]{40}$ ]]
[[ "$expected_release_name" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ ]]

stage='validate_active_release'
current_release="$(readlink -f "$deploy_path/current")"
test -n "$current_release"
test "$(basename "$current_release")" = "$expected_release_name"
test -f "$current_release/REVISION"
test "$(tr -d '[:space:]' < "$current_release/REVISION")" = "$expected_release_sha"
test -d "$current_release/backend"

run_preflight() {
    local output_file="$1"
    (
        cd "$current_release/backend"
        php artisan articles:seo13-discoverability-cache-refresh \
            --dry-run \
            --json
    ) >"$output_file" 2>/dev/null
}

preflight_file="$(mktemp)"
apply_file="$(mktemp)"
feed_dir="$(mktemp -d)"
trap 'rm -f "$preflight_file" "$apply_file"; rm -rf "$feed_dir"' EXIT

stage='command_preflight'
run_preflight "$preflight_file"

stage='validate_command_preflight'
jq -e \
    '.ok == true
     and .mode == "preflight"
     and .production_write_execution == false
     and .target_count == 13
     and .schema_released_count == 13
     and .readback_complete == true
     and .apply_supported == true
     and [.rows[].article_id] == [1,2,5,6,7,9,10,11,12,13,14,15,16]
     and [.rows[].published_revision_id] == [446,445,444,443,442,441,440,436,437,439,438,434,435]
     and ([.rows[].schema_state] | all(. == "released"))
     and ([.rows[].sitemap_eligible] | all(. == true))
     and ([.rows[].llms_eligible] | all(. == true))
     and ([.rows[].sitemap_source_count] | all(. == 1))
     and ([.rows[].llms_source_count] | all(. == 1))
     and (.state_sha256 | test("^[0-9a-f]{64}$"))
     and (.content_set_sha256 | test("^[0-9a-f]{64}$"))
     and (.target_set_sha256 | test("^[0-9a-f]{64}$"))
     and .frontend_revalidation_endpoint_count >= 1
     and .frontend_revalidation_token_present == true
     and .frontend_revalidation_token_output == false
     and .cache_invalidation_count == 0
     and .cache_warm_write_count == 0
     and .sitemap_cache_refresh_count == 0
     and .llms_cache_refresh_count == 0
     and .frontend_revalidation_count == 0
     and .cms_authority_write_count == 0
     and .publication_write_count == 0
     and .schema_write_count == 0
     and .hreflang_write_count == 0
     and .sitemap_eligibility_write_count == 0
     and .llms_eligibility_write_count == 0
     and .search_submission_count == 0
     and .gsc_request_count == 0
     and .url_inspection_count == 0
     and .queue_dispatch_count == 0
     and .deploy_count == 0
     and (.errors | length) == 0' "$preflight_file" >/dev/null

state_sha256="$(jq -er '.state_sha256' "$preflight_file")"
content_set_sha256="$(jq -er '.content_set_sha256' "$preflight_file")"
target_set_sha256="$(jq -er '.target_set_sha256' "$preflight_file")"
endpoint_count="$(jq -er '.frontend_revalidation_endpoint_count' "$preflight_file")"

if [[ "$mode" == 'preflight' ]]; then
    stage='emit_preflight_receipt'
    jq -n \
        --arg contract_version "$contract_version" \
        --arg release_sha "$release_sha" \
        --arg release_name "$release_name" \
        --arg state_sha256 "$state_sha256" \
        --arg content_set_sha256 "$content_set_sha256" \
        --arg target_set_sha256 "$target_set_sha256" \
        --argjson endpoint_count "$endpoint_count" \
        '{
            contract_version: $contract_version,
            status: "PASS_PREFLIGHT",
            mode: "preflight",
            release_sha: $release_sha,
            release_name: $release_name,
            state_sha256: $state_sha256,
            content_set_sha256: $content_set_sha256,
            target_set_sha256: $target_set_sha256,
            target_count: 13,
            article_ids: [1,2,5,6,7,9,10,11,12,13,14,15,16],
            published_revision_ids: [446,445,444,443,442,441,440,436,437,439,438,434,435],
            schema_released_count: 13,
            frontend_revalidation_endpoint_count: $endpoint_count,
            production_write_execution: false,
            cache_invalidation_count: 0,
            cache_warm_write_count: 0,
            sitemap_cache_refresh_count: 0,
            llms_cache_refresh_count: 0,
            frontend_revalidation_count: 0,
            cms_authority_write_count: 0,
            database_authority_write_count: 0,
            publication_write_count: 0,
            schema_write_count: 0,
            hreflang_write_count: 0,
            sitemap_eligibility_write_count: 0,
            llms_eligibility_write_count: 0,
            search_submission_count: 0,
            gsc_request_count: 0,
            url_inspection_count: 0,
            queue_dispatch_count: 0,
            deploy_count: 0,
            write_state: "none"
        }'
    exit 0
fi

stage='bind_apply_state'
test "$state_sha256" = "$expected_state_sha256"
test "$content_set_sha256" = "$expected_content_set_sha256"
test "$target_set_sha256" = "$expected_target_set_sha256"

stage='revalidate_active_release_before_apply'
latest_current_release="$(readlink -f "$deploy_path/current")"
test "$latest_current_release" = "$current_release"
test "$(tr -d '[:space:]' < "$latest_current_release/REVISION")" = "$expected_release_sha"

stage='apply_discoverability_cache_refresh'
command_confirmation="I explicitly approve SEO 13 derived discoverability cache refresh state ${expected_state_sha256} content set ${expected_content_set_sha256} target set ${expected_target_set_sha256}."
write_state='indeterminate'
(
    cd "$current_release/backend"
    php artisan articles:seo13-discoverability-cache-refresh \
        --execute \
        --expected-state-sha256="$expected_state_sha256" \
        --expected-content-set-sha256="$expected_content_set_sha256" \
        --expected-target-set-sha256="$expected_target_set_sha256" \
        --confirm="$command_confirmation" \
        --no-authority-change \
        --no-eligibility-change \
        --no-hreflang \
        --no-search \
        --no-deploy \
        --json
) >"$apply_file" 2>/dev/null

stage='validate_apply_receipt'
jq -e \
    --arg content "$expected_content_set_sha256" \
    --arg target "$expected_target_set_sha256" \
    '.ok == true
     and .mode == "apply"
     and .production_write_execution == true
     and .target_count == 13
     and .content_set_sha256 == $content
     and .target_set_sha256 == $target
     and .schema_released_count == 13
     and .cache_invalidation_count == 6
     and .cache_warm_write_count == 5
     and .sitemap_cache_refresh_count == 4
     and .llms_cache_refresh_count == 2
     and .frontend_revalidation_count == 3
     and .cms_authority_write_count == 0
     and .publication_write_count == 0
     and .schema_write_count == 0
     and .hreflang_write_count == 0
     and .sitemap_eligibility_write_count == 0
     and .llms_eligibility_write_count == 0
     and .search_submission_count == 0
     and .gsc_request_count == 0
     and .url_inspection_count == 0
     and .queue_dispatch_count == 0
     and .deploy_count == 0
     and (.errors | length) == 0' "$apply_file" >/dev/null

stage='public_discoverability_readback'
for surface in sitemap.xml llms.txt llms-full.txt; do
    curl --http1.1 -fsS --retry 3 --retry-delay 2 --retry-all-errors \
        --connect-timeout 10 --max-time 60 \
        -H 'Cache-Control: no-cache' \
        "https://fermatmind.com/$surface" >"$feed_dir/$surface"
done

canonical_count=0
while IFS= read -r canonical; do
    for surface in sitemap.xml llms.txt llms-full.txt; do
        test "$(grep -F -o "$canonical" "$feed_dir/$surface" | wc -l | tr -d ' ')" = 1
    done
    canonical_count=$((canonical_count + 1))
done < <(jq -r '.rows[].canonical_url' "$apply_file")
test "$canonical_count" = 13
write_state='committed'

stage='emit_apply_receipt'
jq -n \
    --arg contract_version "$contract_version" \
    --arg release_sha "$release_sha" \
    --arg release_name "$release_name" \
    --arg state_sha256 "$expected_state_sha256" \
    --arg after_state_sha256 "$(jq -er '.after_state_sha256' "$apply_file")" \
    --arg content_set_sha256 "$expected_content_set_sha256" \
    --arg target_set_sha256 "$expected_target_set_sha256" \
    --argjson endpoint_count "$endpoint_count" \
    '{
        contract_version: $contract_version,
        status: "PASS_APPLY",
        mode: "apply",
        release_sha: $release_sha,
        release_name: $release_name,
        state_sha256: $state_sha256,
        after_state_sha256: $after_state_sha256,
        content_set_sha256: $content_set_sha256,
        target_set_sha256: $target_set_sha256,
        target_count: 13,
        article_ids: [1,2,5,6,7,9,10,11,12,13,14,15,16],
        published_revision_ids: [446,445,444,443,442,441,440,436,437,439,438,434,435],
        schema_released_count: 13,
        public_sitemap_exact_count: 13,
        public_llms_exact_count: 13,
        public_llms_full_exact_count: 13,
        frontend_revalidation_endpoint_count: $endpoint_count,
        production_write_execution: true,
        cache_invalidation_count: 6,
        cache_warm_write_count: 5,
        sitemap_cache_refresh_count: 4,
        llms_cache_refresh_count: 2,
        frontend_revalidation_count: 3,
        cms_authority_write_count: 0,
        database_authority_write_count: 0,
        publication_write_count: 0,
        schema_write_count: 0,
        hreflang_write_count: 0,
        sitemap_eligibility_write_count: 0,
        llms_eligibility_write_count: 0,
        search_submission_count: 0,
        gsc_request_count: 0,
        url_inspection_count: 0,
        queue_dispatch_count: 0,
        deploy_count: 0,
        write_state: "committed"
    }'
