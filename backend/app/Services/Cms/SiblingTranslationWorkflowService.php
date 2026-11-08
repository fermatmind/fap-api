<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Contracts\Cms\SiblingTranslationAdapter;
use App\Filament\Ops\Support\ContentReleaseAudit;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Services\Audit\AuditLogger;
use App\Support\CanonicalTranslationPayloadHash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @review-surface cms_translation_revision
 * @review-surface content_page
 * @review-surface support_article
 * @review-surface interpretation_guide
 */
final class SiblingTranslationWorkflowService
{
    public const PUBLIC_EDITORIAL_ORG_ID = 0;

    /**
     * @var array<string, SiblingTranslationAdapter>
     */
    private array $adapters;

    public function __construct(
        private readonly CmsMachineTranslationProviderRegistry $providers,
        private readonly AuditLogger $auditLogger,
        private readonly RowBackedRevisionWorkspace $workspace,
        private readonly CmsEditorialReviewAttestationService $reviewAttestations,
        SupportArticleTranslationAdapter $supportArticles,
        InterpretationGuideTranslationAdapter $interpretationGuides,
        ContentPageTranslationAdapter $contentPages,
    ) {
        $this->adapters = [
            $supportArticles->contentType() => $supportArticles,
            $interpretationGuides->contentType() => $interpretationGuides,
            $contentPages->contentType() => $contentPages,
        ];
    }

    public function canGenerateMachineDraft(string $contentType): bool
    {
        $adapter = $this->adapter($contentType);

        return $adapter->supportsCreateDraft()
            && $this->providers->providerFor($contentType)->isConfigured();
    }

    public function machineDraftUnavailableReason(string $contentType): ?string
    {
        $adapter = $this->adapter($contentType);
        if (! $adapter->supportsCreateDraft()) {
            return 'This content type does not support machine draft creation in the current backend contract.';
        }

        return $this->providers->providerFor($contentType)->unavailableReason($contentType);
    }

    public function createMachineDraft(string $contentType, Model $source, string $targetLocale): Model
    {
        $adapter = $this->adapter($contentType);
        $provider = $this->providers->providerFor($contentType);
        $this->assertProviderConfigured($contentType, $provider);

        if (! $adapter->isSource($source)) {
            throw new CmsTranslationWorkflowException('Create translation draft must start from a canonical source row.', [
                'source row is not canonical source',
            ]);
        }
        $this->assertSourceApprovedForMachineTranslation($adapter, $source);

        return DB::transaction(function () use ($adapter, $provider, $contentType, $source, $targetLocale): Model {
            $modelClass = $adapter->modelClass();
            $existing = $modelClass::query()
                ->withoutGlobalScopes()
                ->where('org_id', self::PUBLIC_EDITORIAL_ORG_ID)
                ->where('translation_group_id', (string) $source->translation_group_id)
                ->where('locale', $targetLocale)
                ->first();

            if ($existing instanceof Model) {
                throw new CmsTranslationWorkflowException('Target locale already exists for this translation group.', [
                    'target locale already exists',
                ]);
            }

            $payload = $provider->translate($contentType, $source, $adapter->normalizedSourcePayload($source), $targetLocale);
            $target = $adapter->createTarget($source, $targetLocale, $payload);
            $target = $this->workspace->saveWorkingDraft(
                $contentType,
                $target,
                $adapter->snapshotPayload($target),
                CmsTranslationRevision::STATUS_MACHINE_DRAFT,
                [
                    'org_id' => self::PUBLIC_EDITORIAL_ORG_ID,
                    'source_content_id' => (int) $source->id,
                    'source_locale' => (string) $source->locale,
                    'translation_group_id' => (string) $source->translation_group_id,
                    'translated_from_version_hash' => (string) $source->source_version_hash,
                ],
            );

            $this->log('cms_translation_draft_created', $contentType, $target, [
                'source_content_id' => (int) $source->id,
                'target_locale' => $targetLocale,
            ]);

            return $target;
        });
    }

