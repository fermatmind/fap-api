#!/usr/bin/env bash

set -Eeuo pipefail

readonly PACKAGE_ROOT="docs/seo/import-packages/seo-13-article-refresh-2026-07-26"
readonly COHORT_SHA256="718c7e577f23163df13c0ab08123dfe69badcfda1c0dc7077693ea2b7a11df57"
readonly COHORT_LOCK_FILE_SHA256="212b4b298244ba3ed89a1a999d5ea2019332d33694e67e73093b45f275a56166"
readonly TARGET_SET_SHA256="67ecf80ba9a7ec3fc730bba43242005ffd84c5cedb328b62a1aa2dde2d4f934c"
readonly CONTENT_SET_SHA256="b58959e613d6abdf1123da09811f7c78c87c73f1e26b70ef3d542506d089432e"
readonly REVIEWED_BY_ADMIN_USER_ID="1"

require_env() {
    local name="$1"
    test -n "${!name:-}"
}

fail_receipt() {
    local stage="$1"
    local write_execution=false
    if [ "${SEO13_REVIEW_MODE:-unknown}" = "apply" ]; then
        write_execution=true
    fi
    jq -n \
        --arg mode "${SEO13_REVIEW_MODE:-unknown}" \
        --arg stage "$stage" \
        --arg release_sha "${EXPECTED_RELEASE_SHA:-}" \
        --arg release_name "${EXPECTED_RELEASE_NAME:-}" \
        --argjson write_execution "$write_execution" \
        '{
            contract_version: "seo13.article_review_approval.production_ops.v1",
            status: "FAIL_CLOSED",
            mode: $mode,
            failed_stage: $stage,
            release_sha: $release_sha,
            release_name: $release_name,
            production_write_execution: $write_execution
        }'
    exit 1
}

require_env SEO13_REVIEW_MODE || fail_receipt "missing_mode"
require_env DEPLOY_PATH || fail_receipt "missing_deploy_path"
require_env EXPECTED_RELEASE_SHA || fail_receipt "missing_release_sha"
require_env EXPECTED_RELEASE_NAME || fail_receipt "missing_release_name"

case "$SEO13_REVIEW_MODE" in
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

export SEO13_REVIEW_MODE EXPECTED_STATE_SHA256 CONTENT_SET_SHA256 TARGET_SET_SHA256 REVIEWED_BY_ADMIN_USER_ID

