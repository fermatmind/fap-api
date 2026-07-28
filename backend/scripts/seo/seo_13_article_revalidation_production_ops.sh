#!/usr/bin/env bash

set -Eeuo pipefail

contract_version='seo13.article_revalidation.production_ops.v1'
stage='bootstrap'
write_state='none'
release_sha=''
release_name=''
article_ids='1,2,5,6,7,9,10,11,12,13,14,15,16'
expected_slugs='big-five-growth-guide,big-five-narrative-portrait,iq-test-growth-guide,iq-test-narrative-portrait,iq-test-tool-guide,mbti-growth-guide,mbti-narrative-portrait,are-infj-men-rare-or-socially-silenced,best-valentines-date-by-personality-and-relationship-science,childhood-dream-job-still-shapes-career-choice,how-16-personality-types-talk-to-an-ai-coach,how-personality-shapes-attitude-toward-ai,which-love-script-fits-you-best'
published_revision_ids='446,445,444,443,442,441,440,436,437,439,438,434,435'

emit_failure() {
    local failed_stage="$1"
    jq -n \
        --arg contract_version "$contract_version" \
        --arg mode "${SEO13_REVALIDATION_MODE:-unknown}" \
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
            revalidation_path_count: (
                if $write_state == "committed" then 14
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
            sitemap_cache_refresh_count: 0,
            llms_cache_refresh_count: 0,
            search_submission_count: 0,
            gsc_request_count: 0,
            url_inspection_count: 0,
            deploy_count: 0
        }'
}

# shellcheck disable=SC2154
trap 'exit_code=$?; trap - ERR; emit_failure "$stage"; exit "$exit_code"' ERR

stage='validate_inputs'
mode="${SEO13_REVALIDATION_MODE:-}"
deploy_path="${DEPLOY_PATH:-}"
expected_release_sha="${EXPECTED_RELEASE_SHA:-}"
expected_release_name="${EXPECTED_RELEASE_NAME:-}"
expected_state_sha256="${EXPECTED_STATE_SHA256:-}"
expected_content_set_sha256="${EXPECTED_CONTENT_SET_SHA256:-}"
preflight_run_id="${PREFLIGHT_RUN_ID:-}"
preflight_run_attempt="${PREFLIGHT_RUN_ATTEMPT:-}"
release_sha="$expected_release_sha"
release_name="$expected_release_name"

case "$mode" in
    preflight)
        test -z "$expected_state_sha256"
        test -z "$expected_content_set_sha256"
        ;;
    apply)
        [[ "$expected_state_sha256" =~ ^[0-9a-f]{64}$ ]]
        [[ "$expected_content_set_sha256" =~ ^[0-9a-f]{64}$ ]]
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
        php artisan content-release:revalidate \
            --type=article-taxonomy \
            --article-ids="$article_ids" \
            --expected-slugs="$expected_slugs" \
            --expected-published-revision-ids="$published_revision_ids" \
            --require-state-lock \
            --include-index=/zh/articles \
            --source=seo13_article_revalidation \
            --dry-run \
            --json
    ) >"$output_file" 2>/dev/null
}

preflight_file="$(mktemp)"
apply_file="$(mktemp)"
trap 'rm -f "$preflight_file" "$apply_file"' EXIT

stage='command_preflight'
run_preflight "$preflight_file"

stage='validate_command_preflight'
jq -e \
    '.ok == true
     and .status == "success"
     and .dry_run == true
     and .type == "article-taxonomy"
     and .state_lock_required == true
     and .article_ids == [1,2,5,6,7,9,10,11,12,13,14,15,16]
     and [.articles[].published_revision_id] == [446,445,444,443,442,441,440,436,437,439,438,434,435]
     and (.articles | length) == 13
     and ([.articles[].locale] | all(. == "zh-CN"))
     and .paths == [
        "/zh/articles",
        "/zh/articles/big-five-growth-guide",
        "/zh/articles/big-five-narrative-portrait",
        "/zh/articles/iq-test-growth-guide",
        "/zh/articles/iq-test-narrative-portrait",
        "/zh/articles/iq-test-tool-guide",
        "/zh/articles/mbti-growth-guide",
        "/zh/articles/mbti-narrative-portrait",
        "/zh/articles/are-infj-men-rare-or-socially-silenced",
        "/zh/articles/best-valentines-date-by-personality-and-relationship-science",
        "/zh/articles/childhood-dream-job-still-shapes-career-choice",
        "/zh/articles/how-16-personality-types-talk-to-an-ai-coach",
        "/zh/articles/how-personality-shapes-attitude-toward-ai",
        "/zh/articles/which-love-script-fits-you-best"
     ]
     and .allowed_path_scope == "taxonomy_only"
     and (.state_sha256 | test("^[0-9a-f]{64}$"))
     and (.content_set_sha256 | test("^[0-9a-f]{64}$"))
     and .endpoint_count >= 1
     and .token_present == true
     and .token_output == false
     and .broadcast_attempted == false
     and .cms_authority_write_count == 0
     and .database_authority_write_count == 0
     and .schema_hreflang_write_attempted == false
     and .sitemap_llms_mutation_attempted == false
     and .search_submission_attempted == false
     and .external_search_submission_attempted == false
     and (.issues | length) == 0' "$preflight_file" >/dev/null