    /** Import an offline prepared TW candidate into the existing revision workspace. */
    public function createPreparedContentPageDraft(int $sourceId, string $expectedSourceHash, array $fields, ?string $expectedSourcePayloadHash = null): ContentPage
    {
        $allowed = ['title', 'kicker', 'summary', 'seo_title', 'meta_description', 'seo_description', 'content_md', 'headings', 'faq_items'];
        if (array_diff(array_keys($fields), $allowed) !== []
            || array_diff($allowed, array_keys($fields)) !== []
            || ! preg_match('/^[a-f0-9]{64}$/', $expectedSourceHash)) {
            throw new CmsTranslationWorkflowException('Prepared ContentPage fields or source binding are invalid.', ['prepared payload invalid']);
        }
        foreach (array_diff($allowed, ['headings', 'faq_items']) as $field) {
            if (! is_string($fields[$field]) && $fields[$field] !== null) {
                throw new CmsTranslationWorkflowException('Prepared ContentPage text is invalid.', ['prepared text invalid']);
            }
        }
        if (! is_array($fields['headings']) || ! array_is_list($fields['headings'])
            || array_filter($fields['headings'], static fn ($heading): bool => ! is_string($heading)) !== []
            || ! is_array($fields['faq_items']) || ! array_is_list($fields['faq_items'])) {
            throw new CmsTranslationWorkflowException('Prepared ContentPage structure is invalid.', ['prepared structure invalid']);
        }
        foreach ($fields['faq_items'] as $faq) {
            if (! is_array($faq) || array_diff(array_keys($faq), ['question', 'answer']) !== []
                || ! is_string($faq['question'] ?? null) || ! is_string($faq['answer'] ?? null)) {
                throw new CmsTranslationWorkflowException('Prepared ContentPage FAQ is invalid.', ['prepared FAQ invalid']);
            }
        }

        return DB::transaction(function () use ($sourceId, $expectedSourceHash, $fields, $expectedSourcePayloadHash): ContentPage {
            $source = ContentPage::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($sourceId);
            $adapter = $this->adapter('content_page');
            if (! $adapter instanceof ContentPageTranslationAdapter || ! $adapter->isPreparedTraditionalSourceIdentity($source)
                || ! $source->passesPublicReadinessGate() || trim((string) $source->content_html) !== '') {
                throw new CmsTranslationWorkflowException('Prepared translation requires a published canonical Markdown source.', ['source identity invalid']);
            }
            $this->assertSourceApprovedForMachineTranslation($adapter, $source);
            // Legacy source version keys remain opaque native authority. A
            // publisher can bind their complete payload independently without
            // rewriting the source hash or invalidating its historical reviews.
            $payloadMatches = $expectedSourcePayloadHash === null
                ? hash_equals($expectedSourceHash, $source->freshSourceVersionHash())
                : preg_match('/^[a-f0-9]{64}$/', $expectedSourcePayloadHash)
                    && hash_equals($expectedSourcePayloadHash, CanonicalTranslationPayloadHash::hash($adapter->snapshotPayload($source)));
            if (! $payloadMatches || ! hash_equals($expectedSourceHash, (string) $source->source_version_hash)) {
                throw new CmsTranslationWorkflowException('Prepared translation source changed.', ['source version mismatch']);
            }
            $existing = ContentPage::query()->withoutGlobalScopes()
                ->where('org_id', self::PUBLIC_EDITORIAL_ORG_ID)->where('locale', 'zh-TW')
                ->where(static function ($query) use ($source): void {
                    $query->where('translation_group_id', $source->translation_group_id)->orWhere('slug', $source->slug);
                })->lockForUpdate()->exists();
            if ($existing) {
                throw new CmsTranslationWorkflowException('Prepared translation target already exists.', ['target identity collision']);
            }
            $payload = $adapter->snapshotPayload($source);
            foreach (['title', 'kicker', 'summary', 'seo_title', 'meta_description', 'seo_description', 'faq_items'] as $field) {
                $payload[$field] = $fields[$field];
            }
            $payload['body_md'] = $fields['content_md'];
            $payload['body_html'] = '';
            $payload['headings_json'] = $fields['headings'];
            // Source approvals never approve a new translation. It remains a
            // private working revision until the native review/publication flow.
            $payload['is_public'] = false;
            $payload['is_indexable'] = false;
            $payload['publish_allowed'] = false;
            $payload['operator_approval_required'] = true;
            $payload['operator_approved_at'] = null;
            $payload['claim_gate_status'] = 'not_reviewed';
            $payload['faq_schema_eligible'] = false;
            $payload['schema_eligibility_reviewed_at'] = null;
            $payload['reviewer'] = null;
            $path = preg_replace('#^/(?:en|zh)(?=/|$)#', '', (string) $source->path);
            if (! is_string($path) || ! preg_match('#^/[a-z0-9]+(?:[-/][a-z0-9]+)*$#', $path)) {
                throw new CmsTranslationWorkflowException('Prepared source path is invalid.', ['source path invalid']);
            }
            $payload['path'] = '/zh-TW'.$path;
            $payload['canonical_path'] = $payload['path'];
            if ($adapter->requiredPayloadBlockers($payload) !== []) {
                throw new CmsTranslationWorkflowException('Prepared ContentPage is incomplete.', ['prepared payload incomplete']);
            }
            /** @var ContentPage $target */
            $target = $adapter->createTarget($source, 'zh-TW', $payload);
            $target = $this->workspace->saveWorkingDraft('content_page', $target, $adapter->snapshotPayload($target),
                CmsTranslationRevision::STATUS_MACHINE_DRAFT, [
                    'org_id' => self::PUBLIC_EDITORIAL_ORG_ID,
                    'source_content_id' => (int) $source->id,
                    'source_locale' => 'zh-CN',
                    'translation_group_id' => (string) $source->translation_group_id,
                    'translated_from_version_hash' => $expectedSourceHash,
                ]);
            $this->log('cms_prepared_translation_draft_created', 'content_page', $target, [
                'source_content_id' => (int) $source->id, 'target_locale' => 'zh-TW', 'translation_provider' => 'offline_prepared',
            ]);

            return $target->refresh();
        });
    }