set +e
application_summary="$(
php /dev/stdin <<'PHP'
<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Article;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\ArticleEditorialCompletenessGate;
use App\Services\Cms\ArticleTranslationWorkflowService;
use App\Services\Cms\CmsEditorialReviewAttestationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$fail = static function (string $stage): never {
    echo json_encode([
        'ok' => false,
        'failed_stage' => $stage,
    ], JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(1);
};

try {
    /**
     * @review-surface article
     * @review-surface article_translation_revision
     */
    $backendRoot = (string) getcwd();
    require $backendRoot.'/vendor/autoload.php';
    $app = require $backendRoot.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $mode = (string) getenv('SEO13_REVIEW_MODE');
    $expectedStateSha256 = (string) getenv('EXPECTED_STATE_SHA256');
    $contentSetSha256 = (string) getenv('CONTENT_SET_SHA256');
    $targetSetSha256 = (string) getenv('TARGET_SET_SHA256');
    $adminUserId = (int) getenv('REVIEWED_BY_ADMIN_USER_ID');
    $cohort = json_decode(
        (string) file_get_contents(base_path('docs/seo/import-packages/seo-13-article-refresh-2026-07-26/cohort.lock.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    if ($mode !== 'preflight' && $mode !== 'apply') {
        $fail('invalid_mode');
    }
    if (($cohort['content_set_sha256'] ?? null) !== $contentSetSha256
        || ($cohort['target_set_sha256'] ?? null) !== $targetSetSha256
        || count((array) ($cohort['packages'] ?? [])) !== 13) {
        $fail('cohort_runtime_contract');
    }
    if (! AdminUser::query()->whereKey($adminUserId)->exists()) {
        $fail('reviewer_identity');
    }

    $attestations = app(CmsEditorialReviewAttestationService::class);
    if (! $attestations->isConfiguredSoloOwner($adminUserId)) {
        $fail('review_governance');
    }

    $bodyHash = static fn (string $body): string => hash(
        'sha256',
        preg_replace("/\r\n?/", "\n", trim($body)) ?: trim($body),
    );
    $inspect = static function (bool $afterApproval = false) use (
        $cohort,
        $bodyHash,
        $attestations,
    ): array {
        $rows = [];
        $completenessGate = app(ArticleEditorialCompletenessGate::class);
        $packages = collect($cohort['packages'])->sortBy('article_id')->values();

        foreach ($packages as $package) {
            $articleId = (int) $package['article_id'];
            $article = Article::query()
                ->withoutGlobalScopes()
                ->with(['workingRevision', 'publishedRevision', 'seoMeta'])
                ->findOrFail($articleId);
            $working = $article->workingRevision;
            $published = $article->publishedRevision;
            if (! $working instanceof ArticleTranslationRevision
                || ! $published instanceof ArticleTranslationRevision) {
                throw new RuntimeException('revision_missing');
            }

            $observedBodyHash = $bodyHash((string) $working->content_md);
            $import = ArticleEditorialPackageImport::query()
                ->withoutGlobalScopes()
                ->where('article_id', $articleId)
                ->latest('id')
                ->firstOrFail();
            $expectedRevisionStatus = $afterApproval
                ? ArticleTranslationRevision::STATUS_APPROVED
                : ArticleTranslationRevision::STATUS_HUMAN_REVIEW;
            $completeness = $completenessGate->inspect(
                (string) $article->locale,
                (string) $working->content_md,
                [
                    'working_revision.title' => (string) $working->title,
                    'working_revision.excerpt' => (string) $working->excerpt,
                    'working_revision.content_md' => (string) $working->content_md,
                    'working_revision.seo_title' => (string) $working->seo_title,
                    'working_revision.seo_description' => (string) $working->seo_description,
                ],
            );

            if ((int) $article->org_id !== 0
                || (int) $working->org_id !== 0
                || (int) $published->org_id !== 0
                || (string) $article->slug !== (string) $package['slug']
                || (string) $article->translation_group_id !== (string) $package['translation_group_id']
                || (string) $article->locale !== 'zh-CN'
                || (string) $working->locale !== 'zh-CN'
                || (string) $working->translation_group_id !== (string) $article->translation_group_id
                || (int) $working->article_id !== $articleId
                || (int) $working->id === (int) $published->id
                || (string) $working->revision_status !== $expectedRevisionStatus) {
                throw new RuntimeException('revision_identity');
            }
            if ((string) $article->status !== 'published'
                || ! (bool) $article->is_public
                || ! (bool) $article->is_indexable
                || ! (bool) $article->sitemap_eligible
                || ! (bool) $article->llms_eligible
                || (string) ($article->seoMeta?->robots ?? '') !== 'index,follow'
                || ! (bool) ($article->seoMeta?->is_indexable ?? false)) {
                throw new RuntimeException('public_surface');
            }
            if ((string) $import->content_track !== 'seo_content_package_existing_article_update'
                || ! in_array((string) $import->status, [
                    ArticleEditorialPackageImport::STATUS_IMPORTED,
                    ArticleEditorialPackageImport::STATUS_WARNING,
                ], true)
                || (string) $import->intended_status !== 'working_revision_human_review'
                || (string) data_get($import->validation_summary_json, 'operation') !== 'update_existing_article_working_revision'
                || ! (bool) data_get($import->validation_summary_json, 'schema_hreflang_search_hold')
                || (int) data_get($import->validation_summary_json, 'article_id') !== $articleId
                || (int) data_get($import->validation_summary_json, 'working_revision_id') !== (int) $working->id
                || (int) data_get($import->validation_summary_json, 'published_revision_id') !== (int) $published->id
                || (string) data_get($import->exactness_json, 'status') !== 'passed'
                || (int) data_get($import->exactness_json, 'article_id') !== $articleId
                || (string) data_get($import->exactness_json, 'translation_group_id') !== (string) $article->translation_group_id
                || (string) data_get($import->exactness_json, 'slug') !== (string) $article->slug
                || (string) $import->slug !== (string) $article->slug
                || (string) data_get($import->exactness_json, 'canonical_url') !== (string) ($article->seoMeta?->canonical_url ?? '')
                || ! hash_equals((string) $import->body_hash, $observedBodyHash)) {
                throw new RuntimeException('import_gate');
            }
            if (($completeness['ok'] ?? false) !== true) {
                throw new RuntimeException('editorial_completeness');
            }

            if ($afterApproval
                && ($working->reviewed_by === null
                    || $working->reviewed_at === null
                    || $working->approved_at === null
                    || ! $attestations->hasApprovedEvidence('article', $article)
                    || ! $attestations->hasApprovedEvidence('article_translation_revision', $working))) {
                throw new RuntimeException('approval_readback');
            }

            $rows[] = [
                'article_id' => $articleId,
                'slug' => (string) $article->slug,
                'working_revision_id' => (int) $working->id,
                'published_revision_id' => (int) $published->id,
                'working_revision_status' => (string) $working->revision_status,
                'body_sha256' => $observedBodyHash,
                'han_character_count' => (int) $completeness['actual_han_characters'],
            ];
        }

        return $rows;
    };

    $rows = $inspect();
    $revisionLocks = array_map(static fn (array $row): array => [
        'article_id' => $row['article_id'],
        'slug' => $row['slug'],
        'working_revision_id' => $row['working_revision_id'],
        'published_revision_id' => $row['published_revision_id'],
        'body_sha256' => $row['body_sha256'],
    ], $rows);
    $revisionSetSha256 = hash(
        'sha256',
        json_encode($revisionLocks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    );
    $state = [
        'content_set_sha256' => $contentSetSha256,
        'target_set_sha256' => $targetSetSha256,
        'revision_set_sha256' => $revisionSetSha256,
        'reviewed_by_admin_user_id' => $adminUserId,
        'rows' => $rows,
    ];
    $preflightStateSha256 = hash(
        'sha256',
        json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    );

    if ($mode === 'apply') {
        if (! preg_match('/^[0-9a-f]{64}$/', $expectedStateSha256)
            || ! hash_equals($expectedStateSha256, $preflightStateSha256)) {
            $fail('state_lock');
        }

        DB::transaction(static function () use ($rows, $bodyHash, $adminUserId): void {
            $workflow = app(ArticleTranslationWorkflowService::class);
            foreach ($rows as $row) {
                $article = Article::query()
                    ->withoutGlobalScopes()
                    ->with(['workingRevision', 'publishedRevision', 'seoMeta'])
                    ->lockForUpdate()
                    ->findOrFail((int) $row['article_id']);
                if ((int) $article->working_revision_id !== (int) $row['working_revision_id']
                    || (int) $article->published_revision_id !== (int) $row['published_revision_id']
                    || ! hash_equals((string) $row['body_sha256'], $bodyHash((string) $article->workingRevision?->content_md))) {
                    throw new RuntimeException('transaction_lock');
                }
                $workflow->approveEditorialWorkingRevision($article, $adminUserId);
            }
        });

        $rows = $inspect(true);
    }

    echo json_encode([
        'ok' => true,
        'mode' => $mode,
        'content_set_sha256' => $contentSetSha256,
        'target_set_sha256' => $targetSetSha256,
        'revision_set_sha256' => $revisionSetSha256,
        'preflight_state_sha256' => $preflightStateSha256,
        'reviewed_by_admin_user_id' => $adminUserId,
        'rows' => $rows,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    $allowedStages = [
        'revision_missing',
        'revision_identity',
        'public_surface',
        'import_gate',
        'editorial_completeness',
        'state_lock',
        'transaction_lock',
        'approval_readback',
    ];
    $stage = in_array($exception->getMessage(), $allowedStages, true)
        ? $exception->getMessage()
        : 'application_runtime';
    $fail($stage);
}
PHP
)"
application_status=$?
set -e
if [ "$application_status" -ne 0 ]; then
    application_stage="$(jq -r '.failed_stage // empty' <<<"$application_summary" 2>/dev/null || true)"
    case "$application_stage" in
        invalid_mode|cohort_runtime_contract|reviewer_identity|review_governance|revision_missing|revision_identity|public_surface|import_gate|editorial_completeness|state_lock|transaction_lock|approval_readback|application_runtime)
            fail_receipt "$application_stage"
            ;;
        *)
            fail_receipt "application_review_phase_failed"
            ;;
    esac
fi

jq -e \
    --arg mode "$SEO13_REVIEW_MODE" \
    --arg content "$CONTENT_SET_SHA256" \
    --arg targets "$TARGET_SET_SHA256" \
    --argjson reviewer "$REVIEWED_BY_ADMIN_USER_ID" \
    '.ok == true
     and .mode == $mode
     and .content_set_sha256 == $content
     and .target_set_sha256 == $targets
     and .reviewed_by_admin_user_id == $reviewer
     and (.revision_set_sha256 | test("^[0-9a-f]{64}$"))
     and (.preflight_state_sha256 | test("^[0-9a-f]{64}$"))
     and (.rows | length) == 13
     and ([.rows[].article_id] | unique | length) == 13
     and ([.rows[].working_revision_id] | unique | length) == 13
     and ([.rows[].published_revision_id] | unique | length) == 13
     and ([.rows[].body_sha256 | test("^[0-9a-f]{64}$")] | all)
     and ([.rows[].han_character_count >= 2000] | all)
     and (if $mode == "preflight"
          then [.rows[].working_revision_status == "human_review"] | all
          else [.rows[].working_revision_status == "approved"] | all
          end)' <<<"$application_summary" >/dev/null || fail_receipt "application_summary_mismatch"

jq -n \
    --arg mode "$SEO13_REVIEW_MODE" \
    --arg release_sha "$EXPECTED_RELEASE_SHA" \
    --arg release_name "$EXPECTED_RELEASE_NAME" \
    --arg content "$CONTENT_SET_SHA256" \
    --arg targets "$TARGET_SET_SHA256" \
    --arg revision_set "$(jq -r '.revision_set_sha256' <<<"$application_summary")" \
    --arg state "$(jq -r '.preflight_state_sha256' <<<"$application_summary")" \
    --argjson reviewer "$REVIEWED_BY_ADMIN_USER_ID" \
    --argjson rows "$(jq -c '.rows' <<<"$application_summary")" \
    '{
        contract_version: "seo13.article_review_approval.production_ops.v1",
        status: (if $mode == "preflight" then "PASS_PREFLIGHT" else "PASS_APPLY" end),
        mode: $mode,
        release_sha: $release_sha,
        release_name: $release_name,
        content_set_sha256: $content,
        target_set_sha256: $targets,
        revision_set_sha256: $revision_set,
        preflight_state_sha256: $state,
        reviewed_by_admin_user_id: $reviewer,
        target_count: 13,
        rows: $rows,
        production_write_execution: ($mode == "apply"),
        review_approval_write_count: (if $mode == "apply" then 13 else 0 end),
        publish_count: 0,
        schema_write_count: 0,
        hreflang_write_count: 0,
        search_submission_count: 0,
        revalidation_count: 0,
        sitemap_write_count: 0,
        llms_write_count: 0,
        deploy_count: 0
    }'
