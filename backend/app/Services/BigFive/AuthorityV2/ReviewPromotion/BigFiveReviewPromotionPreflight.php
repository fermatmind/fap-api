<?php

declare(strict_types=1);

namespace App\Services\BigFive\AuthorityV2\ReviewPromotion;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Models\TopicProfile;
use App\Models\TopicProfileRevision;
use App\Services\BigFive\AuthorityV2\ReleaseGate\BigFiveAuthorityV2DraftImportWriter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class BigFiveReviewPromotionPreflight
{
    /** @var list<string> */
    private const EXISTING_ARTICLE_SLUGS = [
        'big-five-conscientiousness-low-procrastination-task-plan',
        'big-five-emotional-stability-stress-recovery-communication',
        'big-five-personality-test-vs-mbti',
        'big-five-growth-guide',
        'big-five-narrative-portrait',
        'big-five-tool-guide',
    ];

    public const ASSET_COUNT = 231;

    public const REVISION_COUNT = 229;

    public const PRODUCT_SHELL_COUNT = 2;

    public const PRIMARY_CREATE_COUNT = 106;

    public const EXISTING_REVISION_COUNT = 125;

    public function __construct(private readonly BigFiveAuthorityV2DraftImportWriter $packageWriter) {}

    /** @return array<string,mixed> */
    public function packageOnly(string $reviewManifestPath, string $authorizationPacketPath, string $rollbackPlanPath): array
    {
        $artifacts = $this->artifacts($reviewManifestPath, $authorizationPacketPath, $rollbackPlanPath);

        return [
            'ok' => true,
            'status' => 'HOLD_FAIL_CLOSED_PENDING_REVIEW_AND_AUTHORIZATION',
            'mode' => 'package_only_zero_write',
            'review_manifest_sha256' => $artifacts['review_sha256'],
            'rollback_plan_sha256' => $artifacts['rollback_sha256'],
            'counts' => [
                'assets' => self::ASSET_COUNT,
                'working_revisions' => self::REVISION_COUNT,
                'product_shells_preserved' => self::PRODUCT_SHELL_COUNT,
                'primary_create' => self::PRIMARY_CREATE_COUNT,
                'existing_revision' => self::EXISTING_REVISION_COUNT,
                'cohorts' => count($artifacts['review']['cohorts']),
                'manually_reviewed' => 0,
                'runtime_bound' => 0,
                'rollback_targets_bound' => 0,
                'promotion_eligible' => 0,
                'cohorts_authorized' => 0,
            ],
            'blockers' => [
                'manual_review_missing',
                'runtime_identity_unbound',
                'source_permission_missing',
                'media_permission_missing',
                'rollback_target_unbound',
                'exact_cohort_authorization_missing',
            ],
            'actions' => $this->zeroActions(0),
        ];
    }

    /** @return array<string,mixed> */
    public function databasePreflight(string $reviewManifestPath, string $authorizationPacketPath, string $rollbackPlanPath): array
    {
        $artifacts = $this->artifacts($reviewManifestPath, $authorizationPacketPath, $rollbackPlanPath);
        $legacy = $this->packageWriter->validatedPlan(
            '../generated/big-five-authority-v2/big5-authority-v2-release-gate-37/draft-import-package.json',
            '../generated/big-five-authority-v2/big5-authority-v2-release-gate-37/production-authorization-packet.json',
        );
        $descriptors = collect($legacy['descriptors'])->keyBy('asset_id');
        $rollbackRows = collect($artifacts['rollback']['rows'])->keyBy('asset_id');
        $assessments = [];
        $issueCodes = [];
        $blockerCodes = [];
        $databaseReads = 0;

        foreach ($artifacts['review']['rows'] as $row) {
            $descriptor = $descriptors->get($row['asset_id']);
            $rollback = $rollbackRows->get($row['asset_id']);
            if (! is_array($descriptor) || ! is_array($rollback)) {
                throw new RuntimeException('Review package identity is not present in the locked authority package: '.$row['asset_id'].'.');
            }
            $assessment = $this->assessRow($row, $descriptor, $rollback);
            $databaseReads += (int) $assessment['database_reads'];
            $issueCodes = [...$issueCodes, ...$assessment['issues']];
            $blockerCodes = [...$blockerCodes, ...$assessment['blockers']];
            $assessments[] = $assessment;
        }

        $runtimeMaterial = array_map(static fn (array $row): array => [
            'asset_id' => $row['asset_id'],
            'primary_id' => $row['observed_runtime']['primary_id'],
            'working_revision_id' => $row['observed_runtime']['working_revision_id'],
            'published_revision_id' => $row['observed_runtime']['published_revision_id'],
            'public_runtime_baseline_sha256' => $row['observed_runtime']['public_runtime_baseline_sha256'],
            'revision_authority_matches' => $row['observed_runtime']['revision_authority_matches'],
        ], $assessments);
        $preflightFingerprint = $this->fingerprint([
            'review_manifest_sha256' => $artifacts['review_sha256'],
            'rollback_plan_sha256' => $artifacts['rollback_sha256'],
            'runtime' => $runtimeMaterial,
        ]);

        $assessmentByAsset = collect($assessments)->keyBy('asset_id');
        $authorizationByCohort = collect($artifacts['authorization']['cohorts'] ?? [])->keyBy('cohort_id');
        $cohorts = [];
        $authorizedCohorts = 0;
        foreach ($artifacts['review']['cohorts'] as $cohort) {
            $membersReady = collect($cohort['asset_ids'])->every(function (string $assetId) use ($assessmentByAsset): bool {
                $assessment = $assessmentByAsset->get($assetId);

                return is_array($assessment) && $assessment['issues'] === [] && $assessment['blockers'] === [];
            });
            $expectedPhrase = null;
            $authorizationMatches = false;
            $deployedSha = $artifacts['authorization']['deployed_sha'] ?? null;
            if (is_string($deployedSha) && preg_match('/^[0-9a-f]{40}$/', $deployedSha) === 1) {
                $expectedPhrase = $this->approvalPhrase(
                    $deployedSha,
                    $artifacts['review_sha256'],
                    $artifacts['rollback_sha256'],
                    $preflightFingerprint,
                    (string) $cohort['cohort_id'],
                    (string) $cohort['cohort_sha256'],
                    (int) $cohort['asset_count'],
                );
                $supplied = $authorizationByCohort->get($cohort['cohort_id']);
                $authorizationMatches = is_array($supplied)
                    && ($supplied['authorized'] ?? false) === true
                    && ($supplied['cohort_sha256'] ?? null) === $cohort['cohort_sha256']
                    && ($supplied['asset_count'] ?? null) === $cohort['asset_count']
                    && ($supplied['exact_authorization'] ?? null) === $expectedPhrase
                    && ($artifacts['authorization']['promotion_preflight_fingerprint'] ?? null) === $preflightFingerprint
                    && ($artifacts['authorization']['production_promotion_currently_authorized'] ?? false) === true;
            }
            $ready = $membersReady && $authorizationMatches;
            if ($ready) {
                $authorizedCohorts++;
            } else {
                $blockerCodes[] = $membersReady ? 'exact_cohort_authorization_missing' : 'cohort_member_not_ready';
            }
            $cohorts[] = [
                'cohort_id' => $cohort['cohort_id'],
                'cohort_sha256' => $cohort['cohort_sha256'],
                'asset_count' => $cohort['asset_count'],
                'members_ready' => $membersReady,
                'authorization_matches' => $authorizationMatches,
                'promotion_preflight_ready' => $ready,
                'exact_authorization_template' => $expectedPhrase,
            ];
        }

        $issueCodes = array_values(array_unique($issueCodes));
        sort($issueCodes);
        $blockerCodes = array_values(array_unique($blockerCodes));
        sort($blockerCodes);
        $runtimeBound = collect($assessments)->where('runtime_bound', true)->count();
        $reviewed = collect($assessments)->where('manual_review_complete', true)->count();
        $rollbackBound = collect($assessments)->where('rollback_target_bound', true)->count();
        $eligible = collect($assessments)->filter(static fn (array $row): bool => $row['issues'] === [] && $row['blockers'] === [] && $row['revision_create'])->count();
        $allReady = $issueCodes === []
            && $blockerCodes === []
            && $eligible === self::REVISION_COUNT
            && $authorizedCohorts === count($cohorts);

        return [
            'ok' => $allReady,
            'status' => $issueCodes !== []
                ? 'FAIL_CLOSED_ABORT_RUNTIME_MISMATCH'
                : ($allReady ? 'PASS_EXACT_COHORT_PROMOTION_PREFLIGHT_ZERO_WRITE' : 'HOLD_FAIL_CLOSED_PENDING_REVIEW_AND_AUTHORIZATION'),
            'mode' => 'database_read_only_zero_write',
            'review_manifest_sha256' => $artifacts['review_sha256'],
            'rollback_plan_sha256' => $artifacts['rollback_sha256'],
            'promotion_preflight_fingerprint' => $preflightFingerprint,
            'counts' => [
                'assets' => self::ASSET_COUNT,
                'working_revisions' => self::REVISION_COUNT,
                'product_shells_preserved' => self::PRODUCT_SHELL_COUNT,
                'manually_reviewed' => $reviewed,
                'runtime_bound' => $runtimeBound,
                'rollback_targets_bound' => $rollbackBound,
                'promotion_eligible' => $eligible,
                'cohorts' => count($cohorts),
                'cohorts_authorized' => $authorizedCohorts,
            ],
            'issue_codes' => $issueCodes,
            'blocker_codes' => $blockerCodes,
            'cohorts' => $cohorts,
            'observed_runtime' => array_map(static fn (array $row): array => [
                'asset_id' => $row['asset_id'],
                ...$row['observed_runtime'],
            ], $assessments),
            'actions' => $this->zeroActions($databaseReads),
        ];
    }

    public function approvalPhrase(
        string $deployedSha,
        string $reviewManifestSha256,
        string $rollbackPlanSha256,
        string $preflightFingerprint,
        string $cohortId,
        string $cohortSha256,
        int $assetCount,
    ): string {
        foreach ([$reviewManifestSha256, $rollbackPlanSha256, $preflightFingerprint, $cohortSha256] as $hash) {
            if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
                throw new RuntimeException('Exact promotion authorization requires lowercase SHA-256 locks.');
            }
        }
        if (preg_match('/^[0-9a-f]{40}$/', $deployedSha) !== 1
            || preg_match('/^[a-z0-9_]+$/', $cohortId) !== 1
            || $assetCount < 1
            || $assetCount > 25) {
            throw new RuntimeException('Exact promotion authorization identity/count is invalid.');
        }

        return sprintf(
            'AUTHORIZE BIG5 AUTHORITY V2 COHORT PROMOTION FOR DEPLOY_SHA=%s REVIEW_MANIFEST_SHA256=%s ROLLBACK_PLAN_SHA256=%s PREFLIGHT_FINGERPRINT=%s COHORT_ID=%s COHORT_SHA256=%s ASSET_COUNT=%d; ABORT_ON_ANY_MISMATCH; WORKING_REVISION_PUBLIC_SELECTION=0; INDEXABILITY=0; SITEMAP=0; LLMS=0; SEARCH=0; CACHE=0',
            $deployedSha,
            $reviewManifestSha256,
            $rollbackPlanSha256,
            $preflightFingerprint,
            $cohortId,
            $cohortSha256,
            $assetCount,
        );
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $descriptor @param array<string,mixed> $rollback @return array<string,mixed> */
    private function assessRow(array $row, array $descriptor, array $rollback): array
    {
        $issues = [];
        $blockers = [];
        $record = $this->record($descriptor);
        $databaseReads = 1;
        $observed = [
            'primary_id' => null,
            'working_revision_id' => null,
            'published_revision_id' => null,
            'public_runtime_baseline_sha256' => null,
            'revision_authority_matches' => false,
            'public_reader_selects_working_revision' => false,
        ];
        if (! $record instanceof Model) {
            $issues[] = 'identity_missing';
        } else {
            $working = $record->getAttribute('working_revision_id');
            $published = $record->getAttribute('published_revision_id');
            $observed = [
                'primary_id' => (int) $record->getKey(),
                'working_revision_id' => $working === null ? null : (int) $working,
                'published_revision_id' => $published === null ? null : (int) $published,
                'public_runtime_baseline_sha256' => $this->recordFingerprint($record),
                'revision_authority_matches' => $row['action_contract']['revision_create']
                    ? $this->revisionAuthorityMatches($record, $row)
                    : true,
                'public_reader_selects_working_revision' => $working !== null && $published !== null && (int) $working === (int) $published,
            ];
            $databaseReads += $row['action_contract']['revision_create'] ? 1 : 0;
            if ($row['action_contract']['revision_create'] && $working === null) {
                $issues[] = 'working_revision_missing';
            }
            if ($row['action_contract']['revision_create'] && ! $observed['revision_authority_matches']) {
                $issues[] = 'working_revision_authority_mismatch';
            }
            if ($row['action_contract']['existing_revision'] && ($published === null || $working === null || (int) $published === (int) $working)) {
                $issues[] = 'existing_public_revision_isolation_mismatch';
            }
            if ($row['action_contract']['primary_create'] && ! $row['action_contract']['product_shell_preserved'] && $published !== null) {
                $issues[] = 'new_identity_already_published';
            }
            if ($observed['public_reader_selects_working_revision']) {
                $issues[] = 'public_reader_selects_working_revision';
            }
        }

        if (($row['expected_runtime']['bound'] ?? false) !== true) {
            $blockers[] = 'runtime_identity_unbound';
        } else {
            foreach (['primary_id', 'working_revision_id', 'published_revision_id', 'public_runtime_baseline_sha256'] as $field) {
                if (! array_key_exists($field, $row['expected_runtime']) || $row['expected_runtime'][$field] !== $observed[$field]) {
                    $issues[] = $field.'_drift';
                }
            }
        }

        $manualReviewComplete = ($row['manual_review']['status'] ?? null) === 'approved'
            && is_int($row['manual_review']['reviewer_id'] ?? null)
            && ($row['manual_review']['reviewer_id'] ?? 0) > 0
            && is_string($row['manual_review']['reviewed_at'] ?? null)
            && strtotime((string) $row['manual_review']['reviewed_at']) !== false
            && preg_match('/^[0-9a-f]{64}$/', (string) ($row['manual_review']['review_record_sha256'] ?? '')) === 1;
        if (! $manualReviewComplete) {
            $blockers[] = 'manual_review_missing';
        }
        foreach (['source', 'media'] as $permission) {
            if (($row['permissions'][$permission]['approved'] ?? false) !== true
                || ! is_string($row['permissions'][$permission]['approval_reference'] ?? null)
                || trim((string) $row['permissions'][$permission]['approval_reference']) === '') {
                $blockers[] = $permission.'_permission_missing';
            }
        }

        $rollbackTargetBound = ($rollback['exact_target_bound'] ?? false) === true
            && ($rollback['primary_id'] ?? null) === $observed['primary_id']
            && ($rollback['restore_published_revision_id'] ?? null) === $observed['published_revision_id']
            && ($rollback['restore_public_runtime_baseline_sha256'] ?? null) === $observed['public_runtime_baseline_sha256'];
        if (! $rollbackTargetBound) {
            $blockers[] = 'rollback_target_unbound';
        }

        $issues = array_values(array_unique($issues));
        sort($issues);
        $blockers = array_values(array_unique($blockers));
        sort($blockers);

        return [
            'asset_id' => $row['asset_id'],
            'revision_create' => (bool) $row['action_contract']['revision_create'],
            'runtime_bound' => $record instanceof Model && ! in_array('runtime_identity_unbound', $blockers, true),
            'manual_review_complete' => $manualReviewComplete,
            'rollback_target_bound' => $rollbackTargetBound,
            'observed_runtime' => $observed,
            'issues' => $issues,
            'blockers' => $blockers,
            'database_reads' => $databaseReads,
        ];
    }

    /** @param array<string,mixed> $descriptor */
    private function record(array $descriptor): ?Model
    {
        $model = $descriptor['model'];
        $query = $model::query()->withoutGlobalScopes();
        if ($model === Article::class) {
            $query->withTrashed();
        }
        foreach ($descriptor['identity'] as $field => $value) {
            $query->where($field, $value);
        }

        return $query->first();
    }

    /** @param array<string,mixed> $row */
    private function revisionAuthorityMatches(Model $record, array $row): bool
    {
        $workingId = $record->getAttribute('working_revision_id');
        if ($workingId === null) {
            return false;
        }

        $revision = match (true) {
            $record instanceof Article => ArticleTranslationRevision::query()->withoutGlobalScopes()->find($workingId),
            $record instanceof ContentPage => CmsTranslationRevision::query()->withoutGlobalScopes()->find($workingId),
            $record instanceof PersonalityPublicContentAsset => PersonalityPublicContentAssetRevision::query()->find($workingId),
            $record instanceof TopicProfile => TopicProfileRevision::query()->find($workingId),
            default => null,
        };
        if (! $revision instanceof Model) {
            return false;
        }

        $assetKey = $revision->getAttribute('authority_asset_key');
        $sourceHash = $revision->getAttribute('authority_source_hash') ?? $revision->getAttribute('source_hash');
        $packageHash = $revision->getAttribute('authority_package_sha256');

        return $assetKey === $row['asset_id']
            && $sourceHash === $row['source_hash']
            && $packageHash === $row['authority_package_sha256'];
    }

    private function recordFingerprint(Model $record): string
    {
        $attributes = $record->getAttributes();
        unset($attributes['working_revision_id']);
        ksort($attributes);

        return $this->fingerprint($attributes);
    }

    /** @return array{review:array<string,mixed>,authorization:array<string,mixed>,rollback:array<string,mixed>,review_sha256:string,rollback_sha256:string} */
    private function artifacts(string $reviewManifestPath, string $authorizationPacketPath, string $rollbackPlanPath): array
    {
        [$review, $reviewSha] = $this->readJson($reviewManifestPath, 'review manifest');
        [$authorization] = $this->readJson($authorizationPacketPath, 'authorization packet');
        [$rollback, $rollbackSha] = $this->readJson($rollbackPlanPath, 'rollback plan');
        if (($review['schema_version'] ?? null) !== 'big5-authority-v2-review-manifest.v1'
            || ($review['counts']['assets'] ?? null) !== self::ASSET_COUNT
            || ($review['counts']['primary_create'] ?? null) !== self::PRIMARY_CREATE_COUNT
            || ($review['counts']['existing_revision'] ?? null) !== self::EXISTING_REVISION_COUNT
            || ($review['counts']['revision_create'] ?? null) !== self::REVISION_COUNT
            || ($review['counts']['product_shell_preserved'] ?? null) !== self::PRODUCT_SHELL_COUNT
            || count($review['rows'] ?? []) !== self::ASSET_COUNT) {
            throw new RuntimeException('Review manifest identity/count contract mismatch.');
        }
        if (($rollback['schema_version'] ?? null) !== 'big5-authority-v2-promotion-rollback-plan.v1'
            || ($rollback['review_manifest_sha256'] ?? null) !== $reviewSha
            || count($rollback['rows'] ?? []) !== self::ASSET_COUNT
            || ($rollback['execution_implemented'] ?? true) !== false) {
            throw new RuntimeException('Rollback plan identity/hold contract mismatch.');
        }
        if (($authorization['schema_version'] ?? null) !== 'big5-authority-v2-cohort-promotion-authorization.v1'
            || ($authorization['review_manifest_sha256'] ?? null) !== $reviewSha
            || ($authorization['rollback_plan_sha256'] ?? null) !== $rollbackSha) {
            throw new RuntimeException('Authorization packet artifact locks mismatch.');
        }
        foreach ($review['source_artifacts'] ?? [] as $source) {
            $path = $this->projectPath((string) ($source['path'] ?? ''));
            if (! File::isFile($path) || ! hash_equals((string) ($source['sha256'] ?? ''), hash_file('sha256', $path))) {
                throw new RuntimeException('Review source artifact drift: '.($source['path'] ?? 'unknown').'.');
            }
        }
        $assetIds = [];
        $rowsByAsset = [];
        $actions = ['primary_create' => 0, 'existing_revision' => 0, 'revision_create' => 0, 'product_shell_preserved' => 0];
        foreach ($review['rows'] as $row) {
            $assetId = (string) ($row['asset_id'] ?? '');
            if ($assetId === '' || isset($assetIds[$assetId])) {
                throw new RuntimeException('Review manifest duplicate or missing asset identity.');
            }
            $assetIds[$assetId] = true;
            $rowsByAsset[$assetId] = $row;
            if (($row['action_contract'] ?? null) !== $this->expectedActionContract($row)) {
                throw new RuntimeException('Review manifest per-identity action classification mismatch: '.$assetId.'.');
            }
            foreach ($actions as $action => $_) {
                if (($row['action_contract'][$action] ?? false) === true) {
                    $actions[$action]++;
                }
            }
            if (($row['source_hash'] ?? null) === null
                || preg_match('/^[0-9a-f]{64}$/', (string) $row['source_hash']) !== 1
                || ($row['authority_package_sha256'] ?? null) !== BigFiveAuthorityV2DraftImportWriter::PACKAGE_SHA256) {
                throw new RuntimeException('Review manifest source/package authority mismatch: '.$assetId.'.');
            }
        }
        if ($actions !== [
            'primary_create' => self::PRIMARY_CREATE_COUNT,
            'existing_revision' => self::EXISTING_REVISION_COUNT,
            'revision_create' => self::REVISION_COUNT,
            'product_shell_preserved' => self::PRODUCT_SHELL_COUNT,
        ]) {
            throw new RuntimeException('Review manifest action classification mismatch.');
        }
        $rollbackAssets = [];
        foreach ($rollback['rows'] as $row) {
            $assetId = (string) ($row['asset_id'] ?? '');
            if ($assetId === '' || isset($rollbackAssets[$assetId]) || ! isset($rowsByAsset[$assetId])) {
                throw new RuntimeException('Rollback plan duplicate, missing, or unknown asset identity.');
            }
            $rollbackAssets[$assetId] = true;
            $actionContract = $rowsByAsset[$assetId]['action_contract'];
            $expectedAction = $actionContract['product_shell_preserved']
                ? 'preserve_product_shell_without_mutation'
                : ($actionContract['existing_revision']
                    ? 'restore_exact_published_revision_and_runtime_baseline'
                    : 'restore_unpublished_draft_and_clear_published_pointer');
            if (($row['action'] ?? null) !== $expectedAction) {
                throw new RuntimeException('Rollback plan action mismatch: '.$assetId.'.');
            }
        }
        if (count($rollbackAssets) !== self::ASSET_COUNT) {
            throw new RuntimeException('Rollback plan identity coverage mismatch.');
        }
        $cohortAssets = [];
        $cohortByAsset = [];
        foreach ($review['cohorts'] ?? [] as $cohort) {
            $ids = $cohort['asset_ids'] ?? [];
            if (! is_array($ids)
                || count($ids) !== ($cohort['asset_count'] ?? -1)
                || count($ids) < 1
                || count($ids) > 25
                || ($cohort['abort_on_any_mismatch'] ?? false) !== true
                || ! hash_equals((string) ($cohort['cohort_sha256'] ?? ''), hash('sha256', json_encode($ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)))) {
                throw new RuntimeException('Review manifest cohort contract mismatch.');
            }
            $cohortAssets = [...$cohortAssets, ...$ids];
            foreach ($ids as $assetId) {
                $cohortByAsset[$assetId] = $cohort['cohort_id'];
            }
        }
        if (count($cohortAssets) !== self::REVISION_COUNT || count(array_unique($cohortAssets)) !== self::REVISION_COUNT) {
            throw new RuntimeException('Review manifest cohort coverage mismatch.');
        }
        foreach ($rowsByAsset as $assetId => $row) {
            $expectedCohort = $row['action_contract']['revision_create'] ? ($cohortByAsset[$assetId] ?? null) : null;
            if (($row['promotion']['cohort_id'] ?? null) !== $expectedCohort) {
                throw new RuntimeException('Review manifest cohort membership mismatch: '.$assetId.'.');
            }
        }

        return compact('review', 'authorization', 'rollback') + [
            'review_sha256' => $reviewSha,
            'rollback_sha256' => $rollbackSha,
        ];
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function readJson(string $path, string $label): array
    {
        $resolved = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        if (! File::isFile($resolved)) {
            throw new RuntimeException(ucfirst($label).' file is missing.');
        }
        $raw = File::get($resolved);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException(ucfirst($label).' must decode to an object.');
        }

        return [$decoded, hash('sha256', $raw)];
    }

    private function projectPath(string $path): string
    {
        if ($path === '' || str_contains($path, '..')) {
            throw new RuntimeException('Review source artifact path is unsafe.');
        }

        return base_path('../'.$path);
    }

    /** @param array<string,mixed> $row @return array<string,bool> */
    private function expectedActionContract(array $row): array
    {
        $route = (string) ($row['route'] ?? '');
        $slug = basename($route);
        $surface = (string) ($row['authority_surface'] ?? '');
        $existing = $surface === 'CMS personality_public_content_assets'
            || $surface === 'CMS topic_profiles'
            || ($surface === 'CMS Article' && in_array($slug, self::EXISTING_ARTICLE_SLUGS, true));
        $shell = $surface === 'CMS landing_surfaces/page_blocks';

        return [
            'primary_create' => ! $existing,
            'existing_revision' => $existing,
            'revision_create' => ! $shell,
            'product_shell_preserved' => $shell,
        ];
    }

    /** @param array<mixed> $value */
    private function fingerprint(array $value): string
    {
        $this->sortRecursive($value);

        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<mixed> $value */
    private function sortRecursive(array &$value): void
    {
        foreach ($value as &$child) {
            if (is_array($child)) {
                $this->sortRecursive($child);
            }
        }
        unset($child);
        if (! array_is_list($value)) {
            ksort($value);
        }
    }

    /** @return array<string,int> */
    private function zeroActions(int $databaseReads): array
    {
        return [
            'database_reads' => $databaseReads,
            'database_writes' => 0,
            'cms_writes' => 0,
            'promotions' => 0,
            'rollbacks' => 0,
            'public_release_changes' => 0,
            'indexability_changes' => 0,
            'sitemap_changes' => 0,
            'llms_changes' => 0,
            'search_submissions' => 0,
            'cache_operations' => 0,
            'deployments' => 0,
        ];
    }
}