    public function resyncFromSource(string $contentType, Model $target): Model
    {
        $adapter = $this->adapter($contentType);
        $provider = $this->providers->providerFor($contentType);
        $this->assertProviderConfigured($contentType, $provider);

        if ($adapter->isSource($target)) {
            throw new CmsTranslationWorkflowException('Re-sync requires a target translation row.', [
                'target row is source',
            ]);
        }

        return DB::transaction(function () use ($adapter, $provider, $contentType, $target): Model {
            $source = $this->source($adapter, $target);
            if (! $source instanceof Model) {
                throw new CmsTranslationWorkflowException('Target translation is missing a valid source row.', [
                    'source linkage invalid',
                ]);
            }
            $this->assertSourceApprovedForMachineTranslation($adapter, $source);

            $payload = $provider->translate($contentType, $source, $adapter->normalizedSourcePayload($source), (string) $target->locale);
            $currentWorking = $this->workspace->workingRevision($contentType, $target);
            $recordPayload = array_replace($currentWorking->payload_json ?? $adapter->snapshotPayload($target), $payload);
            $target = $this->workspace->saveWorkingDraft(
                $contentType,
                $target,
                $recordPayload,
                CmsTranslationRevision::STATUS_MACHINE_DRAFT,
                [
                    'org_id' => self::PUBLIC_EDITORIAL_ORG_ID,
                    'source_content_id' => (int) $source->id,
                    'source_locale' => (string) $source->locale,
                    'translation_group_id' => (string) $source->translation_group_id,
                    'translated_from_version_hash' => (string) $source->source_version_hash,
                ],
            );

            $this->log('cms_translation_resynced', $contentType, $target, [
                'source_content_id' => (int) $source->id,
            ]);

            return $target->refresh();
        });
    }

    public function promoteToHumanReview(string $contentType, Model $target): Model
    {
        $adapter = $this->adapter($contentType);

        if ($adapter->isSource($target)) {
            throw new CmsTranslationWorkflowException('Human review promotion requires a target translation row.', [
                'target row is source',
            ]);
        }

        $target = $this->workspace->updateWorkingRevisionStatus($contentType, $target, CmsTranslationRevision::STATUS_HUMAN_REVIEW);
        $adapter->markHumanReview($target);
        if (! filled($target->published_revision_id)) {
            $target->save();
        } else {
            $target->saveQuietly();
        }

        $this->log('cms_translation_promoted_human_review', $contentType, $target, []);

        return $target->refresh();
    }

    /** @param array<string, mixed>|null $attestation */
    public function approveTranslation(
        string $contentType,
        Model $target,
        ?array $attestation = null,
    ): Model {
        return DB::transaction(function () use ($contentType, $target, $attestation): Model {
            /** @var Model $locked */
            $locked = $target->newQueryWithoutScopes()
                ->lockForUpdate()
                ->findOrFail($target->getKey());
            $blockers = $this->preflight($contentType, $locked)['blockers'];
            if ($blockers !== []) {
                throw new CmsTranslationWorkflowException('Translation approval is blocked by preflight issues.', $blockers);
            }

            $working = $this->workspace->workingRevision($contentType, $locked);
            $reviewedContent = $this->workspace->editorRecord($contentType, $locked);
            if ($attestation !== null || $this->reviewAttestations->usesSoloOwnerMode()) {
                $this->reviewAttestations->bindOrCreateApproved(
                    attestation: $attestation,
                    scopeType: 'cms_translation_review',
                    scopeIdentity: $contentType.':'.(int) $locked->getKey().':revision:'.(int) $working->getKey(),
                    resources: [
                        ['surface_id' => $this->reviewSurfaceForContentType($contentType), 'record' => $reviewedContent],
                        ['surface_id' => 'cms_translation_revision', 'record' => $working],
                    ],
                    actorAdminUserId: $this->actorAdminId(),
                );
            }

            $locked = $this->workspace->updateWorkingRevisionStatus($contentType, $locked, CmsTranslationRevision::STATUS_APPROVED);
            $adapter = $this->adapter($contentType);
            $adapter->markApproved($locked);
            if (! filled($locked->published_revision_id)) {
                $locked->save();
            } else {
                $locked->saveQuietly();
            }

            $this->log('cms_translation_approved', $contentType, $locked, []);

            return $locked->refresh();
        });
    }

