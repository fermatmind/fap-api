#!/usr/bin/env bash

set -Eeuo pipefail

readonly PACKAGE_ROOT="docs/seo/import-packages/seo-13-article-refresh-2026-07-26"
readonly COHORT_SHA256="718c7e577f23163df13c0ab08123dfe69badcfda1c0dc7077693ea2b7a11df57"
readonly COHORT_LOCK_FILE_SHA256="212b4b298244ba3ed89a1a999d5ea2019332d33694e67e73093b45f275a56166"
readonly TARGET_SET_SHA256="67ecf80ba9a7ec3fc730bba43242005ffd84c5cedb328b62a1aa2dde2d4f934c"
readonly CONTENT_SET_SHA256="b58959e613d6abdf1123da09811f7c78c87c73f1e26b70ef3d542506d089432e"

require_env() {
    local name="$1"
    test -n "${!name:-}"
}

fail_receipt() {
    local stage="$1"
    local write_execution=false
    if [ "${SEO13_MODE:-unknown}" = "apply" ]; then
        write_execution=true
    fi
    jq -n \
        --arg mode "${SEO13_MODE:-unknown}" \
        --arg stage "$stage" \
        --arg release_sha "${EXPECTED_RELEASE_SHA:-}" \
        --arg release_name "${EXPECTED_RELEASE_NAME:-}" \
        --argjson write_execution "$write_execution" \
        '{
            contract_version: "seo13.article_draft.production_ops.v1",
            status: "FAIL_CLOSED",
            mode: $mode,
            failed_stage: $stage,
            release_sha: $release_sha,
            release_name: $release_name,
            production_write_execution: $write_execution
        }'
    exit 1
}

require_env SEO13_MODE || fail_receipt "missing_mode"
require_env DEPLOY_PATH || fail_receipt "missing_deploy_path"
require_env EXPECTED_RELEASE_SHA || fail_receipt "missing_release_sha"
require_env EXPECTED_RELEASE_NAME || fail_receipt "missing_release_name"

case "$SEO13_MODE" in
    preflight)
        test -z "${EXPECTED_STATE_SHA256:-}" || fail_receipt "unexpected_state_lock"
        ;;
    apply)
        [[ "${EXPECTED_STATE_SHA256:-}" =~ ^[0-9a-f]{64}$ ]] || fail_receipt "invalid_state_lock"
        ;;
    *)
        fail_receipt "unsupported_mode"
        ;;
esac

current_release="$(readlink -f "$DEPLOY_PATH/current")"
test -d "$current_release" || fail_receipt "active_release_missing"
test "$(basename "$current_release")" = "$EXPECTED_RELEASE_NAME" || fail_receipt "release_name_mismatch"
test -f "$current_release/REVISION" || fail_receipt "revision_file_missing"
test "$(tr -d '\r\n' < "$current_release/REVISION")" = "$EXPECTED_RELEASE_SHA" || fail_receipt "release_sha_mismatch"

cd "$current_release/backend"
test -f artisan || fail_receipt "artisan_missing"
test -r "$PACKAGE_ROOT/cohort.json" || fail_receipt "cohort_missing"
test -r "$PACKAGE_ROOT/cohort.lock.json" || fail_receipt "cohort_lock_missing"
printf '%s  %s\n' "$COHORT_SHA256" "$PACKAGE_ROOT/cohort.json" | sha256sum -c - >/dev/null \
    || fail_receipt "cohort_hash_mismatch"
printf '%s  %s\n' "$COHORT_LOCK_FILE_SHA256" "$PACKAGE_ROOT/cohort.lock.json" | sha256sum -c - >/dev/null \
    || fail_receipt "cohort_lock_hash_mismatch"

jq -e \
    --arg cohort "$COHORT_SHA256" \
    --arg targets "$TARGET_SET_SHA256" \
    --arg content "$CONTENT_SET_SHA256" \
    '.schema_version == "seo_13_article_refresh_cohort_lock.v1"
     and .target_count == 13
     and .cohort_sha256 == $cohort
     and .target_set_sha256 == $targets
     and .content_set_sha256 == $content
     and (.packages | length) == 13' \
    "$PACKAGE_ROOT/cohort.lock.json" >/dev/null || fail_receipt "cohort_contract_mismatch"

