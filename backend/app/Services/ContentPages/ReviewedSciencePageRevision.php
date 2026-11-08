<?php

declare(strict_types=1);

namespace App\Services\ContentPages;

use App\Filament\Ops\Support\ContentReleaseAudit;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Services\Cms\CmsEditorialReviewAttestationService;
use App\Services\Cms\ContentPagePublishGate;
use App\Services\Cms\RowBackedRevisionWorkspace;
use App\Services\ContentPromotion\PromotionContextFactory;
use App\Services\ReviewGovernance\ReviewAttestationValidationException;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Body-only revision operations for already-public science pages.
 * Existing cohort publishers and their readiness rules remain separate.
 *
 * @review-surface content_page
 * @review-surface cms_translation_revision
 */
final readonly class ReviewedSciencePageRevision
{
    public const SCOPE = 'reviewed-existing-science';

    public function __construct(
        private RowBackedRevisionWorkspace $workspace,
        private CmsEditorialReviewAttestationService $reviews,
        private ContentPagePublishGate $gate,
    ) {}

    public function run(string $locale, array $keys, string $phase, string $path, string $sha, bool $execute, string $expectedState, int $actor, string $environment = 'production', bool $dispatchFollowUp = true): array
    {
        $result = null;
        try {
            if (! in_array($locale, ['en', 'zh-CN'], true) || count($keys) !== 1
                || ! in_array($keys[0], ContentPagesControlledPublishService::SCIENCE_ZH_ALLOWED_KEYS, true)
                || ! in_array($phase, ['stage', 'review', 'publish'], true)) {
                throw new DomainException('existing_science_scope_invalid');
            }
            $bytes = is_file($path) && ! is_link($path) ? file_get_contents($path) : false;
            if (! is_string($bytes) || ! preg_match('/\A[a-f0-9]{64}\z/', $sha) || ! hash_equals($sha, hash('sha256', $bytes))) {
                throw new DomainException('existing_science_candidate_hash_invalid');
            }
            $package = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
            $candidates = array_values(array_filter((array) ($package['candidates'] ?? []), static fn (array $row): bool => ($row['identity'] ?? null) === ['org_id' => 0, 'slug' => $keys[0], 'locale' => $locale]));
            if (count($candidates) !== 1) {
                throw new DomainException('existing_science_candidate_identity_invalid');
            }
            $candidate = $candidates[0];
            if (! in_array($environment, ['staging', 'production'], true)) {
                throw new DomainException('existing_science_environment_invalid');
            }
            if ($environment === 'staging') {
                $baseline = $package['staging_baselines'][$locale] ?? null;
                if (! is_array($baseline) || array_keys($baseline) !== ['before_body_sha256', 'before_faq_sha256']) {
                    throw new DomainException('existing_science_staging_baseline_missing');
                }
                $candidate = [...$candidate, ...$baseline];
            }
            $allowed = ['identity', 'before_body_sha256', 'before_faq_sha256', 'content_md', 'faq_items', 'preserve_fields'];
            if (array_diff(array_keys($candidate), $allowed) !== [] || array_diff($allowed, array_keys($candidate)) !== []
                || ! is_string($candidate['content_md']) || trim($candidate['content_md']) === ''
                || ! is_array($candidate['faq_items']) || ! array_is_list($candidate['faq_items'])) {
                throw new DomainException('existing_science_candidate_fields_invalid');
            }
            if ($execute && (! $this->reviews->isConfiguredSoloOwner($actor) || $expectedState === '')) {
                throw new DomainException('existing_science_owner_or_state_lock_missing');
            }
            $released = null;
            $result = DB::transaction(function () use ($locale, $keys, $phase, $candidate, $execute, $expectedState, $actor, &$released): array {
                $page = ContentPage::query()->withoutGlobalScopes()->where('org_id', 0)
                    ->where('slug', $keys[0])->where('locale', $locale)
                    ->when($execute, fn ($q) => $q->lockForUpdate())->first();
                if (! $page instanceof ContentPage || $page->status !== 'published' || ! $page->is_public
                    || $page->kind !== 'policy' || $page->path !== '/'.$keys[0]
                    || $page->canonical_path !== $page->path
                    || ! $page->published_revision_id || ! $page->working_revision_id) {
                    throw new DomainException('existing_science_published_identity_missing');
                }
                $revision = CmsTranslationRevision::query()->withoutGlobalScopes()->whereKey($page->working_revision_id)
                    ->when($execute, fn ($q) => $q->lockForUpdate())->first();
                if (! $revision instanceof CmsTranslationRevision) {
                    throw new DomainException('existing_science_working_revision_missing');
                }
                $page->setRelation('workingRevision', $revision);
                if ($this->workspace->publishValidationErrors('content_page', $page, $revision) !== []) {
                    throw new DomainException('existing_science_revision_identity_invalid');
                }
                $source = null;
                if (! $page->isSourceContent()) {
                    $source = ContentPage::query()->withoutGlobalScopes()->where('org_id', 0)->whereKey($page->source_content_id)
                        ->when($execute, fn ($q) => $q->lockForUpdate())->first();
                    if (! $source instanceof ContentPage || ! $source->isSourceContent()
                        || $source->status !== 'published' || ! $source->is_public
                        || $source->slug !== $page->slug || $source->locale !== $page->source_locale
                        || $source->translation_group_id !== $page->translation_group_id
                        || ! $source->source_version_hash) {
                        throw new DomainException('existing_science_source_binding_invalid');
                    }
                }
                $state = $this->hash([$page->getAttributes(), $revision->getAttributes(), $source?->getAttributes()]);
                if ($execute && ! hash_equals($expectedState, $state)) {
                    throw new DomainException('existing_science_preflight_state_changed');
                }
                $alreadyPublished = $page->working_revision_id === $page->published_revision_id
                    && $this->matches($page, $candidate);
                if ($alreadyPublished && $source !== null && $page->translated_from_version_hash !== $source->source_version_hash) {
                    throw new DomainException('existing_science_translation_source_changed');
                }
                $payload = $this->workspace->adapter('content_page')->snapshotPayload($page);
                if (trim((string) ($payload['body_html'] ?? '')) !== '') {
                    throw new DomainException('existing_science_html_authority_out_of_scope');
                }
                $payload['body_md'] = $candidate['content_md'];
                $payload['faq_items'] = $candidate['faq_items'];
                if (! $alreadyPublished) {
                    if (! hash_equals((string) $candidate['before_body_sha256'], hash('sha256', (string) $page->content_md))
                        || ! hash_equals((string) $candidate['before_faq_sha256'], $this->hash($page->faq_items ?? []))) {
                        throw new DomainException('existing_science_public_baseline_changed');
                    }
                    if ($page->working_revision_id !== $page->published_revision_id
                        && $this->hash($revision->payload_json) !== $this->hash($payload)) {
                        throw new DomainException('existing_science_foreign_working_draft');
                    }
                    if ($phase !== 'stage' && $page->working_revision_id === $page->published_revision_id) {
                        throw new DomainException('existing_science_separate_draft_required');
                    }
                    if ($phase !== 'stage' && $source !== null
                        && $revision->translated_from_version_hash !== $source->source_version_hash) {
                        throw new DomainException('existing_science_translation_source_changed');
                    }
                    if ($phase === 'publish' && $revision->revision_status !== CmsTranslationRevision::STATUS_APPROVED) {
                        throw new DomainException('existing_science_review_required');
                    }
                }
                /** @var ContentPage $editor */
                $editor = $this->workspace->editorRecord('content_page', $page);
                $this->workspace->adapter('content_page')->applyRevisionPayload($editor, $payload);
                $editor->source_version_hash = $editor->freshSourceVersionHash();
                if ($source !== null) {
                    $editor->translated_from_version_hash = $source->source_version_hash;
                }
                $this->gate->assertPasses($editor);
                if ($phase === 'publish' && ! $alreadyPublished) {
                    $this->reviews->assertApprovedEvidence($this->resources($editor, $revision));
                }
                $changed = false;
                if ($execute && ! $alreadyPublished) {
                    $beforePublic = $page->getAttributes();
                    if ($phase === 'stage') {
                        if ($page->working_revision_id === $page->published_revision_id) {
                            $page = $this->workspace->saveWorkingDraft('content_page', $page, $payload,
                                CmsTranslationRevision::STATUS_DRAFT, [], $actor);
                            if ($page->translation_status !== $beforePublic['translation_status']) {
                                $page->forceFill(['translation_status' => $beforePublic['translation_status']])->saveQuietly();
                            }
                            $revision = $page->workingRevision;
                            $revision->forceFill(['source_version_hash' => $editor->source_version_hash,
                                'translated_from_version_hash' => $source?->source_version_hash])->save();
                            $changed = true;
                        }
                    } elseif ($phase === 'review') {
                        $revision->forceFill(['revision_status' => CmsTranslationRevision::STATUS_APPROVED,
                            'reviewed_at' => now(), 'approved_at' => now()])->save();
                        $this->reviews->bindOrCreateApproved(null, 'cms_editorial_review',
                            'content_page:'.$page->id.':revision:'.$revision->id,
                            $this->resources($editor, $revision), $actor);
                        $changed = true;
                    } else {
                        $page->last_reviewed_at = $revision->reviewed_at;
                        $released = $this->workspace->publishWorkingRevision('content_page', $page,
                            (int) $revision->id, $this->workspace->revisionPayloadHash($revision));
                        // A date cast must not rewrite the existing publication bytes.
                        // The revision's activation time is distinct from datePublished.
                        if ($released->getRawOriginal('published_at') !== $beforePublic['published_at']) {
                            $released->setRawAttributes([...$released->getAttributes(), 'published_at' => $beforePublic['published_at']]);
                            $released->saveQuietly();
                        }
                        $revision->refresh()->forceFill(['published_at' => now()])->save();
                        if (! $this->matches($released, $candidate) || ! $released->passesPublicReadinessGate()) {
                            throw new DomainException('existing_science_published_readback_failed');
                        }
                        $changed = true;
                    }
                    $afterPublic = $page->fresh()->getAttributes();
                    foreach ($beforePublic as $field => $value) {
                        $allowed = $phase === 'publish'
                            ? ['content_md', 'faq_items', 'source_version_hash', 'translated_from_version_hash', 'translation_status', 'published_revision_id', 'updated_at', 'last_reviewed_at']
                            : ['working_revision_id', 'updated_at'];
                        if (! in_array($field, $allowed, true) && ($afterPublic[$field] ?? null) !== $value) {
                            throw new DomainException('existing_science_protected_field_changed:'.$field);
                        }
                    }
                }

                return ['ok' => true, 'command' => ContentPagesControlledPublishService::COMMAND, 'scope' => self::SCOPE,
                    'phase' => $phase, 'dry_run' => ! $execute, 'execute' => $execute, 'writes_committed' => $changed,
                    'state_sha256' => $state, 'target' => $locale.':'.$keys[0], 'target_count' => 1,
                    'working_revision_id' => (int) $page->fresh()->working_revision_id,
                    'published_revision_id' => (int) $page->fresh()->published_revision_id,
                    'already_published' => $alreadyPublished, 'is_indexable' => (bool) $page->is_indexable,
                    'search_mutation_count' => 0, 'errors' => []];
            });
            if ($released !== null) {
                DB::afterCommit(static function () use ($released, $dispatchFollowUp): void {
                    ContentReleaseAudit::log('content_page', $released, 'reviewed_existing_science_revision', $dispatchFollowUp);
                });
            }

            return $result;
        } catch (Throwable $error) {
            if (is_array($result) && $result['writes_committed']) {
                return [...$result, 'ok' => false, 'cache_closeout' => 'follow_up_failed',
                    'errors' => [['code' => 'existing_science_committed_follow_up_failed']]];
            }

            return ['ok' => false, 'command' => ContentPagesControlledPublishService::COMMAND, 'scope' => self::SCOPE,
                'phase' => $phase, 'dry_run' => ! $execute, 'execute' => $execute, 'writes_committed' => false,
                'errors' => [['code' => match (true) {
                    $error instanceof DomainException => $error->getMessage(),
                    $error instanceof ReviewAttestationValidationException => 'existing_science_review_evidence_invalid',
                    $error instanceof ValidationException => 'existing_science_publish_gate_failed',
                    default => 'existing_science_operation_failed',
                }]]];
        }
    }

    private function resources(ContentPage $editor, CmsTranslationRevision $revision): array
    {
        return [['surface_id' => 'content_page', 'record' => $editor], ['surface_id' => 'cms_translation_revision', 'record' => $revision]];
    }

    private function matches(ContentPage $page, array $candidate): bool
    {
        return (string) $page->content_md === $candidate['content_md']
            && $this->hash($page->faq_items ?? []) === $this->hash($candidate['faq_items']);
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', PromotionContextFactory::canonicalJson($value));
    }
}