    public function publishTranslation(string $contentType, Model $target): Model
    {
        $preflight = $this->preflight($contentType, $target);
        if (! $preflight['ok']) {
            throw new CmsTranslationWorkflowException('Translation publish preflight failed.', $preflight['blockers']);
        }

        $working = $this->workspace->workingRevision($contentType, $target);
        if ($working->revision_status !== CmsTranslationRevision::STATUS_APPROVED) {
            throw new CmsTranslationWorkflowException('Only approved translation revisions can be published.', [
                'working revision is not approved',
            ]);
        }

        if ($this->reviewAttestations->usesSoloOwnerMode()) {
            $this->reviewAttestations->assertApprovedEvidence([
                [
                    'surface_id' => $this->reviewSurfaceForContentType($contentType),
                    'record' => $this->workspace->editorRecord($contentType, $target),
                ],
                ['surface_id' => 'cms_translation_revision', 'record' => $working],
            ]);
        }

        $target = $this->workspace->publishWorkingRevision($contentType, $target);

        ContentReleaseAudit::log($contentType, $target->fresh(), 'translation_ops_console');
        $this->log('cms_translation_published', $contentType, $target, []);

        return $target->refresh();
    }

    public function archiveStale(string $contentType, Model $target): Model
    {
        $adapter = $this->adapter($contentType);

        if ((string) $target->translation_status !== 'stale' && ! $this->isStale($adapter, $target)) {
            throw new CmsTranslationWorkflowException('Only stale translation rows can be archived.', [
                'target row is not stale',
            ]);
        }

        $target = $this->workspace->updateWorkingRevisionStatus($contentType, $target, CmsTranslationRevision::STATUS_ARCHIVED);
        if (! filled($target->published_revision_id)) {
            $adapter->markArchived($target);
            $target->save();
        }

        $this->log('cms_translation_archived', $contentType, $target, []);

        return $target->refresh();
    }

    /**
     * @return array{ok:bool,blockers:list<string>}
     */
    public function preflight(string $contentType, Model $target): array
    {
        $adapter = $this->adapter($contentType);
        $blockers = [];

        if ($adapter->isSource($target)) {
            $blockers[] = 'target row is source';
        }
        if ((int) $target->org_id !== self::PUBLIC_EDITORIAL_ORG_ID) {
            $blockers[] = 'target row org mismatch';
        }
        if (! filled($target->locale)) {
            $blockers[] = 'target locale missing';
        }

        $working = $target->workingRevision;
        if (! $working instanceof CmsTranslationRevision) {
            $blockers[] = 'working revision missing';
        }
        $payload = $working instanceof CmsTranslationRevision
            ? ($working->payload_json ?? [])
            : $adapter->snapshotPayload($target);
        if ($working instanceof CmsTranslationRevision && $payload === []) {
            $blockers[] = 'working revision missing payload';
        }

        $source = $this->source($adapter, $target);
        if (! $source instanceof Model || ! $adapter->isSource($source)) {
            $blockers[] = 'source linkage invalid';
        } else {
            $blockers = array_merge($blockers, $this->sourceDisclosureBlockers($adapter, $source));
            if ($working instanceof CmsTranslationRevision
                && (! filled($source->source_version_hash) || ! filled($working->translated_from_version_hash))) {
                $blockers[] = 'translation provenance unknown';
            }
            if ((int) $target->source_content_id !== (int) $source->id) {
                $blockers[] = 'source_content_id mismatch';
            }
            if ((string) $target->translation_group_id !== (string) $source->translation_group_id) {
                $blockers[] = 'translation_group mismatch';
            }
            if ((string) $target->source_locale !== (string) $source->locale) {
                $blockers[] = 'source_locale mismatch';
            }
            if ($this->isStale($adapter, $target)) {
                $blockers[] = 'target translation is stale';
            }
        }

        $blockers = array_values(array_unique(array_merge($blockers, $adapter->requiredPayloadBlockers($payload))));

        return [
            'ok' => $blockers === [],
            'blockers' => $blockers,
        ];
    }