run_update() {
    local action_mode="$1"
    local article_id="$2"
    local slug="$3"
    local translation_group_id="$4"

    php artisan articles:update-existing-seo-content-package \
        --package="$PACKAGE_ROOT/$slug" \
        --article-id="$article_id" \
        --translation-group-id="$translation_group_id" \
        --locale=zh-CN \
        --expected-slug="$slug" \
        --expected-canonical="https://fermatmind.com/zh/articles/$slug" \
        "--$action_mode" \
        --json \
        --slug-lock \
        --canonical-lock \
        --schema-hold \
        --hreflang-hold \
        --search-hold \
        --no-revalidation \
        --no-sitemap \
        --no-llms
}

preflight_rows='[]'
while IFS=$'\t' read -r article_id slug translation_group_id observed_revision_id; do
    summary="$(run_update dry-run "$article_id" "$slug" "$translation_group_id")" \
        || fail_receipt "article_preflight_command_failed"

    jq -e \
        --argjson article_id "$article_id" \
        --arg slug "$slug" \
        --arg translation_group_id "$translation_group_id" \
        --argjson observed_revision_id "$observed_revision_id" \
        '.ok == true
         and .dry_run == true
         and .action == "would_update_existing_working_revision"
         and .would_write == true
         and .article_id == $article_id
         and .translation_group_id == $translation_group_id
         and .slug_lock == $slug
         and .active_surface_guard_scan.status == "passed"
         and (.errors | length) == 0
         and (.articles | length) == 1
         and .articles[0].article_id == $article_id
         and .articles[0].slug == $slug
         and .articles[0].working_revision_id == $observed_revision_id
         and .articles[0].published_revision_id == $observed_revision_id
         and .articles[0].working_revision_is_published_revision == true
         and .articles[0].will_create_isolated_working_revision == true
         and .articles[0].status == "published"
         and .articles[0].is_public == true
         and .articles[0].is_indexable == true
         and .articles[0].sitemap_eligible == true
         and .articles[0].llms_eligible == true
         and (.articles[0].body_hash | test("^[0-9a-f]{64}$"))
         and .safety_flags.schema_generation_allowed == false
         and .safety_flags.hreflang_generation_allowed == false
         and .safety_flags.search_submission_allowed == false
         and .safety_flags.revalidation_allowed == false
         and .safety_flags.sitemap_change_allowed == false
         and .safety_flags.llms_change_allowed == false' \
        <<<"$summary" >/dev/null || fail_receipt "article_preflight_contract_failed"

    safe_row="$(jq -c \
        '{
            article_id,
            slug: .slug_lock,
            translation_group_id,
            working_revision_id: .articles[0].working_revision_id,
            published_revision_id: .articles[0].published_revision_id,
            body_hash: .articles[0].body_hash
        }' <<<"$summary")"
    preflight_rows="$(jq -c --argjson row "$safe_row" '. + [$row]' <<<"$preflight_rows")"
done < <(jq -r '.articles[] | [.id, .slug, .translation_group_id, .observed_public_revision_id] | @tsv' "$PACKAGE_ROOT/cohort.json")

test "$(jq 'length' <<<"$preflight_rows")" -eq 13 || fail_receipt "preflight_count_mismatch"
state_sha256="$(jq -cS 'sort_by(.article_id)' <<<"$preflight_rows" | sha256sum | awk '{print $1}')"

if [ "$SEO13_MODE" = "preflight" ]; then
    jq -n \
        --arg release_sha "$EXPECTED_RELEASE_SHA" \
        --arg release_name "$EXPECTED_RELEASE_NAME" \
        --arg cohort_sha "$COHORT_SHA256" \
        --arg lock_sha "$COHORT_LOCK_FILE_SHA256" \
        --arg target_sha "$TARGET_SET_SHA256" \
        --arg content_sha "$CONTENT_SET_SHA256" \
        --arg state_sha "$state_sha256" \
        --argjson rows "$preflight_rows" \
        '{
            contract_version: "seo13.article_draft.production_ops.v1",
            status: "PASS_PREFLIGHT",
            mode: "preflight",
            release_sha: $release_sha,
            release_name: $release_name,
            cohort_sha256: $cohort_sha,
            cohort_lock_file_sha256: $lock_sha,
            target_set_sha256: $target_sha,
            content_set_sha256: $content_sha,
            preflight_state_sha256: $state_sha,
            target_count: 13,
            rows: ($rows | sort_by(.article_id)),
            production_write_execution: false,
            cms_write_count: 0,
            publish_count: 0,
            schema_write_count: 0,
            hreflang_write_count: 0,
            search_submission_count: 0,
            revalidation_count: 0,
            sitemap_write_count: 0,
            llms_write_count: 0
        }'
    exit 0
