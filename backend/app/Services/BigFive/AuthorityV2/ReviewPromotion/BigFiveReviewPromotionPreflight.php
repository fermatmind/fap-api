<?php

declare(strict_types=1);

namespace App\Services\BigFive\AuthorityV2\ReviewPromotion;

use App\Models\Article;
use App\Models\ArticleTestEdge;
use App\Models\ArticleTranslationRevision;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Models\LandingSurface;
use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Models\TopicProfile;
use App\Models\TopicProfileRevision;
use App\Services\BigFive\AuthorityV2\ReleaseGate\BigFiveAuthorityV2DraftImportWriter;
use App\Services\Cms\TopicEntryResolverService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class BigFiveReviewPromotionPreflight
{
    private const DRAFT_IMPORT_PACKAGE_PATH = '../generated/big-five-authority-v2/big5-authority-v2-release-gate-37/draft-import-package.json';

    private const DRAFT_IMPORT_PACKAGE_SHA256 = '80f95a73d497f28a74197b5af7dc1849af35ec9c15958ac898b29b669b997154';

    /** @var list<string> */
    private const SOURCE_ARTIFACT_PATHS = [
        'generated/big-five-authority-v2/big5-authority-v2-release-gate-37/draft-import-package.json',
        'generated/big-five-authority-v2/big5-authority-v2-media-authority-41/mapping-package.json',
        'generated/big-five-authority-v2/big5-authority-v2-visible-date-42/visible-date-findings.json',
        'generated/big-five-authority-v2/big5-authority-v2-visible-provenance-43/visible-provenance-findings.json',
        'generated/big-five-authority-v2/big5-authority-v2-discoverability-parity-44/discoverability-parity-findings.json',
        'generated/big-five-authority-v2/big5-authority-v2-structured-data-45/structured-data-findings.json',
        'generated/big-five-authority-v2/big5-authority-v2-topic-authority-46/topic-draft-revision-package.json',
    ];

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

    public const COHORT_COUNT = 16;

    public function __construct(
        private readonly BigFiveAuthorityV2DraftImportWriter $packageWriter,
        private readonly TopicEntryResolverService $topicEntryResolverService,
    ) {}

    /** @return array<string,mixed> */
    public function packageOnly(string $reviewManifestPath, string $authorizationPacketPath, string $rollbackPlanPath): array
    {
        $artifacts = $this->artifacts($reviewManifestPath, $authorizationPacketPath, $rollbackPlanPath, true);

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
        $artifacts = $this->artifacts($reviewManifestPath, $authorizationPacketPath, $rollbackPlanPath, false);
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
        $deployedSha = $artifacts['authorization']['deployed_sha'] ?? null;
        $authorizationExecutable = $this->authorizationPacketIsExecutable($artifacts['authorization']);
        $deployedRevisionMatches = is_string($deployedSha)
            && preg_match('/^[0-9a-f]{40}$/', $deployedSha) === 1
            && $this->deployedRevisionMatches($deployedSha);
        if (($deployedSha !== null || $authorizationExecutable) && ! $deployedRevisionMatches) {
            $blockerCodes[] = 'authorization_deploy_sha_mismatch';
        }

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
            'primary_publicly_readable' => $row['observed_runtime']['primary_publicly_readable'],
            'public_route_matches' => $row['observed_runtime']['public_route_matches'],
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
            if ($deployedRevisionMatches) {
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
                    && $authorizationExecutable;
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
        $eligible = collect($assessments)->filter(static fn (array $row): bool => $row['issues'] === []
            && $row['blockers'] === []
            && $row['revision_create']
            && $row['promotion_eligible'])->count();
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
            'authorization_deploy_sha_matches_runtime' => $deployedRevisionMatches,
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

    /** @param array<string,mixed> $authorization */
    public function authorizationPacketIsExecutable(array $authorization): bool
    {
        return ($authorization['production_promotion_currently_authorized'] ?? false) === true
            && ($authorization['approval_phrases_currently_executable'] ?? false) === true;
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
            'primary_publicly_readable' => false,
            'primary_record_live' => false,
            'public_route_matches' => false,
        ];
        if (! $record instanceof Model) {
            $issues[] = 'identity_missing';
        } else {
            $working = $record->getAttribute('working_revision_id');
            $published = $record->getAttribute('published_revision_id');
            $primaryPubliclyReadable = $this->primaryPubliclyReadable($record);
            $databaseReads++;
            $databaseReads += match (true) {
                $record instanceof Article => ($published !== null ? 1 : 0) + 4,
                $record instanceof TopicProfile => 3,
                default => 0,
            };
            $primaryRecordLive = ! $record instanceof Article || (! $record->trashed()
                && ! in_array($record->getAttribute('lifecycle_state'), [
                    Article::LIFECYCLE_ARCHIVED,
                    Article::LIFECYCLE_SOFT_DELETED,
                ], true));
            $publicRouteMatches = $this->publicRouteMatchesDescriptor($record, $descriptor);
            $observed = [
                'primary_id' => (int) $record->getKey(),
                'working_revision_id' => $working === null ? null : (int) $working,
                'published_revision_id' => $published === null ? null : (int) $published,
                'public_runtime_baseline_sha256' => $this->recordFingerprint($record),
                'revision_authority_matches' => $row['action_contract']['revision_create']
                    ? $this->revisionAuthorityMatches($record, $row)
                    : true,
                'public_reader_selects_working_revision' => $working !== null && $published !== null && (int) $working === (int) $published,
                'primary_publicly_readable' => $primaryPubliclyReadable,
                'primary_record_live' => $primaryRecordLive,
                'public_route_matches' => $publicRouteMatches,
            ];
            $databaseReads += $row['action_contract']['revision_create'] ? 1 : 0;
            if ($row['action_contract']['revision_create'] && $working === null) {
                $issues[] = 'working_revision_missing';
            }
            if ($row['action_contract']['revision_create'] && ! $observed['revision_authority_matches']) {
                $issues[] = 'working_revision_authority_mismatch';
            }
            if (! $primaryRecordLive) {
                $issues[] = 'article_identity_not_live';
            }
            if (! $publicRouteMatches) {
                $issues[] = 'public_route_mismatch';
            }
            if ($row['action_contract']['existing_revision'] && ($published === null || $working === null || (int) $published === (int) $working)) {
                $issues[] = 'existing_public_revision_isolation_mismatch';
            }
            if ($row['action_contract']['existing_revision'] && ! $primaryPubliclyReadable) {
                $issues[] = 'existing_identity_not_publicly_readable';
            }
            if ($row['action_contract']['primary_create'] && ! $row['action_contract']['product_shell_preserved'] && $published !== null) {
                $issues[] = 'new_identity_already_published';
            }
            if ($row['action_contract']['primary_create'] && $primaryPubliclyReadable) {
                $issues[] = 'new_identity_already_publicly_readable';
            }
            if ($row['action_contract']['primary_create']
                && ! $primaryPubliclyReadable
                && $this->primaryHasPublicOrScheduledState($record)) {
                $issues[] = 'new_identity_publication_state_present';
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
        $promotionEligible = ($row['promotion']['eligible'] ?? false) === true;
        if ($row['action_contract']['revision_create'] && ! $promotionEligible) {
            $blockers[] = 'row_promotion_eligibility_missing';
        }
        if ($row['action_contract']['product_shell_preserved'] && $promotionEligible) {
            $blockers[] = 'product_shell_promotion_eligibility_forbidden';
        }

        $issues = array_values(array_unique($issues));
        sort($issues);
        $blockers = array_values(array_unique($blockers));
        sort($blockers);

        return [
            'asset_id' => $row['asset_id'],
            'revision_create' => (bool) $row['action_contract']['revision_create'],
            'promotion_eligible' => $promotionEligible,
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

        return $this->revisionOwnedByRecord($revision, $record)
            && $assetKey === $row['asset_id']
            && $sourceHash === $row['source_hash']
            && $packageHash === $row['authority_package_sha256'];
    }

    private function revisionOwnedByRecord(Model $revision, Model $record): bool
    {
        $recordId = (int) $record->getKey();

        return match (true) {
            $record instanceof Article => (int) $revision->getAttribute('article_id') === $recordId
                && (int) $revision->getAttribute('org_id') === (int) $record->getAttribute('org_id')
                && $revision->getAttribute('locale') === $record->getAttribute('locale'),
            $record instanceof ContentPage => $revision->getAttribute('content_type') === 'content_page'
                && (int) $revision->getAttribute('content_id') === $recordId
                && (int) $revision->getAttribute('org_id') === (int) $record->getAttribute('org_id')
                && $revision->getAttribute('locale') === $record->getAttribute('locale'),
            $record instanceof PersonalityPublicContentAsset => (int) $revision->getAttribute('asset_id') === $recordId,
            $record instanceof TopicProfile => (int) $revision->getAttribute('profile_id') === $recordId,
            default => false,
        };
    }

    /** @param array<string,mixed> $descriptor */
    private function publicRouteMatchesDescriptor(Model $record, array $descriptor): bool
    {
        $attributes = $descriptor['attributes'] ?? [];
        if (! is_array($attributes)) {
            return false;
        }

        return match (true) {
            $record instanceof ContentPage => $record->getAttribute('path') === ($attributes['path'] ?? null)
                && $record->getAttribute('canonical_path') === ($attributes['canonical_path'] ?? null),
            $record instanceof PersonalityPublicContentAsset => $record->getAttribute('slug') === ($attributes['slug'] ?? null)
                && $this->canonicalPath($record->getAttribute('canonical_json')) === $this->canonicalPath($attributes['canonical_json'] ?? null),
            $record instanceof TopicProfile => $record->getAttribute('slug') === ($attributes['slug'] ?? null),
            default => true,
        };
    }

    private function canonicalPath(mixed $canonical): ?string
    {
        return is_array($canonical) && is_string($canonical['path'] ?? null)
            ? $canonical['path']
            : null;
    }

    private function primaryPubliclyReadable(Model $record): bool
    {
        $recordId = $record->getKey();

        return match (true) {
            $record instanceof Article => Article::query()
                ->withoutGlobalScopes()
                ->whereKey($recordId)
                ->publiclyReadable()
                ->exists(),
            $record instanceof ContentPage => ContentPage::query()
                ->withoutGlobalScopes()
                ->whereKey($recordId)
                ->publiclyReadable()
                ->exists(),
            $record instanceof LandingSurface => LandingSurface::query()
                ->withoutGlobalScopes()
                ->whereKey($recordId)
                ->publishedPublic()
                ->exists(),
            $record instanceof PersonalityPublicContentAsset => PersonalityPublicContentAsset::query()
                ->withoutGlobalScopes()
                ->whereKey($recordId)
                ->publiclyReadable()
                ->exists(),
            $record instanceof TopicProfile => TopicProfile::query()
                ->withoutGlobalScopes()
                ->whereKey($recordId)
                ->publishedPublic()
                ->where(static function (Builder $query): void {
                    $query->whereNull('published_at')
                        ->orWhere('published_at', '<=', now());
                })
                ->exists(),
            default => false,
        };
    }

    private function primaryHasPublicOrScheduledState(Model $record): bool
    {
        return match (true) {
            $record instanceof Article => $record->getAttribute('status') === 'published'
                || (bool) $record->getAttribute('is_public')
                || $record->getAttribute('published_at') !== null
                || $record->getAttribute('scheduled_at') !== null,
            $record instanceof ContentPage => in_array($record->getAttribute('status'), [ContentPage::STATUS_SCHEDULED, ContentPage::STATUS_PUBLISHED], true)
                || (bool) $record->getAttribute('is_public')
                || $record->getAttribute('published_at') !== null,
            $record instanceof LandingSurface => $record->getAttribute('status') === LandingSurface::STATUS_PUBLISHED
                || (bool) $record->getAttribute('is_public')
                || $record->getAttribute('published_at') !== null
                || $record->getAttribute('scheduled_at') !== null,
            $record instanceof PersonalityPublicContentAsset => $record->getAttribute('launch_state') === PersonalityPublicContentAsset::LAUNCH_PUBLISHED
                || (bool) $record->getAttribute('is_public')
                || $record->getAttribute('published_at') !== null,
            $record instanceof TopicProfile => $record->getAttribute('status') === TopicProfile::STATUS_PUBLISHED
                || (bool) $record->getAttribute('is_public')
                || $record->getAttribute('published_at') !== null
                || $record->getAttribute('scheduled_at') !== null,
            default => false,
        };
    }

    private function deployedRevisionMatches(string $deployedSha): bool
    {
        $revisionPath = base_path('../REVISION');

        return File::isFile($revisionPath)
            && hash_equals($deployedSha, trim(File::get($revisionPath)));
    }

    private function recordFingerprint(Model $record): string
    {
        $attributes = $record->getAttributes();
        unset($attributes['working_revision_id']);
        ksort($attributes);

        if (! $record instanceof Article && ! $record instanceof TopicProfile) {
            return $this->fingerprint($attributes);
        }

        if ($record instanceof TopicProfile) {
            return $this->fingerprint([
                'primary' => $attributes,
                'public_relations' => $this->topicPublicRelationsSnapshot($record),
            ]);
        }

        $publishedRevisionId = $record->getAttribute('published_revision_id');
        $publishedRevision = $publishedRevisionId === null
            ? null
            : ArticleTranslationRevision::query()->withoutGlobalScopes()->find($publishedRevisionId);

        return $this->fingerprint([
            'primary' => $attributes,
            'published_revision' => $this->modelSnapshot($publishedRevision),
            'public_relations' => $this->articlePublicRelationsSnapshot($record),
        ]);
    }

    /** @return array<string,mixed> */
    private function articlePublicRelationsSnapshot(Article $article): array
    {
        $category = $article->category()->withoutGlobalScopes()->first();
        if ($category instanceof Model && (int) $category->getAttribute('org_id') !== (int) $article->getAttribute('org_id')) {
            $category = null;
        }
        $tags = $article->tags()
            ->withoutGlobalScopes()
            ->get()
            ->filter(static fn (Model $tag): bool => (int) $tag->getAttribute('org_id') === (int) $article->getAttribute('org_id'));
        $testEdges = $article->testEdges()
            ->withoutGlobalScopes()
            ->where('org_id', $article->getAttribute('org_id'))
            ->where('locale', $article->getAttribute('locale'))
            ->where('visibility', ArticleTestEdge::VISIBILITY_PUBLIC)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return [
            'category' => $this->modelSnapshot($category),
            'tags' => $this->modelSnapshots($tags),
            'test_edges' => $this->modelSnapshots($testEdges, preserveOrder: true),
            'seo_meta' => $this->modelSnapshot($article->seoMeta()->withoutGlobalScopes()->first()),
        ];
    }

    /** @return array<string,mixed> */
    private function topicPublicRelationsSnapshot(TopicProfile $profile): array
    {
        $sections = $profile->sections()
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $entries = $profile->entries()
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $profile->setRelation('entries', $entries);

        return [
            'sections' => $this->modelSnapshots($sections, preserveOrder: true),
            'entries' => $this->modelSnapshots($entries, preserveOrder: true),
            'resolved_entry_groups' => $this->topicEntryResolverService->resolveGroupedEntries($profile, (string) $profile->getAttribute('locale')),
            'seo_meta' => $this->modelSnapshot($profile->seoMeta()->first()),
        ];
    }

    /** @return array<string,mixed>|null */
    private function modelSnapshot(?Model $model): ?array
    {
        return $model?->toArray();
    }

    /** @param iterable<int,Model> $models @return list<array<string,mixed>> */
    private function modelSnapshots(iterable $models, bool $preserveOrder = false): array
    {
        $snapshots = collect($models);
        if (! $preserveOrder) {
            $snapshots = $snapshots->sortBy(static fn (Model $model): int => (int) $model->getKey());
        }

        return $snapshots
            ->map(static fn (Model $model): array => $model->toArray())
            ->values()
            ->all();
    }

    /** @return array{review:array<string,mixed>,authorization:array<string,mixed>,rollback:array<string,mixed>,review_sha256:string,rollback_sha256:string} */
    private function artifacts(
        string $reviewManifestPath,
        string $authorizationPacketPath,
        string $rollbackPlanPath,
        bool $requirePendingAuthorization,
    ): array {
        [$review, $reviewSha] = $this->readJson($reviewManifestPath, 'review manifest');
        [$authorization] = $this->readJson($authorizationPacketPath, 'authorization packet');
        [$rollback, $rollbackSha] = $this->readJson($rollbackPlanPath, 'rollback plan');
        if (($review['schema_version'] ?? null) !== 'big5-authority-v2-review-manifest.v1'
            || ($review['counts']['assets'] ?? null) !== self::ASSET_COUNT
            || ($review['counts']['primary_create'] ?? null) !== self::PRIMARY_CREATE_COUNT
            || ($review['counts']['existing_revision'] ?? null) !== self::EXISTING_REVISION_COUNT
            || ($review['counts']['revision_create'] ?? null) !== self::REVISION_COUNT
            || ($review['counts']['product_shell_preserved'] ?? null) !== self::PRODUCT_SHELL_COUNT
            || count($review['rows'] ?? []) !== self::ASSET_COUNT
            || count($review['cohorts'] ?? []) !== self::COHORT_COUNT) {
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
        if ($requirePendingAuthorization) {
            $this->assertPendingReviewAndRollback($review, $rollback);
            $this->assertPendingAuthorizationPacket($authorization, $review);
        }
        $sourceArtifacts = $review['source_artifacts'] ?? null;
        if (! is_array($sourceArtifacts) || count($sourceArtifacts) !== count(self::SOURCE_ARTIFACT_PATHS)) {
            throw new RuntimeException('Review source artifact identity contract mismatch.');
        }
        $sourcePaths = [];
        foreach ($sourceArtifacts as $source) {
            if (! is_array($source)) {
                throw new RuntimeException('Review source artifact identity contract mismatch.');
            }
            $sourcePaths[] = (string) ($source['path'] ?? '');
        }
        sort($sourcePaths);
        $expectedSourcePaths = self::SOURCE_ARTIFACT_PATHS;
        sort($expectedSourcePaths);
        if ($sourcePaths !== $expectedSourcePaths) {
            throw new RuntimeException('Review source artifact identity contract mismatch.');
        }
        foreach ($sourceArtifacts as $source) {
            $path = $this->projectPath((string) ($source['path'] ?? ''));
            if (! File::isFile($path) || ! hash_equals((string) ($source['sha256'] ?? ''), hash_file('sha256', $path))) {
                throw new RuntimeException('Review source artifact drift: '.($source['path'] ?? 'unknown').'.');
            }
        }
        $assetIds = [];
        $rowsByAsset = [];
        $lockedRowsByAsset = $this->lockedAuthorityRows();
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
            $lockedRow = $lockedRowsByAsset[$assetId] ?? null;
            if (! is_array($lockedRow)
                || ($row['source_package'] ?? null) !== ($lockedRow['source_package'] ?? null)
                || ($row['source_hash'] ?? null) !== ($lockedRow['source_hash'] ?? null)
                || ($row['route'] ?? null) !== ($lockedRow['route'] ?? null)
                || ($row['locale'] ?? null) !== ($lockedRow['locale'] ?? null)
                || ($row['page_family'] ?? null) !== ($lockedRow['page_family'] ?? null)
                || ($row['authority_surface'] ?? null) !== ($lockedRow['authority_surface'] ?? null)
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
        $cohortIds = [];
        foreach ($review['cohorts'] ?? [] as $cohort) {
            $ids = $cohort['asset_ids'] ?? [];
            $cohortId = (string) ($cohort['cohort_id'] ?? '');
            if ($cohortId === ''
                || preg_match('/^[a-z0-9_]+$/', $cohortId) !== 1
                || isset($cohortIds[$cohortId])
                || ! is_array($ids)
                || count($ids) !== ($cohort['asset_count'] ?? -1)
                || count($ids) < 1
                || count($ids) > 25
                || ($cohort['abort_on_any_mismatch'] ?? false) !== true
                || ! hash_equals((string) ($cohort['cohort_sha256'] ?? ''), hash('sha256', json_encode($ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)))) {
                throw new RuntimeException('Review manifest cohort contract mismatch.');
            }
            $cohortIds[$cohortId] = true;
            $cohortAssets = [...$cohortAssets, ...$ids];
            foreach ($ids as $assetId) {
                if (! is_string($assetId) || ! isset($rowsByAsset[$assetId])) {
                    throw new RuntimeException('Review manifest cohort references an unknown asset identity.');
                }
                $cohortByAsset[$assetId] = $cohortId;
            }
        }
        if (count($cohortAssets) !== self::REVISION_COUNT || count(array_unique($cohortAssets)) !== self::REVISION_COUNT) {
            throw new RuntimeException('Review manifest cohort coverage mismatch.');
        }
        foreach ($rowsByAsset as $assetId => $row) {
            if ($row['action_contract']['revision_create'] && ! isset($cohortByAsset[$assetId])) {
                throw new RuntimeException('Review manifest cohort membership mismatch: '.$assetId.'.');
            }
            $expectedCohort = $row['action_contract']['revision_create'] ? $cohortByAsset[$assetId] : null;
            if (($row['promotion']['cohort_id'] ?? null) !== $expectedCohort) {
                throw new RuntimeException('Review manifest cohort membership mismatch: '.$assetId.'.');
            }
        }
        if (($review['cohorts'] ?? null) !== $this->expectedCohorts($lockedRowsByAsset)) {
            throw new RuntimeException('Review manifest exact cohort identity contract mismatch.');
        }
        $this->assertAuthorizationCohortIdentityContract($authorization, $review);

        return compact('review', 'authorization', 'rollback') + [
            'review_sha256' => $reviewSha,
            'rollback_sha256' => $rollbackSha,
        ];
    }

    /** @param array<string,mixed> $authorization @param array<string,mixed> $review */
    private function assertPendingAuthorizationPacket(array $authorization, array $review): void
    {
        $authorizationCohorts = $authorization['cohorts'] ?? null;
        if (($authorization['production_promotion_currently_authorized'] ?? true) !== false
            || ($authorization['approval_phrases_currently_executable'] ?? true) !== false
            || ($authorization['deployed_sha'] ?? null) !== null
            || ($authorization['promotion_preflight_fingerprint'] ?? null) !== null
            || ! is_array($authorizationCohorts)) {
            throw new RuntimeException('Package-only authorization packet must remain pending and non-executable.');
        }
        foreach ($authorizationCohorts as $cohort) {
            if (($cohort['authorized'] ?? true) !== false
                || ($cohort['exact_authorization'] ?? null) !== null) {
                throw new RuntimeException('Package-only authorization packet must remain pending and non-executable.');
            }
        }
    }

    /** @param array<string,mixed> $authorization @param array<string,mixed> $review */
    private function assertAuthorizationCohortIdentityContract(array $authorization, array $review): void
    {
        $authorizationCohorts = $authorization['cohorts'] ?? null;
        $reviewCohorts = collect($review['cohorts'] ?? [])->keyBy('cohort_id');
        if (! is_array($authorizationCohorts) || count($authorizationCohorts) !== $reviewCohorts->count()) {
            throw new RuntimeException('Authorization packet cohort identity coverage mismatch.');
        }
        $seenCohorts = [];
        foreach ($authorizationCohorts as $cohort) {
            $cohortId = is_array($cohort) ? (string) ($cohort['cohort_id'] ?? '') : '';
            $reviewCohort = $reviewCohorts->get($cohortId);
            if ($cohortId === ''
                || isset($seenCohorts[$cohortId])
                || ! is_array($reviewCohort)
                || ($cohort['cohort_sha256'] ?? null) !== ($reviewCohort['cohort_sha256'] ?? null)
                || ($cohort['asset_count'] ?? null) !== ($reviewCohort['asset_count'] ?? null)) {
                throw new RuntimeException('Authorization packet cohort identity coverage mismatch.');
            }
            $seenCohorts[$cohortId] = true;
        }
        if (count($seenCohorts) !== $reviewCohorts->count()) {
            throw new RuntimeException('Authorization packet cohort identity coverage mismatch.');
        }
    }

    /** @param array<string,mixed> $review @param array<string,mixed> $rollback */
    private function assertPendingReviewAndRollback(array $review, array $rollback): void
    {
        $effects = $rollback['effects'] ?? null;
        $expectedEffects = [
            'database_writes' => 0,
            'indexability_changes' => 0,
            'promotions' => 0,
            'public_release_changes' => 0,
            'rollbacks' => 0,
        ];
        if (is_array($effects)) {
            ksort($effects);
        }
        if (($review['status'] ?? null) !== 'HOLD_PENDING_MANUAL_REVIEW_AND_RUNTIME_BINDING'
            || ($review['invariants']['production_promotion_currently_authorized'] ?? true) !== false
            || ($rollback['status'] ?? null) !== 'HOLD_PENDING_EXACT_RUNTIME_TARGETS'
            || ($rollback['abort_on_missing_target'] ?? false) !== true
            || ($rollback['execution_implemented'] ?? true) !== false
            || $effects !== $expectedEffects) {
            throw new RuntimeException('Package-only review and rollback artifacts must remain pending and non-executable.');
        }
        foreach ($review['rows'] as $row) {
            if (($row['manual_review']['status'] ?? null) !== 'pending_manual_review'
                || ($row['manual_review']['reviewer_id'] ?? null) !== null
                || ($row['manual_review']['reviewed_at'] ?? null) !== null
                || ($row['manual_review']['review_record_sha256'] ?? null) !== null
                || ($row['permissions']['source']['approved'] ?? true) !== false
                || ($row['permissions']['source']['approval_reference'] ?? null) !== null
                || ($row['permissions']['media']['approved'] ?? true) !== false
                || ($row['permissions']['media']['approval_reference'] ?? null) !== null
                || ($row['expected_runtime']['bound'] ?? true) !== false
                || collect($row['expected_runtime'] ?? [])->except('bound')->contains(static fn (mixed $value): bool => $value !== null)
                || ($row['promotion']['eligible'] ?? true) !== false
                || ($row['promotion']['exact_authorization_required'] ?? null) !== ($row['action_contract']['revision_create'] ?? null)) {
                throw new RuntimeException('Package-only review and rollback artifacts must remain pending and non-executable.');
            }
        }
        foreach ($rollback['rows'] as $row) {
            if (($row['exact_target_bound'] ?? true) !== false
                || ($row['primary_id'] ?? null) !== null
                || ($row['restore_published_revision_id'] ?? null) !== null
                || ($row['restore_public_runtime_baseline_sha256'] ?? null) !== null) {
                throw new RuntimeException('Package-only review and rollback artifacts must remain pending and non-executable.');
            }
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function lockedAuthorityRows(): array
    {
        [$package, $packageSha256] = $this->readJson(self::DRAFT_IMPORT_PACKAGE_PATH, 'locked PR37 draft import package');
        $rows = $package['assets'] ?? null;
        if (! hash_equals(self::DRAFT_IMPORT_PACKAGE_SHA256, $packageSha256)
            || ! is_array($rows)
            || count($rows) !== self::ASSET_COUNT) {
            throw new RuntimeException('Locked PR37 draft import package identity or hash mismatch.');
        }

        $rowsByAsset = [];
        foreach ($rows as $row) {
            $assetId = is_array($row) ? (string) ($row['asset_id'] ?? '') : '';
            if ($assetId === '' || isset($rowsByAsset[$assetId])) {
                throw new RuntimeException('Locked PR37 draft import package asset identity mismatch.');
            }
            $rowsByAsset[$assetId] = $row;
        }

        return $rowsByAsset;
    }

    /**
     * @param  array<string,array<string,mixed>>  $lockedRowsByAsset
     * @return list<array<string,mixed>>
     */
    private function expectedCohorts(array $lockedRowsByAsset): array
    {
        $collator = new \Collator('en');
        $groups = [];
        foreach ($lockedRowsByAsset as $row) {
            $surface = (string) ($row['authority_surface'] ?? '');
            if ($surface === 'CMS landing_surfaces/page_blocks') {
                continue;
            }
            $locale = (string) ($row['locale'] ?? '');
            $groups[$surface.'|'.$locale][] = (string) ($row['asset_id'] ?? '');
        }
        ksort($groups, SORT_STRING);

        $cohorts = [];
        foreach ($groups as $key => $assetIds) {
            usort($assetIds, static fn (string $left, string $right): int => $collator->compare($left, $right));
            [$surface, $locale] = explode('|', $key, 2);
            foreach (array_chunk($assetIds, 25) as $index => $cohortAssetIds) {
                $surfaceKey = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($surface)), '_');
                $localeKey = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($locale)), '_');
                $cohorts[] = [
                    'cohort_id' => $surfaceKey.'_'.$localeKey.'_'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                    'authority_surface' => $surface,
                    'locale' => $locale,
                    'asset_count' => count($cohortAssetIds),
                    'asset_ids' => $cohortAssetIds,
                    'cohort_sha256' => hash('sha256', json_encode($cohortAssetIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                    'abort_on_any_mismatch' => true,
                ];
            }
        }

        return $cohorts;
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
