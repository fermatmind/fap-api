<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Contracts\Cms\SiblingTranslationAdapter;
use App\Filament\Ops\Support\ContentReleaseAudit;
use App\Models\CmsTranslationRevision;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    public function approveTranslation(string $contentType, Model $target): Model
    {
        $blockers = $this->preflight($contentType, $target)['blockers'];
        if ($blockers !== []) {
            throw new CmsTranslationWorkflowException('Translation approval is blocked by preflight issues.', $blockers);
        }

        $target = $this->workspace->updateWorkingRevisionStatus($contentType, $target, CmsTranslationRevision::STATUS_APPROVED);
        $adapter = $this->adapter($contentType);
        $adapter->markApproved($target);
        if (! filled($target->published_revision_id)) {
            $target->save();
        } else {
            $target->saveQuietly();
        }

        $this->log('cms_translation_approved', $contentType, $target, []);

        return $target->refresh();
    }

    public function publishTranslation(string $contentType, Model $target): Model
    {
        $preflight = $this->preflight($contentType, $target);
        if (! $preflight['ok']) {
            throw new CmsTranslationWorkflowException('Translation publish preflight failed.', $preflight['blockers']);
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

        $working = $this->workspace->workingRevision($contentType, $target);
        $payload = $working->payload_json ?? [];
        if ($payload === []) {
            $blockers[] = 'working revision missing payload';
        }

        $source = $this->source($adapter, $target);
        if (! $source instanceof Model || ! $adapter->isSource($source)) {
            $blockers[] = 'source linkage invalid';
        } else {
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

        return [
            'ok' => $blockers === [],
            'blockers' => array_values(array_unique(array_merge($blockers, $adapter->requiredPayloadBlockers($payload)))),
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
