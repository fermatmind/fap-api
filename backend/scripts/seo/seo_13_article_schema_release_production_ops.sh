#!/usr/bin/env bash

set -Eeuo pipefail

contract_version='seo13.article_schema_release.production_ops.v1'
stage='bootstrap'
write_state='none'
release_sha=''
release_name=''
failure_diagnostics='{
    "command_error_count": 0,
    "command_error_set_sha256": "",
    "command_error_codes": []
}'

emit_failure() {
    local failed_stage="$1"
    jq -n \
        --arg contract_version "$contract_version" \
        --arg mode "${SEO13_SCHEMA_MODE:-unknown}" \
        --arg release_sha "$release_sha" \
        --arg release_name "$release_name" \
        --arg failed_stage "$failed_stage" \
        --arg write_state "$write_state" \
        --argjson failure_diagnostics "$failure_diagnostics" \
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
            schema_write_count: (
                if $write_state == "committed" then 13
                elif $write_state == "none" then 0
                else null
                end
            ),
            revision_authority_write_count: (
                if $write_state == "committed" then 2
                elif $write_state == "none" then 0
                else null
                end
            ),
            revision_write_count: (
                if $write_state == "committed" then 2
                elif $write_state == "none" then 0
                else null
                end
            ),
            article_body_write_count: 0,
            publication_write_count: 0,
            hreflang_write_count: 0,
            revalidation_count: 0,
            sitemap_eligibility_write_count: 0,
            llms_eligibility_write_count: 0,
            sitemap_cache_refresh_count: 0,
            llms_cache_refresh_count: 0,
            search_submission_count: 0,
            gsc_request_count: 0,
            url_inspection_count: 0,
            queue_dispatch_count: 0,
            deploy_count: 0
        } + $failure_diagnostics'
}

install_error_trap() {
    # shellcheck disable=SC2154
    trap 'exit_code=$?; trap - ERR; emit_failure "$stage"; exit "$exit_code"' ERR
}

install_error_trap

stage='validate_inputs'
mode="${SEO13_SCHEMA_MODE:-}"
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
        php artisan articles:seo13-schema-release \
            --dry-run \
            --json
    ) >"$output_file" 2>/dev/null
}

preflight_file="$(mktemp)"
apply_file="$(mktemp)"
trap 'rm -f "$preflight_file" "$apply_file"' EXIT

stage='command_preflight'
trap - ERR
set +e
run_preflight "$preflight_file"
preflight_status=$?
set -e
install_error_trap
if [ "$preflight_status" -ne 0 ]; then
    if jq -e \
        '.ok == false
         and .mode == "preflight"
         and .production_write_execution == false
         and (.errors | type == "array" and length > 0 and length <= 128)
         and ([.errors[] |
             ((.article_id // 0) | type == "number"),
             (.code | type == "string" and test("^[a-z0-9_]{1,128}$"))
         ] | all)
         and .schema_write_count == 0
         and .revision_authority_write_count == 0
         and .revision_write_count == 0
         and .article_body_write_count == 0
         and .publication_write_count == 0
         and .indexability_write_count == 0
         and .hreflang_write_count == 0
         and .revalidation_count == 0
         and .sitemap_eligibility_write_count == 0
         and .llms_eligibility_write_count == 0
         and .sitemap_cache_refresh_count == 0
         and .llms_cache_refresh_count == 0
         and .search_submission_count == 0
         and .gsc_request_count == 0
         and .url_inspection_count == 0
         and .queue_dispatch_count == 0
         and .deploy_count == 0' "$preflight_file" >/dev/null; then
        safe_error_codes="$(
            jq -c '[
                .errors[] | {
                    article_id: (.article_id // 0),
                    code
                }
            ] | sort_by(.article_id, .code)' "$preflight_file"
        )"
        safe_error_set_sha256="$(
            printf '%s' "$safe_error_codes" | sha256sum | awk '{print $1}'
        )"
        failure_diagnostics="$(
            jq -cn \
                --arg hash "$safe_error_set_sha256" \
                --argjson errors "$safe_error_codes" \
                '{
                    command_error_count: ($errors | length),
                    command_error_set_sha256: $hash,
                    command_error_codes: $errors
                }'
        )"
        stage='command_preflight_rejected'
    fi
    false