    public function isStale(SiblingTranslationAdapter $adapter, Model $target): bool
    {
        $source = $this->source($adapter, $target);
        if (! $source instanceof Model) {
            return false;
        }

        $working = $target->workingRevision instanceof CmsTranslationRevision
            ? $target->workingRevision
            : null;
        $translatedFrom = $working?->translated_from_version_hash ?: $target->translated_from_version_hash;

        return filled($source->source_version_hash)
            && filled($translatedFrom)
            && ! hash_equals((string) $source->source_version_hash, (string) $translatedFrom);
    }

    public function adapter(string $contentType): SiblingTranslationAdapter
    {
        $adapter = $this->adapters[$contentType] ?? null;
        if (! $adapter instanceof SiblingTranslationAdapter) {
            throw new CmsTranslationWorkflowException(sprintf('Unsupported content type [%s].', $contentType));
        }

        return $adapter;
    }

    private function source(SiblingTranslationAdapter $adapter, Model $target): ?Model
    {
        if ($adapter->isSource($target)) {
            return $target;
        }

        $modelClass = $adapter->modelClass();

        return $modelClass::query()
            ->withoutGlobalScopes()
            ->find($target->source_content_id);
    }

    private function assertProviderConfigured(string $contentType, object $provider): void
    {
        if (! method_exists($provider, 'isConfigured') || ! $provider->isConfigured()) {
            $reason = method_exists($provider, 'unavailableReason')
                ? (string) $provider->unavailableReason($contentType)
                : 'machine translation provider unavailable';

            throw new CmsTranslationWorkflowException($reason, [
                'machine translation provider unavailable',
            ]);
        }
    }

    private function reviewSurfaceForContentType(string $contentType): string
    {
        return match ($contentType) {
            'support_article' => 'support_article',
            'interpretation_guide' => 'interpretation_guide',
            'content_page' => 'content_page',
            default => throw new CmsTranslationWorkflowException('Unsupported CMS review surface.', [
                'review surface missing',
            ]),
        };
    }

    private function actorAdminId(): int
    {
        $guard = (string) config('admin.guard', 'admin');
        $actor = auth($guard)->user();

        return is_object($actor) && is_numeric(data_get($actor, 'id'))
            ? (int) data_get($actor, 'id')
            : 0;
    }

    private function assertSourceApprovedForMachineTranslation(SiblingTranslationAdapter $adapter, Model $source): void
    {
        $blockers = $this->sourceDisclosureBlockers($adapter, $source);
        if ($blockers !== []) {
            throw new CmsTranslationWorkflowException('Source row is outside machine translation disclosure scope.', $blockers);
        }
    }

    /**
     * @return list<string>
     */
    private function sourceDisclosureBlockers(SiblingTranslationAdapter $adapter, Model $source): array
    {
        $blockers = [];

        if ((int) data_get($source, 'org_id') !== self::PUBLIC_EDITORIAL_ORG_ID) {
            $blockers[] = 'source row org mismatch';
        }
        if ((string) data_get($source, 'status') !== 'published') {
            $blockers[] = 'source row not published';
        }
        if ((string) data_get($source, 'review_state') !== 'approved') {
            $blockers[] = 'source row not approved';
        }
        if ($source->getAttribute('is_public') !== null && ! (bool) data_get($source, 'is_public')) {
            $blockers[] = 'source row not public';
        }
        if (! $adapter->isPublished($source)) {
            $blockers[] = 'source row adapter publication check failed';
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function log(string $action, string $contentType, Model $record, array $meta): void
    {
        $resolvedRequest = app()->bound('request') ? app()->make('request') : null;
        $request = $resolvedRequest instanceof Request
            ? $resolvedRequest
            : Request::create('/ops/article-translation-ops', 'POST');

        $this->auditLogger->log(
            $request,
            $action,
            $contentType,
            (string) $record->getKey(),
            $meta + [
                'title' => trim((string) data_get($record, 'title', '')),
                'locale' => trim((string) data_get($record, 'locale', '')),
                'translation_group_id' => trim((string) data_get($record, 'translation_group_id', '')),
            ],
            reason: 'cms_translation_workflow',
            result: 'success',
        );
    }
}
