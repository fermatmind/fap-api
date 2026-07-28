#!/usr/bin/env bash

set -Eeuo pipefail

contract_version='seo13.article_release_closeout.production_ops.v1'
stage='bootstrap'
release_sha=''
release_name=''

emit_failure() {
    local failed_stage="$1"
    jq -n \
        --arg contract_version "$contract_version" \
        --arg release_sha "$release_sha" \
        --arg release_name "$release_name" \
        --arg failed_stage "$failed_stage" \
        '{
            contract_version: $contract_version,
            status: "FAIL_CLOSED",
            mode: "verify_only",
            release_sha: $release_sha,
            release_name: $release_name,
            failed_stage: $failed_stage,
            production_write_execution: false,
            cms_authority_write_count: 0,
            database_authority_write_count: 0,
            publication_write_count: 0,
            schema_write_count: 0,
            hreflang_write_count: 0,
            revalidation_count: 0,
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
deploy_path="${DEPLOY_PATH:-}"
expected_release_sha="${EXPECTED_RELEASE_SHA:-}"
expected_release_name="${EXPECTED_RELEASE_NAME:-}"
release_sha="$expected_release_sha"
release_name="$expected_release_name"

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

closeout_file="$(mktemp)"
api_file="$(mktemp)"
html_file="$(mktemp)"
sitemap_file="$(mktemp)"
llms_file="$(mktemp)"
llms_full_file="$(mktemp)"
trap 'rm -f "$closeout_file" "$api_file" "$html_file" "$sitemap_file" "$llms_file" "$llms_full_file"' EXIT

stage='run_read_only_closeout'
(
    cd "$current_release/backend"
    php artisan articles:seo13-release-closeout --json
) >"$closeout_file" 2>/dev/null

stage='validate_authority_closeout'
jq -e \
    '.ok == true
     and .decision == "SEO13_RELEASE_CLOSEOUT_COMPLETE_MONITORING_PENDING"
     and .target_count == 13
     and .schema_released_count == 13
     and .hreflang_held_count == 13
     and .search_hold.ok == true
     and .search_hold.queue_item_count == 0
     and .search_hold.indexnow_submission_count == 0
     and .search_hold.baidu_submission_count == 0
     and .cannibalization.ok == true
     and [.rows[].article_id] == [1,2,5,6,7,9,10,11,12,13,14,15,16]
     and [.rows[].published_revision_id] == [446,445,444,443,442,441,440,436,437,439,438,434,435]
     and [.rows[].old_published_revision_id] == [341,347,5,6,7,9,10,30,31,32,33,34,35]
     and ([.rows[].old_revision_traceable_and_stale] | all(. == true))
     and ([.rows[].editorial_completeness_ok] | all(. == true))
     and ([.rows[].han_character_count] | all(. >= 2000))
     and ([.rows[].quick_answer_present] | all(. == true))
     and ([.rows[].faq_present] | all(. == true))
     and ([.rows[].references_present] | all(. == true))
     and ([.rows[].markdown_h1_count] | all(. == 1))
     and ([.rows[].private_url_absent] | all(. == true))
     and ([.rows[].hreflang_state] | all(. == "held"))
     and ([.rows[].hreflang_closeout_reason] | all(. == "no_verified_reciprocal_counterpart"))
     and ([.rows[].sitemap_eligible] | all(. == true))
     and ([.rows[].llms_eligible] | all(. == true))
     and ([.rows[].json_ld_types] | all(index("Article") != null and index("FAQPage") != null))
     and .production_write_execution == false
     and .cms_authority_write_count == 0
     and .database_authority_write_count == 0
     and .publication_write_count == 0
     and .schema_write_count == 0
     and .hreflang_write_count == 0
     and .revalidation_count == 0
     and .sitemap_eligibility_write_count == 0
     and .llms_eligibility_write_count == 0
     and .search_submission_count == 0
     and .gsc_request_count == 0
     and .url_inspection_count == 0
     and .queue_dispatch_count == 0
     and .deploy_count == 0
     and (.errors | length) == 0' "$closeout_file" >/dev/null

stage='public_article_readback'
public_api_count=0
public_html_count=0
while IFS=$'\t' read -r article_id slug revision_id canonical title_sha excerpt_sha public_body_sha seo_title_sha seo_description_sha; do
    curl -fsS --connect-timeout 10 --max-time 30 \
        --get \
        --data-urlencode 'locale=zh-CN' \
        "https://api.fermatmind.com/api/v0.5/articles/${slug}" \
        >"$api_file"
    jq -e \
        --argjson article_id "$article_id" \
        --arg slug "$slug" \
        --argjson revision_id "$revision_id" \
        --arg canonical "$canonical" \
        '.ok == true
         and .article.id == $article_id
         and .article.slug == $slug
         and .article.locale == "zh-CN"
         and .article.published_revision_id == $revision_id
         and .article.status == "published"
         and .article.is_public == true
         and .article.is_indexable == true
         and .article.sitemap_eligible == true
         and .article.llms_eligible == true
         and .seo_surface_v1.canonical_url == $canonical
         and .seo_surface_v1.robots_policy == "index,follow"
         and .seo_surface_v1.indexability_state == "indexable"
         and .seo_surface_v1.sitemap_state == "included"
         and .seo_surface_v1.llms_exposure_state == "allow"
         and (.seo_surface_v1.alternates | length) == 0
         and (.seo_surface_v1.structured_data_keys | index("Article")) != null
         and (.seo_surface_v1.structured_data_keys | index("BreadcrumbList")) != null
         and (.seo_surface_v1.structured_data_keys | index("FAQPage")) != null' "$api_file" >/dev/null
    test "$(jq -j '.article.title' "$api_file" | sha256sum | awk '{print $1}')" = "$title_sha"
    test "$(jq -j '.article.excerpt' "$api_file" | sha256sum | awk '{print $1}')" = "$excerpt_sha"
    test "$(jq -j '.article.content_md' "$api_file" | sha256sum | awk '{print $1}')" = "$public_body_sha"
    test "$(jq -j '.article.seo_meta.seo_title' "$api_file" | sha256sum | awk '{print $1}')" = "$seo_title_sha"
    test "$(jq -j '.article.seo_meta.seo_description' "$api_file" | sha256sum | awk '{print $1}')" = "$seo_description_sha"
    public_api_count=$((public_api_count + 1))

    curl -fsS --connect-timeout 10 --max-time 30 "$canonical" >"$html_file"
    grep -Eqi '<h1([[:space:]>])' "$html_file"
    grep -Fq '快速答案' "$html_file"
    grep -Fq '常见问题' "$html_file"
    grep -Fq '参考来源' "$html_file"
    grep -Fq '"Article"' "$html_file"
    grep -Fq '"BreadcrumbList"' "$html_file"
    grep -Fq '"FAQPage"' "$html_file"
    grep -Eqi "<link[^>]+rel=['\"]canonical['\"]" "$html_file"
    grep -Fq "$canonical" "$html_file"
    ! grep -Eqi 'noindex' "$html_file"
    ! grep -Eqi 'hreflang=' "$html_file"
    public_html_count=$((public_html_count + 1))
done < <(
    jq -r '.rows[] | [
        .article_id,
        .slug,
        .published_revision_id,
        .canonical_url,
        .title_sha256,
        .excerpt_sha256,
        .public_body_sha256,
        .seo_title_sha256,
        .seo_description_sha256
    ] | @tsv' "$closeout_file"
)
test "$public_api_count" -eq 13
test "$public_html_count" -eq 13

