#!/usr/bin/env bash

set -Eeuo pipefail

contract_version='seo13.article_atomic_promotion.production_ops.v1'
content_set_sha256='b58959e613d6abdf1123da09811f7c78c87c73f1e26b70ef3d542506d089432e'
target_set_sha256='67ecf80ba9a7ec3fc730bba43242005ffd84c5cedb328b62a1aa2dde2d4f934c'
stage='bootstrap'
release_sha=''
release_name=''
write_state='none'

emit_failure() {
    local failed_stage="$1"
    jq -n \
        --arg contract_version "$contract_version" \
        --arg mode "${SEO13_PROMOTION_MODE:-unknown}" \
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
            publish_count: (
                if $write_state == "committed" then 13
                elif $write_state == "none" then 0
                else null
                end
            ),
            schema_write_count: 0,
            hreflang_write_count: 0,
            search_submission_count: 0,
            revalidation_count: 0,
            sitemap_eligibility_write_count: 0,
            llms_eligibility_write_count: 0,
            queue_dispatch_count: 0,
            gsc_request_count: 0,
            url_inspection_count: 0,
            deploy_count: 0
        }'
}

trap 'status=$?; trap - ERR; emit_failure "$stage"; exit "$status"' ERR

stage='validate_inputs'
mode="${SEO13_PROMOTION_MODE:-}"
deploy_path="${DEPLOY_PATH:-}"
expected_release_sha="${EXPECTED_RELEASE_SHA:-}"
expected_release_name="${EXPECTED_RELEASE_NAME:-}"
expected_state_sha256="${EXPECTED_STATE_SHA256:-}"
expected_revision_set_sha256="${EXPECTED_REVISION_SET_SHA256:-}"
release_sha="$expected_release_sha"
release_name="$expected_release_name"

case "$mode" in
    preflight)
        test -z "$expected_state_sha256"
        test -z "$expected_revision_set_sha256"
        ;;
    apply)
        [[ "$expected_state_sha256" =~ ^[0-9a-f]{64}$ ]]
        [[ "$expected_revision_set_sha256" =~ ^[0-9a-f]{64}$ ]]
        ;;
    *)
        false
        ;;
esac
[[ "$deploy_path" =~ ^/[A-Za-z0-9._/-]+$ ]]
[[ "$deploy_path" != *".."* ]]
[[ "$expected_release_sha" =~ ^[0-9a-f]{40}$ ]]
[[ "$expected_release_name" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ ]]

