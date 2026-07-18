<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Models\EditorialReview;
use App\Models\InterpretationGuide;
use App\Models\ResearchReport;
use App\Models\ReviewAttestation;
use App\Models\ReviewAttestationTargetEvidence;
use App\Models\SupportArticle;
use App\Services\ReviewGovernance\ReviewAttestationCanonicalizer;
use App\Services\ReviewGovernance\ReviewAttestationFactory;
use App\Services\ReviewGovernance\ReviewAttestationService;
use App\Services\ReviewGovernance\ReviewAttestationValidationException;
use Illuminate\Database\Eloquent\Model;

/**
 * Private CMS adapter for the central compact owner-attestation contract.
 *
 * @review-surface article
 * @review-surface article_translation_revision
 * @review-surface cms_translation_revision
 * @review-surface content_page
 * @review-surface support_article
 * @review-surface interpretation_guide
 * @review-surface research_report
 * @review-surface editorial_review
 */
final readonly class CmsEditorialReviewAttestationService
{
    /** @var array<string, class-string<Model>> */
    private const SURFACE_MODELS = [
        'article' => Article::class,
        'article_translation_revision' => ArticleTranslationRevision::class,
        'cms_translation_revision' => CmsTranslationRevision::class,
        'content_page' => ContentPage::class,
        'support_article' => SupportArticle::class,
        'interpretation_guide' => InterpretationGuide::class,
        'research_report' => ResearchReport::class,
        'editorial_review' => EditorialReview::class,
    ];

    /**
     * Workflow and publication outputs are intentionally outside the content
     * fingerprint. Content or revision payload edits still change the hash,
     * while the later approve/publish transitions do not invalidate evidence.
     *
     * @var list<string>
     */
    private const STATE_OUTPUT_ATTRIBUTES = [
        'created_at',
        'updated_at',
        'status',
        'lifecycle_state',
        'lifecycle_changed_at',
        'lifecycle_changed_by_admin_user_id',
        'is_public',
        'is_indexable',
        'sitemap_eligible',
        'llms_eligible',
        'translation_status',
        'working_revision_id',
        'published_revision_id',
        'published_at',
        'revision_status',
        'workflow_state',
        'review_state',
        'reviewer',
        'reviewer_name',
        'reviewer_admin_user_id',
        'reviewed_by',
        'reviewed_by_admin_user_id',
        'reviewed_at',
        'last_reviewed_at',
        'approved_at',
        'operator_approved_at',
        'operator_approval_required',
        'publish_allowed',
        'legal_review_required',
        'science_review_required',
        'claim_gate_status',
        'forbidden_claims',
        'schema_enabled',
        'faq_schema_eligible',
        'schema_eligibility_reviewed_at',
        'submitted_at',
        'last_transition_at',
    ];

    public function __construct(
        private ReviewAttestationCanonicalizer $canonicalizer,
        private ReviewAttestationFactory $factory,
        private ReviewAttestationService $attestations,
    ) {}

    public function usesSoloOwnerMode(): bool
    {
        return (string) config('review_governance.mode') === 'solo_owner';
    }

    public function isConfiguredSoloOwner(int $adminUserId): bool
    {
        return $this->usesSoloOwnerMode()
            && $adminUserId > 0
            && $adminUserId === (int) config('review_governance.solo_owner_admin_user_id');
    }

    /**
     * @param  list<array{surface_id:string,record:Model}>  $resources
     * @return list<array{target_identity:string,target_sha256:string}>
     */
    public function targets(array $resources): array
    {
        if ($resources === []) {
            throw new ReviewAttestationValidationException('CMS review resources must not be empty.');
        }

        $targets = [];
        foreach ($resources as $index => $resource) {
            $missing = array_diff(['surface_id', 'record'], array_keys($resource));
            $extra = array_diff(array_keys($resource), ['surface_id', 'record']);
            if ($missing !== [] || $extra !== []
                || ! is_string($resource['surface_id'])
                || ! $resource['record'] instanceof Model) {
                throw new ReviewAttestationValidationException('CMS review resource at index '.$index.' has an invalid schema.');
            }

            $surfaceId = $resource['surface_id'];
            $record = $resource['record'];
            $expectedModel = self::SURFACE_MODELS[$surfaceId] ?? null;
            if ($expectedModel === null || ! $record instanceof $expectedModel) {
                throw new ReviewAttestationValidationException('CMS review resource does not match its registered surface.');
            }
            if (! $record->exists || $record->getKey() === null) {
                throw new ReviewAttestationValidationException('CMS review targets must be persisted records.');
            }

            $attributes = array_diff_key(
                $record->attributesToArray(),
                array_fill_keys([...self::STATE_OUTPUT_ATTRIBUTES, $record->getKeyName()], true),
            );
            $attributes = array_filter($attributes, static fn (mixed $value): bool => $value !== null);
            ksort($attributes, SORT_STRING);
            $identity = $surfaceId.':'.$record->getMorphClass().':'.(string) $record->getKey();
            $targets[] = [
                'target_identity' => $identity,
                'target_sha256' => hash('sha256', $this->canonicalizer->encode([
                    'surface_id' => $surfaceId,
                    'model' => $record->getMorphClass(),
                    'key' => (string) $record->getKey(),
                    'attributes' => $attributes,
                ])),
            ];
        }

        return $targets;
    }

    public function hasApprovedEvidence(string $surfaceId, Model $record): bool
    {
        $target = $this->targets([['surface_id' => $surfaceId, 'record' => $record]])[0];

        return $this->hasApprovedTarget($target);
    }

    /**
     * @param  list<array{surface_id:string,record:Model}>  $resources
     */
    public function assertApprovedEvidence(array $resources): void
    {
        foreach ($this->targets($resources) as $target) {
            if (! $this->hasApprovedTarget($target)) {
                throw new ReviewAttestationValidationException(
                    'CMS review evidence is missing or stale for one or more exact targets.'
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attestation
     * @param  list<array{surface_id:string,record:Model}>  $resources
     * @return array<string, mixed>
     */
    public function preflight(
        array $attestation,
        array $resources,
        ?string $expectedPackageSha256 = null,
    ): array {
        return $this->attestations->preflight(
            $attestation,
            $this->targets($resources),
            $expectedPackageSha256,
        );
    }

    /**
     * Approval state changes fail closed unless every exact target is approved.
     * An approved_with_exceptions payload may be preflighted, but is never bound
     * by this state-changing adapter.
     *
     * @param  array<string, mixed>  $attestation
     * @param  list<array{surface_id:string,record:Model}>  $resources
     */
    public function bindApproved(
        array $attestation,
        array $resources,
        int $actorAdminUserId,
        ?string $expectedPackageSha256 = null,
    ): ReviewAttestation {
        $this->assertConfiguredSoloOwner($actorAdminUserId);
        if (($attestation['decision'] ?? null) !== 'approved_all') {
            throw new ReviewAttestationValidationException('CMS approval requires approved_all; exception batches fail closed.');
        }

        return $this->attestations->bind(
            $attestation,
            $this->targets($resources),
            $expectedPackageSha256,
        );
    }

    /**
     * @param  array<string, mixed>|null  $attestation
     * @param  list<array{surface_id:string,record:Model}>  $resources
     */
    public function bindOrCreateApproved(
        ?array $attestation,
        string $scopeType,
        string $scopeIdentity,
        array $resources,
        int $actorAdminUserId,
        ?string $packageSha256 = null,
    ): ReviewAttestation {
        $this->assertConfiguredSoloOwner($actorAdminUserId);
        $targets = $this->targets($resources);
        $attestation ??= $this->factory->make(
            scopeType: $scopeType,
            scopeIdentity: $scopeIdentity,
            decision: 'approved_all',
            targets: $targets,
            packageSha256: $packageSha256,
            adminUserId: $actorAdminUserId,
        );

        return $this->bindApproved(
            $attestation,
            $resources,
            $actorAdminUserId,
            $packageSha256,
        );
    }

    private function assertConfiguredSoloOwner(int $actorAdminUserId): void
    {
        if (! $this->isConfiguredSoloOwner($actorAdminUserId)) {
            throw new ReviewAttestationValidationException('CMS solo-owner approval requires the authenticated configured owner.');
        }
    }

    /** @param array{target_identity:string,target_sha256:string} $target */
    private function hasApprovedTarget(array $target): bool
    {
        return ReviewAttestationTargetEvidence::query()
            ->where('target_identity', $target['target_identity'])
            ->where('target_sha256', $target['target_sha256'])
            ->where('target_decision', 'approved')
            ->whereHas('attestation', static function ($query): void {
                $query
                    ->where('review_mode', 'solo_owner')
                    ->where('review_source', (string) config('review_governance.attestation.review_source'))
                    ->where('decision', 'approved_all');
            })
            ->exists();
    }
}
