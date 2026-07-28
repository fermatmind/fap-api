#!/usr/bin/env bash

set -Eeuo pipefail

contract_version='seo13.legacy_metadata_bootstrap.production_ops.v1'
stage='bootstrap'
write_state='none'
release_sha=''
release_name=''

emit_failure() {
    local failed_stage="$1"
    jq -n \
        --arg contract_version "$contract_version" \
        --arg mode "${SEO13_LEGACY_METADATA_MODE:-unknown}" \
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
            target_count: 5,
            seo_meta_write_count: (
                if $write_state == "committed" then 5
                elif $write_state == "none" then 0
                else null
                end
            ),
            category_write_count: (
                if $write_state == "committed" then 5
                elif $write_state == "none" then 0
                else null
                end
            ),
            tag_mapping_write_count: (
                if $write_state == "committed" then 21
                elif $write_state == "none" then 0
                else null
                end
            ),
            article_body_write_count: 0,
            revision_write_count: 0,
            publication_write_count: 0,
            indexability_write_count: 0,
            schema_write_count: 0,
            hreflang_write_count: 0,
            revalidation_count: 0,
            sitemap_eligibility_write_count: 0,
            llms_eligibility_write_count: 0,
            search_submission_count: 0,
            queue_dispatch_count: 0,
            gsc_request_count: 0,
            url_inspection_count: 0,
            deploy_count: 0
        }'
}

trap 'status=$?; trap - ERR; emit_failure "$stage"; exit "$status"' ERR

stage='validate_inputs'
mode="${SEO13_LEGACY_METADATA_MODE:-}"
deploy_path="${DEPLOY_PATH:-}"
expected_release_sha="${EXPECTED_RELEASE_SHA:-}"
expected_release_name="${EXPECTED_RELEASE_NAME:-}"
expected_state_sha256="${EXPECTED_STATE_SHA256:-}"
expected_target_set_sha256="${EXPECTED_TARGET_SET_SHA256:-}"
release_sha="$expected_release_sha"
release_name="$expected_release_name"

case "$mode" in
    preflight)
        test -z "$expected_state_sha256"
        test -z "$expected_target_set_sha256"
        ;;
    apply)
        [[ "$expected_state_sha256" =~ ^[0-9a-f]{64}$ ]]
        [[ "$expected_target_set_sha256" =~ ^[0-9a-f]{64}$ ]]
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
actual_revision="$(tr -d '[:space:]' < "$current_release/REVISION")"
test "$actual_revision" = "$expected_release_sha"
test -d "$current_release/backend"

run_preflight() {
    local output_file="$1"
    (
        cd "$current_release/backend"
        php artisan articles:seo13-legacy-metadata-bootstrap \
            --dry-run \
            --json
    ) >"$output_file" 2>/dev/null
}

preflight_file="$(mktemp)"
apply_file="$(mktemp)"
trap 'rm -f "$preflight_file" "$apply_file"' EXIT

stage='command_preflight'
if ! run_preflight "$preflight_file"; then
    stage='command_preflight_rejected'
    exit 1
fi

stage='validate_command_preflight'
jq -e \
    '.ok == true
     and .mode == "preflight"
     and .production_write_execution == false
     and .target_count == 5
     and .missing_count == 5
     and .complete_count == 0
     and .repair_required == true
     and .apply_supported == true
     and .readback_complete == false
     and (.state_sha256 | test("^[0-9a-f]{64}$"))
     and (.target_set_sha256 | test("^[0-9a-f]{64}$"))
     and (.rows | length) == 5
     and [.rows[].article_id] == [5, 6, 7, 9, 10]
     and ([.rows[].locale] | all(. == "zh-CN"))
     and ([.rows[].working_revision_status] | all(. == "approved"))
     and ([.rows[].metadata_state] | all(. == "missing"))
     and .seo_meta_write_count == 0
     and .category_write_count == 0
     and .tag_mapping_write_count == 0
     and .article_body_write_count == 0
     and .revision_write_count == 0
     and .publication_write_count == 0
     and .indexability_write_count == 0
     and .schema_write_count == 0
     and .hreflang_write_count == 0
     and .revalidation_count == 0
     and .sitemap_eligibility_write_count == 0
     and .llms_eligibility_write_count == 0
     and .search_submission_count == 0
     and .queue_dispatch_count == 0
     and .deploy_count == 0
     and (.errors | length) == 0' "$preflight_file" >/dev/null

state_sha256="$(jq -er '.state_sha256' "$preflight_file")"
target_set_sha256="$(jq -er '.target_set_sha256' "$preflight_file")"