state_sha256="$(jq -er '.state_sha256' "$preflight_file")"
content_set_sha256="$(jq -er '.content_set_sha256' "$preflight_file")"
endpoint_count="$(jq -er '.endpoint_count' "$preflight_file")"

if [[ "$mode" == 'preflight' ]]; then
    stage='emit_preflight_receipt'
    jq -n \
        --arg contract_version "$contract_version" \
        --arg release_sha "$release_sha" \
        --arg release_name "$release_name" \
        --arg state_sha256 "$state_sha256" \
        --arg content_set_sha256 "$content_set_sha256" \
        --argjson endpoint_count "$endpoint_count" \
        '{
            contract_version: $contract_version,
            status: "PASS_PREFLIGHT",
            mode: "preflight",
            release_sha: $release_sha,
            release_name: $release_name,
            state_sha256: $state_sha256,
            content_set_sha256: $content_set_sha256,
            target_count: 13,
            article_ids: [1,2,5,6,7,9,10,11,12,13,14,15,16],
            published_revision_ids: [446,445,444,443,442,441,440,436,437,439,438,434,435],
            endpoint_count: $endpoint_count,
            production_write_execution: false,
            revalidation_path_count: 0,
            cms_authority_write_count: 0,
            database_authority_write_count: 0,
            publication_write_count: 0,
            schema_write_count: 0,
            hreflang_write_count: 0,
            sitemap_eligibility_write_count: 0,
            llms_eligibility_write_count: 0,
            sitemap_cache_refresh_count: 0,
            llms_cache_refresh_count: 0,
            search_submission_count: 0,
            gsc_request_count: 0,
            url_inspection_count: 0,
            deploy_count: 0,
            write_state: "none"
        }'
    exit 0
fi

stage='bind_apply_state'
test "$state_sha256" = "$expected_state_sha256"
test "$content_set_sha256" = "$expected_content_set_sha256"

stage='revalidate_active_release_before_apply'
latest_current_release="$(readlink -f "$deploy_path/current")"
test "$latest_current_release" = "$current_release"
test "$(tr -d '[:space:]' < "$latest_current_release/REVISION")" = "$expected_release_sha"

stage='dispatch_revalidation'
write_state='indeterminate'
(
    cd "$current_release/backend"
    php artisan content-release:revalidate \
        --type=article-taxonomy \
        --article-ids="$article_ids" \
        --expected-slugs="$expected_slugs" \
        --expected-published-revision-ids="$published_revision_ids" \
        --expected-state-sha256="$expected_state_sha256" \
        --expected-content-set-sha256="$expected_content_set_sha256" \
        --require-state-lock \
        --include-index=/zh/articles \
        --source=seo13_article_revalidation \
        --execute \
        --json
) >"$apply_file" 2>/dev/null

stage='validate_apply_receipt'
jq -e \
    --arg state "$expected_state_sha256" \
    --arg content "$expected_content_set_sha256" \
    '.ok == true
     and .status == "success"
     and .dry_run == false
     and .action == "taxonomy_only_revalidation_dispatched"
     and .state_sha256 == $state
     and .content_set_sha256 == $content
     and .article_ids == [1,2,5,6,7,9,10,11,12,13,14,15,16]
     and [.articles[].published_revision_id] == [446,445,444,443,442,441,440,436,437,439,438,434,435]
     and (.paths | length) == 14
     and .broadcast_attempted == false
     and .cms_authority_write_count == 0
     and .database_authority_write_count == 0
     and .schema_hreflang_write_attempted == false
     and .sitemap_llms_mutation_attempted == false
     and .search_submission_attempted == false
     and .external_search_submission_attempted == false
     and (.issues | length) == 0' "$apply_file" >/dev/null
write_state='committed'

stage='emit_apply_receipt'
jq -n \
    --arg contract_version "$contract_version" \
    --arg release_sha "$release_sha" \
    --arg release_name "$release_name" \
    --arg state_sha256 "$expected_state_sha256" \
    --arg content_set_sha256 "$expected_content_set_sha256" \
    --argjson endpoint_count "$endpoint_count" \
    '{
        contract_version: $contract_version,
        status: "PASS_APPLY",
        mode: "apply",
        release_sha: $release_sha,
        release_name: $release_name,
        state_sha256: $state_sha256,
        content_set_sha256: $content_set_sha256,
        target_count: 13,
        article_ids: [1,2,5,6,7,9,10,11,12,13,14,15,16],
        published_revision_ids: [446,445,444,443,442,441,440,436,437,439,438,434,435],
        endpoint_count: $endpoint_count,
        production_write_execution: true,
        revalidation_path_count: 14,
        cms_authority_write_count: 0,
        database_authority_write_count: 0,
        publication_write_count: 0,
        schema_write_count: 0,
        hreflang_write_count: 0,
        sitemap_eligibility_write_count: 0,
        llms_eligibility_write_count: 0,
        sitemap_cache_refresh_count: 0,
        llms_cache_refresh_count: 0,
        search_submission_count: 0,
        gsc_request_count: 0,
        url_inspection_count: 0,
        deploy_count: 0,
        write_state: "committed"
    }'