stage='public_discoverability_readback'
curl -fsS --connect-timeout 10 --max-time 30 'https://fermatmind.com/sitemap.xml' >"$sitemap_file"
curl -fsS --connect-timeout 10 --max-time 30 'https://fermatmind.com/llms.txt' >"$llms_file"
curl -fsS --connect-timeout 10 --max-time 30 'https://fermatmind.com/llms-full.txt' >"$llms_full_file"

public_sitemap_exact_count=0
public_llms_exact_count=0
public_llms_full_exact_count=0
while IFS= read -r canonical; do
    sitemap_count="$(grep -Fo "<loc>${canonical}</loc>" "$sitemap_file" | wc -l | tr -d '[:space:]')"
    llms_count="$(grep -Fxc -- "- ${canonical}" "$llms_file" || true)"
    llms_full_count="$(grep -Fxc -- "- ${canonical}" "$llms_full_file" || true)"
    test "$sitemap_count" -eq 1
    test "$llms_count" -eq 1
    test "$llms_full_count" -eq 1
    public_sitemap_exact_count=$((public_sitemap_exact_count + sitemap_count))
    public_llms_exact_count=$((public_llms_exact_count + llms_count))
    public_llms_full_exact_count=$((public_llms_full_exact_count + llms_full_count))
done < <(jq -r '.rows[].canonical_url' "$closeout_file")

stage='emit_sanitized_closeout'
jq -n \
    --arg contract_version "$contract_version" \
    --arg release_sha "$release_sha" \
    --arg release_name "$release_name" \
    --arg state_sha256 "$(jq -er '.state_sha256' "$closeout_file")" \
    --arg content_set_sha256 "$(jq -er '.content_set_sha256' "$closeout_file")" \
    --arg target_set_sha256 "$(jq -er '.target_set_sha256' "$closeout_file")" \
    --argjson public_api_count "$public_api_count" \
    --argjson public_html_count "$public_html_count" \
    --argjson public_sitemap_exact_count "$public_sitemap_exact_count" \
    --argjson public_llms_exact_count "$public_llms_exact_count" \
    --argjson public_llms_full_exact_count "$public_llms_full_exact_count" \
    '{
        contract_version: $contract_version,
        status: "PASS_CLOSEOUT",
        mode: "verify_only",
        release_sha: $release_sha,
        release_name: $release_name,
        state_sha256: $state_sha256,
        content_set_sha256: $content_set_sha256,
        target_set_sha256: $target_set_sha256,
        target_count: 13,
        article_ids: [1,2,5,6,7,9,10,11,12,13,14,15,16],
        published_revision_ids: [446,445,444,443,442,441,440,436,437,439,438,434,435],
        old_published_revision_ids: [341,347,5,6,7,9,10,30,31,32,33,34,35],
        schema_released_count: 13,
        hreflang_held_count: 13,
        search_hold_verified: true,
        cannibalization_scan_passed: true,
        public_api_readback_count: $public_api_count,
        public_html_readback_count: $public_html_count,
        public_sitemap_exact_count: $public_sitemap_exact_count,
        public_llms_exact_count: $public_llms_exact_count,
        public_llms_full_exact_count: $public_llms_full_exact_count,
        monitoring_windows: ["D1","D7","D14","D28"],
        production_write_execution: false,
        cms_authority_write_count: 0,
        database_authority_write_count: 0,
        publication_write_count: 0,
        schema_write_count: 0,
        hreflang_write_count: 0,
        revalidation_count: 0,
        sitemap_eligibility_write_count: 0,
        llms_eligibility_write_count: 0,
        search_submission_count: 0,
        gsc_request_count: 0,
        url_inspection_count: 0,
        queue_dispatch_count: 0,
        deploy_count: 0,
        write_state: "none"
    }'