if [[ "$mode" == 'preflight' ]]; then
    stage='emit_preflight_receipt'
    jq -n \
        --arg contract_version "$contract_version" \
        --arg release_sha "$release_sha" \
        --arg release_name "$release_name" \
        --arg state_sha256 "$state_sha256" \
        --arg target_set_sha256 "$target_set_sha256" \
        '{
            contract_version: $contract_version,
            status: "PASS_PREFLIGHT",
            mode: "preflight",
            release_sha: $release_sha,
            release_name: $release_name,
            state_sha256: $state_sha256,
            target_set_sha256: $target_set_sha256,
            target_count: 5,
            article_ids: [5, 6, 7, 9, 10],
            missing_count: 5,
            complete_count: 0,
            production_write_execution: false,
            seo_meta_write_count: 0,
            category_write_count: 0,
            tag_mapping_write_count: 0,
            audit_write_count: 0,
            article_body_write_count: 0,
            revision_write_count: 0,
            publication_write_count: 0,
            indexability_write_count: 0,
            schema_write_count: 0,
            hreflang_write_count: 0,
            revalidation_count: 0,
            sitemap_eligibility_write_count: 0,
            llms_eligibility_write_count: 0,
            search_submission_count: 0,
            queue_dispatch_count: 0,
            gsc_request_count: 0,
            url_inspection_count: 0,
            deploy_count: 0,
            write_state: "none"
        }'
    exit 0
fi

stage='bind_apply_state'
test "$state_sha256" = "$expected_state_sha256"
test "$target_set_sha256" = "$expected_target_set_sha256"

stage='revalidate_active_release_before_apply'
latest_current_release="$(readlink -f "$deploy_path/current")"
test "$latest_current_release" = "$current_release"
test "$(tr -d '[:space:]' < "$latest_current_release/REVISION")" = "$expected_release_sha"

command_confirmation="I explicitly approve SEO 13 legacy metadata bootstrap state ${expected_state_sha256} target set ${expected_target_set_sha256}."

stage='atomic_apply'
write_state='indeterminate'
if ! (
    cd "$current_release/backend"
    php artisan articles:seo13-legacy-metadata-bootstrap \
        --execute \
        --expected-state-sha256="$expected_state_sha256" \
        --expected-target-set-sha256="$expected_target_set_sha256" \
        --confirm="$command_confirmation" \
        --json
) >"$apply_file" 2>/dev/null; then
    stage='atomic_apply_rejected'
    exit 1
fi

stage='validate_apply_readback'
jq -e \
    '.ok == true
     and .mode == "apply"
     and .production_write_execution == true
     and .target_count == 5
     and .missing_count == 0
     and .complete_count == 5
     and .repair_required == false
     and .apply_supported == false
     and .readback_complete == true
     and .seo_meta_write_count == 5
     and .category_write_count == 5
     and .tag_mapping_write_count == 21
     and .audit_write_count == 1
     and [.rows[].article_id] == [5, 6, 7, 9, 10]
     and ([.rows[].metadata_state] | all(. == "complete"))
     and .article_body_write_count == 0
     and .revision_write_count == 0
     and .publication_write_count == 0
     and .indexability_write_count == 0
     and .schema_write_count == 0
     and .hreflang_write_count == 0
     and .revalidation_count == 0
     and .sitemap_eligibility_write_count == 0
     and .llms_eligibility_write_count == 0
     and .search_submission_count == 0
     and .queue_dispatch_count == 0
     and .deploy_count == 0
     and (.errors | length) == 0' "$apply_file" >/dev/null
write_state='committed'

stage='emit_apply_receipt'
jq -n \
    --arg contract_version "$contract_version" \
    --arg release_sha "$release_sha" \
    --arg release_name "$release_name" \
    --arg state_sha256 "$expected_state_sha256" \
    --arg target_set_sha256 "$expected_target_set_sha256" \
    --arg after_state_sha256 "$(jq -er '.after_state_sha256' "$apply_file")" \
    '{
        contract_version: $contract_version,
        status: "PASS_APPLY",
        mode: "apply",
        release_sha: $release_sha,
        release_name: $release_name,
        state_sha256: $state_sha256,
        target_set_sha256: $target_set_sha256,
        after_state_sha256: $after_state_sha256,
        target_count: 5,
        article_ids: [5, 6, 7, 9, 10],
        missing_count: 0,
        complete_count: 5,
        production_write_execution: true,
        seo_meta_write_count: 5,
        category_write_count: 5,
        tag_mapping_write_count: 21,
        audit_write_count: 1,
        article_body_write_count: 0,
        revision_write_count: 0,
        publication_write_count: 0,
        indexability_write_count: 0,
        schema_write_count: 0,
        hreflang_write_count: 0,
        revalidation_count: 0,
        sitemap_eligibility_write_count: 0,
        llms_eligibility_write_count: 0,
        search_submission_count: 0,
        queue_dispatch_count: 0,
        gsc_request_count: 0,
        url_inspection_count: 0,
        deploy_count: 0,
        write_state: "committed"
    }'