fi

stage='validate_command_preflight'
jq -e \
    '.ok == true
     and .mode == "preflight"
     and .production_write_execution == false
     and .target_count == 13
     and .held_count == 13
     and .released_count == 0
     and .apply_supported == true
     and .readback_complete == false
     and [.rows[].article_id] == [1,2,5,6,7,9,10,11,12,13,14,15,16]
     and [.rows[].published_revision_id] == [446,445,444,443,442,441,440,436,437,439,438,434,435]
     and ([.rows[].locale] | all(. == "zh-CN"))
     and ([.rows[].schema_state] | all(. == "held"))
     and ([.rows[] | select(.article_id == 1 or .article_id == 2) | .authority_state] | all(. == "held"))
     and ([.rows[] | select(.article_id != 1 and .article_id != 2) | .authority_state] | all(. == "not_applicable"))
     and ([.rows[] | select(.article_id == 1 or .article_id == 2) | .visible_source_count] | all(. >= 1 and . <= 8))
     and ([.rows[].faq_count] | all(. >= 4 and . <= 8))
     and ([.rows[].planned_json_ld_faq_count] | all(. >= 4 and . <= 8))
     and ([.rows[].planned_json_ld_types] | all(index("Article") != null and index("FAQPage") != null))
     and (.state_sha256 | test("^[0-9a-f]{64}$"))
     and (.content_set_sha256 | test("^[0-9a-f]{64}$"))
     and (.target_set_sha256 | test("^[0-9a-f]{64}$"))
     and .schema_write_count == 0
     and .revision_authority_write_count == 0
     and .revision_write_count == 0
     and .publication_write_count == 0
     and .hreflang_write_count == 0
     and .revalidation_count == 0
     and .sitemap_eligibility_write_count == 0
     and .llms_eligibility_write_count == 0
     and .sitemap_cache_refresh_count == 0
     and .llms_cache_refresh_count == 0
     and .search_submission_count == 0
     and .gsc_request_count == 0
     and .url_inspection_count == 0
     and .queue_dispatch_count == 0
     and .deploy_count == 0
     and (.errors | length) == 0' "$preflight_file" >/dev/null

state_sha256="$(jq -er '.state_sha256' "$preflight_file")"
content_set_sha256="$(jq -er '.content_set_sha256' "$preflight_file")"
target_set_sha256="$(jq -er '.target_set_sha256' "$preflight_file")"
faq_set_sha256="$(jq -c '[.rows[] | {article_id,faq_count,faq_sha256}]' "$preflight_file" | sha256sum | awk '{print $1}')"

if [[ "$mode" == 'preflight' ]]; then
    stage='emit_preflight_receipt'
    jq -n \
        --arg contract_version "$contract_version" \
        --arg release_sha "$release_sha" \
        --arg release_name "$release_name" \
        --arg state_sha256 "$state_sha256" \
        --arg content_set_sha256 "$content_set_sha256" \
        --arg target_set_sha256 "$target_set_sha256" \
        --arg faq_set_sha256 "$faq_set_sha256" \
        '{
            contract_version: $contract_version,
            status: "PASS_PREFLIGHT",
            mode: "preflight",
            release_sha: $release_sha,
            release_name: $release_name,
            state_sha256: $state_sha256,
            content_set_sha256: $content_set_sha256,
            target_set_sha256: $target_set_sha256,
            faq_set_sha256: $faq_set_sha256,
            target_count: 13,
            article_ids: [1,2,5,6,7,9,10,11,12,13,14,15,16],
            published_revision_ids: [446,445,444,443,442,441,440,436,437,439,438,434,435],
            visible_faq_validated_count: 13,
            planned_article_schema_count: 13,
            planned_breadcrumb_schema_count: 13,
            planned_faq_schema_count: 13,
            planned_big_five_authority_count: 2,
            production_write_execution: false,
            schema_write_count: 0,
            revision_authority_write_count: 0,
            revision_write_count: 0,
            article_body_write_count: 0,
            publication_write_count: 0,
            hreflang_write_count: 0,
            revalidation_count: 0,
            sitemap_eligibility_write_count: 0,
            llms_eligibility_write_count: 0,
            sitemap_cache_refresh_count: 0,
            llms_cache_refresh_count: 0,
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

