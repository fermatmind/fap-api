#!/usr/bin/env bash

set -Eeuo pipefail

contract_version='seo13.big_five_authority_label_bootstrap.production_ops.v1'
stage='bootstrap'
write_state='none'
release_sha=''
release_name=''

emit_failure() {
    local failed_stage="$1"
    jq -n \
        --arg contract_version "$contract_version" \
        --arg mode "${SEO13_BIGFIVE_AUTHORITY_LABEL_MODE:-unknown}" \
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
            target_count: 2,
            article_metadata_write_count: (
                if $write_state == "committed" then 2
                elif $write_state == "none" then 0
                else null
                end
            ),
            audit_write_count: (
                if $write_state == "committed" then 1
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
mode="${SEO13_BIGFIVE_AUTHORITY_LABEL_MODE:-}"
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
        php artisan articles:seo13-big-five-authority-label-bootstrap \
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
     and .target_count == 2
     and .missing_count == 2
     and .complete_count == 0
     and .repair_required == true
     and .apply_supported == true
     and .readback_complete == false
     and (.state_sha256 | test("^[0-9a-f]{64}$"))
     and (.target_set_sha256 | test("^[0-9a-f]{64}$"))
     and (.rows | length) == 2
     and [.rows[].article_id] == [1, 2]
     and ([.rows[].locale] | all(. == "zh-CN"))
     and ([.rows[].label_state] | all(. == "missing"))
     and .article_metadata_write_count == 0
     and .audit_write_count == 0
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

operator_phrase="I explicitly approve SEO 13 Big Five authority label bootstrap for SHA ${expected_release_sha} release ${expected_release_name} preflight run ${GITHUB_RUN_ID:-0} attempt ${GITHUB_RUN_ATTEMPT:-0} state ${state_sha256} target set ${target_set_sha256}; set article author and reviewer labels for exactly 2 Big Five published articles, and keep article bodies, revisions, publication, indexability, schema, hreflang, revalidation, sitemap eligibility, llms eligibility, search, GSC, URL Inspection, queue, and deploy held."

if [[ "$mode" == 'preflight' ]]; then
    stage='emit_preflight_receipt'
    jq -n \
        --arg contract_version "$contract_version" \
        --arg release_sha "$release_sha" \
        --arg release_name "$release_name" \
        --arg state_sha256 "$state_sha256" \
        --arg target_set_sha256 "$target_set_sha256" \
        --arg operator_phrase "$operator_phrase" \
        '{
            contract_version: $contract_version,
            status: "PASS_PREFLIGHT",
            mode: "preflight",
            release_sha: $release_sha,
            release_name: $release_name,
            state_sha256: $state_sha256,
            target_set_sha256: $target_set_sha256,
            target_count: 2,
            article_ids: [1, 2],
            desired_author_name: "FermatMind Editorial",
            desired_reviewer_name: "Content Review Desk",
            missing_count: 2,
            complete_count: 0,
            production_write_execution: false,
            article_metadata_write_count: 0,
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
            write_state: "none",
            operator_approval_phrase: $operator_phrase
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

command_confirmation="I explicitly approve SEO 13 Big Five authority label bootstrap state ${expected_state_sha256} target set ${expected_target_set_sha256}."

stage='atomic_apply'
write_state='indeterminate'
if ! (
    cd "$current_release/backend"
    php artisan articles:seo13-big-five-authority-label-bootstrap \
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
     and .target_count == 2
     and .missing_count == 0
     and .complete_count == 2
     and .repair_required == false
     and .apply_supported == false
     and .readback_complete == true
     and .article_metadata_write_count == 2
     and .audit_write_count == 1
     and [.rows[].article_id] == [1, 2]
     and ([.rows[].label_state] | all(. == "complete"))
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
     and (.errors | length) == 0' "$apply_file" >/dev/null

stage='emit_apply_receipt'
write_state='committed'
jq -n \
    --arg contract_version "$contract_version" \
    --arg release_sha "$release_sha" \
    --arg release_name "$release_name" \
    --arg state_sha256 "$expected_state_sha256" \
    --arg target_set_sha256 "$expected_target_set_sha256" \
    '{
        contract_version: $contract_version,
        status: "PASS_APPLY",
        mode: "apply",
        release_sha: $release_sha,
        release_name: $release_name,
        state_sha256: $state_sha256,
        target_set_sha256: $target_set_sha256,
        target_count: 2,
        article_ids: [1, 2],
        desired_author_name: "FermatMind Editorial",
        desired_reviewer_name: "Content Review Desk",
        missing_count: 0,
        complete_count: 2,
        production_write_execution: true,
        article_metadata_write_count: 2,
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