stage='validate_active_release'
releases_root="$(readlink -f "$deploy_path/releases")"
current_release="$(readlink -f "$deploy_path/current")"
test -d "$releases_root"
case "$current_release" in
    "$releases_root"/*) ;;
    *) false ;;
esac
test -d "$current_release"
actual_release_name="$(basename "$current_release")"
test "$actual_release_name" = "$expected_release_name"
test -f "$current_release/REVISION"
actual_release_sha="$(tr -d '\r\n' < "$current_release/REVISION")"
test "$actual_release_sha" = "$expected_release_sha"
test ! -e "$deploy_path/.dep/deploy.lock"
test -f "$current_release/backend/artisan"
test -f "$current_release/backend/vendor/autoload.php"

stage='run_command_preflight'
cd "$current_release/backend"
set +e
preflight_json="$(
    php artisan articles:promote-existing-working-revision \
        --batch=seo13-20260726 \
        --expected-target-count=13 \
        --dry-run \
        --json \
        --no-interaction \
        --no-ansi \
        2>/dev/null
)"
preflight_status=$?
set -e
test "$preflight_status" -eq 0

stage='validate_command_preflight'
jq -e \
    --arg content "$content_set_sha256" \
    --arg targets "$target_set_sha256" \
    '
        .contract_version == "seo13.article_atomic_promotion.v1"
        and .ok == true
        and .dry_run == true
        and .execute == false
        and .batch == "seo13-20260726"
        and .target_count == 13
        and (.rows | length) == 13
        and ([.rows[].article_id] | unique | length) == 13
        and ([.rows[].working_revision_id] | unique | length) == 13
        and ([.rows[].working_revision_status] | all(. == "approved"))
        and ([.rows[].editorial_completeness.actual_han_characters] | all(. >= 2000))
        and ([.rows[].working_revision_body_hash] | all(test("^[0-9a-f]{64}$")))
        and ([.rows[].working_revision_title_hash] | all(test("^[0-9a-f]{64}$")))
        and ([.rows[].working_revision_excerpt_hash] | all(test("^[0-9a-f]{64}$")))
        and ([.rows[].working_revision_seo_title_hash] | all(test("^[0-9a-f]{64}$")))
        and ([.rows[].working_revision_seo_description_hash] | all(test("^[0-9a-f]{64}$")))
        and ([.rows[].is_public] | all(. == true))
        and ([.rows[].is_indexable] | all(. == true))
        and ([.rows[].sitemap_eligible] | all(. == true))
        and ([.rows[].llms_eligible] | all(. == true))
        and ([.rows[].seo_robots] | all(. == "index,follow"))
        and .content_set_sha256 == $content
        and .target_set_sha256 == $targets
        and (.preflight_state_sha256 | test("^[0-9a-f]{64}$"))
        and (.revision_set_sha256 | test("^[0-9a-f]{64}$"))
        and .production_write_execution == false
        and .publish_count == 0
        and .schema_write_count == 0
        and .hreflang_write_count == 0
        and .search_submission_count == 0
        and .revalidation_count == 0
        and .sitemap_eligibility_write_count == 0
        and .llms_eligibility_write_count == 0
        and .queue_dispatch_count == 0
        and .gsc_request_count == 0
        and .url_inspection_count == 0
        and .deploy_count == 0
    ' <<<"$preflight_json" >/dev/null

state_sha256="$(jq -er '.preflight_state_sha256' <<<"$preflight_json")"
revision_set_sha256="$(jq -er '.revision_set_sha256' <<<"$preflight_json")"

if [ "$mode" = 'preflight' ]; then
    stage='emit_preflight_receipt'
    jq -n \
        --arg contract_version "$contract_version" \
        --arg release_sha "$release_sha" \
        --arg release_name "$release_name" \
        --arg content "$content_set_sha256" \
        --arg targets "$target_set_sha256" \
        --arg state "$state_sha256" \
        --arg revisions "$revision_set_sha256" \
        --argjson rows "$(jq -c '[.rows[] | {
            article_id,
            slug,
            translation_group_id,
            canonical_url,
            working_revision_id,
            current_published_revision_id,
            published_revision_id,
            working_revision_status,
            working_revision_body_hash,
            working_revision_title_hash,
            working_revision_excerpt_hash,
            working_revision_seo_title_hash,
            working_revision_seo_description_hash,
            seo_title_hash,
            seo_description_hash,
            seo_schema_hash,
            seo_robots,
            sitemap_eligible,
            llms_eligible,
            han_character_count: .editorial_completeness.actual_han_characters
        }]' <<<"$preflight_json")" \
        '{
            contract_version: $contract_version,
            status: "PASS_PREFLIGHT",
            mode: "preflight",
            release_sha: $release_sha,
            release_name: $release_name,
            content_set_sha256: $content,
            target_set_sha256: $targets,
            preflight_state_sha256: $state,
            revision_set_sha256: $revisions,
            target_count: 13,
            rows: $rows,
            production_write_execution: false,
            publish_count: 0,
            schema_write_count: 0,
            hreflang_write_count: 0,
            search_submission_count: 0,
            revalidation_count: 0,
            sitemap_eligibility_write_count: 0,
            llms_eligibility_write_count: 0,
            queue_dispatch_count: 0,
            gsc_request_count: 0,
            url_inspection_count: 0,
            deploy_count: 0
        }'
    exit 0
fi

stage='validate_apply_locks'
test "$state_sha256" = "$expected_state_sha256"
test "$revision_set_sha256" = "$expected_revision_set_sha256"
command_confirmation="$(jq -er '.expected_confirmation' <<<"$preflight_json")"

stage='revalidate_active_release_before_apply'
test ! -e "$deploy_path/.dep/deploy.lock"
latest_current_release="$(readlink -f "$deploy_path/current")"
test "$latest_current_release" = "$current_release"
test "$(basename "$latest_current_release")" = "$expected_release_name"
test -f "$latest_current_release/REVISION"
test "$(tr -d '\r\n' < "$latest_current_release/REVISION")" = "$expected_release_sha"

stage='run_command_apply'
write_state='indeterminate'
set +e
apply_json="$(
    php artisan articles:promote-existing-working-revision \
        --batch=seo13-20260726 \
        --expected-target-count=13 \
        --expected-state-sha256="$expected_state_sha256" \
        --expected-revision-set-sha256="$expected_revision_set_sha256" \
        --confirm="$command_confirmation" \
        --preview-approved \
        --schema-hold \
        --hreflang-hold \
        --search-hold \
        --no-revalidation \
        --no-sitemap \
        --no-llms \
        --execute \
        --json \
        --no-interaction \
        --no-ansi \
        2>/dev/null
)"
apply_status=$?
set -e
test "$apply_status" -eq 0
write_state='committed'

stage='validate_apply_readback'
jq -e \
    --arg content "$content_set_sha256" \
    --arg targets "$target_set_sha256" \
    --arg state "$expected_state_sha256" \
    --arg revisions "$expected_revision_set_sha256" \
    '
        .contract_version == "seo13.article_atomic_promotion.v1"
        and .ok == true
        and .dry_run == false
        and .execute == true
        and .target_count == 13
        and (.rows | length) == 13
        and ([.rows[].working_revision_status] | all(. == "published"))
        and ([.rows[] | .published_revision_id == .working_revision_id] | all)
        and .content_set_sha256 == $content
        and .target_set_sha256 == $targets
        and .preflight_state_sha256 == $state
        and .revision_set_sha256 == $revisions
        and .production_write_execution == true
        and .publish_count == 13
        and .schema_write_count == 0
        and .hreflang_write_count == 0
        and .search_submission_count == 0
        and .revalidation_count == 0
        and .sitemap_eligibility_write_count == 0
        and .llms_eligibility_write_count == 0
        and .queue_dispatch_count == 0
        and .gsc_request_count == 0
        and .url_inspection_count == 0
        and .deploy_count == 0
    ' <<<"$apply_json" >/dev/null

stage='emit_apply_receipt'
jq -n \
    --arg contract_version "$contract_version" \
    --arg release_sha "$release_sha" \
    --arg release_name "$release_name" \
    --arg content "$content_set_sha256" \
    --arg targets "$target_set_sha256" \
    --arg state "$expected_state_sha256" \
    --arg revisions "$expected_revision_set_sha256" \
    --argjson rows "$(jq -c '[.rows[] | {
        article_id,
        slug,
        translation_group_id,
        canonical_url,
        working_revision_id,
        current_published_revision_id,
        published_revision_id,
        working_revision_status,
        working_revision_body_hash,
        working_revision_title_hash,
        working_revision_excerpt_hash,
        working_revision_seo_title_hash,
        working_revision_seo_description_hash,
        seo_title_hash,
        seo_description_hash,
        seo_schema_hash,
        seo_robots,
        sitemap_eligible,
        llms_eligible,
        han_character_count: .editorial_completeness.actual_han_characters
    }]' <<<"$apply_json")" \
    '{
        contract_version: $contract_version,
        status: "PASS_APPLY",
        mode: "apply",
        release_sha: $release_sha,
        release_name: $release_name,
        content_set_sha256: $content,
        target_set_sha256: $targets,
        preflight_state_sha256: $state,
        revision_set_sha256: $revisions,
        target_count: 13,
        rows: $rows,
        write_state: "committed",
        production_write_execution: true,
        publish_count: 13,
        schema_write_count: 0,
        hreflang_write_count: 0,
        search_submission_count: 0,
        revalidation_count: 0,
        sitemap_eligibility_write_count: 0,
        llms_eligibility_write_count: 0,
        queue_dispatch_count: 0,
        gsc_request_count: 0,
        url_inspection_count: 0,
        deploy_count: 0
    }'