stage='apply_schema_release'
command_confirmation="I explicitly approve SEO 13 atomic schema release state ${expected_state_sha256} content set ${expected_content_set_sha256} target set ${expected_target_set_sha256}."
write_state='indeterminate'
(
    cd "$current_release/backend"
    php artisan articles:seo13-schema-release \
        --execute \
        --expected-state-sha256="$expected_state_sha256" \
        --expected-content-set-sha256="$expected_content_set_sha256" \
        --expected-target-set-sha256="$expected_target_set_sha256" \
        --confirm="$command_confirmation" \
        --no-publish \
        --no-hreflang \
        --no-revalidation \
        --no-sitemap-llms-change \
        --no-search \
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
     and .held_count == 0
     and .released_count == 13
     and .readback_complete == true
     and .schema_write_count == 13
     and .revision_authority_write_count == 2
     and .revision_write_count == 2
     and .article_schema_enabled_count == 13
     and .breadcrumb_schema_enabled_count == 13
     and .faq_schema_enabled_count == 13
     and .audit_write_count == 1
     and [.rows[].article_id] == [1,2,5,6,7,9,10,11,12,13,14,15,16]
     and [.rows[].published_revision_id] == [446,445,444,443,442,441,440,436,437,439,438,434,435]
     and ([.rows[].schema_state] | all(. == "released"))
     and ([.rows[] | select(.article_id == 1 or .article_id == 2) | .authority_state] | all(. == "complete"))
     and ([.rows[].planned_json_ld_types] | all(index("Article") != null and index("FAQPage") != null))
     and .article_body_write_count == 0
     and .publication_write_count == 0
     and .indexability_write_count == 0
     and .hreflang_write_count == 0
     and .revalidation_count == 0
     and .sitemap_eligibility_write_count == 0
     and .llms_eligibility_write_count == 0
     and .sitemap_cache_refresh_count == 0
     and .llms_cache_refresh_count == 0
     and .search_submission_count == 0
     and .gsc_request_count == 0
     and .url_inspection_count == 0
     and .queue_dispatch_count == 0
     and .deploy_count == 0
     and (.errors | length) == 0' "$apply_file" >/dev/null
write_state='committed'
after_state_sha256="$(jq -er '.after_state_sha256' "$apply_file")"

stage='emit_apply_receipt'
jq -n \
    --arg contract_version "$contract_version" \
    --arg release_sha "$release_sha" \
    --arg release_name "$release_name" \
    --arg state_sha256 "$expected_state_sha256" \
    --arg after_state_sha256 "$after_state_sha256" \
    --arg content_set_sha256 "$expected_content_set_sha256" \
    --arg target_set_sha256 "$expected_target_set_sha256" \
    --arg faq_set_sha256 "$faq_set_sha256" \
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
        faq_set_sha256: $faq_set_sha256,
        target_count: 13,
        article_ids: [1,2,5,6,7,9,10,11,12,13,14,15,16],
        published_revision_ids: [446,445,444,443,442,441,440,436,437,439,438,434,435],
        visible_faq_validated_count: 13,
        production_write_execution: true,
        schema_write_count: 13,
        revision_authority_write_count: 2,
        revision_write_count: 2,
        article_body_write_count: 0,
        big_five_visible_authority_bound_count: 2,
        article_schema_enabled_count: 13,
        breadcrumb_schema_enabled_count: 13,
        faq_schema_enabled_count: 13,
        audit_write_count: 1,
        publication_write_count: 0,
        hreflang_write_count: 0,
        revalidation_count: 0,
        sitemap_eligibility_write_count: 0,
        llms_eligibility_write_count: 0,
        sitemap_cache_refresh_count: 0,
        llms_cache_refresh_count: 0,
        search_submission_count: 0,
        gsc_request_count: 0,
        url_inspection_count: 0,
        queue_dispatch_count: 0,
        deploy_count: 0,
        write_state: "committed"
    }'