fi

test "$state_sha256" = "$EXPECTED_STATE_SHA256" || fail_receipt "preflight_state_drift"

write_rows='[]'
while IFS=$'\t' read -r article_id slug translation_group_id observed_revision_id; do
    summary="$(run_update execute "$article_id" "$slug" "$translation_group_id")" \
        || fail_receipt "article_write_command_failed"

    jq -e \
        --argjson article_id "$article_id" \
        --arg slug "$slug" \
        --argjson observed_revision_id "$observed_revision_id" \
        '.ok == true
         and .dry_run == false
         and .action == "updated_existing_working_revision"
         and .would_write == true
         and .article_id == $article_id
         and (.errors | length) == 0
         and (.articles | length) == 1
         and .articles[0].article_id == $article_id
         and .articles[0].slug == $slug
         and .articles[0].published_revision_id == $observed_revision_id
         and .articles[0].working_revision_id != $observed_revision_id
         and .articles[0].created_isolated_working_revision == true
         and .articles[0].working_revision_status == "human_review"
         and .articles[0].status == "published"
         and .articles[0].is_public == true
         and .articles[0].is_indexable == true
         and .articles[0].sitemap_eligible == true
         and .articles[0].llms_eligible == true
         and (.articles[0].body_hash | test("^[0-9a-f]{64}$"))' \
        <<<"$summary" >/dev/null || fail_receipt "article_write_contract_failed"

    safe_row="$(jq -c \
        '{
            article_id,
            slug: .slug_lock,
            working_revision_id: .articles[0].working_revision_id,
            published_revision_id: .articles[0].published_revision_id,
            working_revision_status: .articles[0].working_revision_status,
            body_hash: .articles[0].body_hash
        }' <<<"$summary")"
    write_rows="$(jq -c --argjson row "$safe_row" '. + [$row]' <<<"$write_rows")"
done < <(jq -r '.articles[] | [.id, .slug, .translation_group_id, .observed_public_revision_id] | @tsv' "$PACKAGE_ROOT/cohort.json")

test "$(jq 'length' <<<"$write_rows")" -eq 13 || fail_receipt "write_count_mismatch"
revision_set_sha256="$(jq -cS 'sort_by(.article_id)' <<<"$write_rows" | sha256sum | awk '{print $1}')"

jq -n \
    --arg release_sha "$EXPECTED_RELEASE_SHA" \
    --arg release_name "$EXPECTED_RELEASE_NAME" \
    --arg cohort_sha "$COHORT_SHA256" \
    --arg lock_sha "$COHORT_LOCK_FILE_SHA256" \
    --arg target_sha "$TARGET_SET_SHA256" \
    --arg content_sha "$CONTENT_SET_SHA256" \
    --arg state_sha "$state_sha256" \
    --arg revision_set_sha "$revision_set_sha256" \
    --argjson rows "$write_rows" \
    '{
        contract_version: "seo13.article_draft.production_ops.v1",
        status: "PASS_APPLY",
        mode: "apply",
        release_sha: $release_sha,
        release_name: $release_name,
        cohort_sha256: $cohort_sha,
        cohort_lock_file_sha256: $lock_sha,
        target_set_sha256: $target_sha,
        content_set_sha256: $content_sha,
        preflight_state_sha256: $state_sha,
        revision_set_sha256: $revision_set_sha,
        target_count: 13,
        rows: ($rows | sort_by(.article_id)),
        production_write_execution: true,
        cms_write_count: 13,
        publish_count: 0,
        schema_write_count: 0,
        hreflang_write_count: 0,
        search_submission_count: 0,
        revalidation_count: 0,
        sitemap_write_count: 0,
        llms_write_count: 0
    }'
