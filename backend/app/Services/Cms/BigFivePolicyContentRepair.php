<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Filament\Ops\Support\ContentReleaseAudit;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Exact publication path for the reviewed methodology and source-policy corrections.
 *
 * @review-surface content_page
 * @review-surface content_page_external_evidence_gate
 * @review-surface cms_translation_revision
 */
final readonly class BigFivePolicyContentRepair
{
    private const PAGES = [
        55 => ['zh-CN', 'methodology', null, 116, 116, 'e8bc0c1357c0ab5e186d8c67e829c95269f35493ce315d5bac4bb1fcb332daed', '6b589193a358d8ef6feacb519dcd082e9312486b0007e75c1a38963c6d3b2af2'],
        53 => ['en', 'methodology', 55, 119, 118, '1620a6320356a1b2280aaf9ed2b22e8fa72d60f6081d3bb63483b5a59ed143cd', 'ade1bddbfc5b1054045b5d6c91057d6a142222f60b79d62da40d0084b7e0857b'],
        54 => ['en', 'source-review-policy', 56, 121, 120, '62e0cee94e570012f634c39f5a36309b202ac150fda8b468a3096666abd056da', 'ffb971fdc8f96ab9f7d9134a55565abb04f3771abb887fd2205ed36caac46819'],
    ];

    public function __construct(
        private RowBackedRevisionWorkspace $workspace,
        private CmsEditorialReviewAttestationService $attestations,
    ) {}

    public function canReview(ContentPage $page): bool
    {
        $revision = $page->workingRevision;

        return isset(self::PAGES[$page->id])
            && $revision instanceof CmsTranslationRevision
            && $revision->revision_status === CmsTranslationRevision::STATUS_DRAFT
            && $this->prepared($page, $revision);
    }

    public function inspect(int $id): array
    {
        $page = $this->page($id);
        $revision = $page->workingRevision;

        return [
            'id' => $id,
            'working_revision' => $revision->id,
            'published_revision' => $page->published_revision_id,
            'payload_sha256' => $this->workspace->revisionPayloadHash($revision),
            'canonical_path' => $revision->payload_json['canonical_path'] ?? null,
            'prepared' => $this->prepared($page, $revision),
            'approved' => $revision->revision_status === CmsTranslationRevision::STATUS_APPROVED,
            'public_ready' => $page->passesPublicReadinessGate(),
        ];
    }

    public function stage(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $page = $this->page($id, true);
            $working = $page->workingRevision;
            if ($this->prepared($page, $working)) {
                return;
            }

            [, , $sourceId, $workingId, $publishedId, $baselineBody, $expectedBody] = self::PAGES[$id];
            $payload = $working->payload_json;
            $copy = file_get_contents(base_path('content_assets/big_five_policy_cms_repairs/'.$id.'.md'));
            if ($copy === false || ! hash_equals($expectedBody, hash('sha256', $copy))
                || ! is_array($payload) || $working->id !== $workingId
                || $page->published_revision_id !== $publishedId
                || ! hash_equals($baselineBody, hash('sha256', rtrim((string) ($payload['body_md'] ?? ''))))
                || ($payload['path'] ?? null) !== $this->path($page)
                || ($payload['canonical_path'] ?? null) !== $this->path($page)
                || count((array) ($payload['headings_json'] ?? [])) !== 7
                || ($sourceId === null ? ! $page->passesPublicReadinessGate() : $page->is_public || $page->published_at !== null)) {
                throw new DomainException('The exact policy baseline or candidate changed.');
            }

            $source = $this->source($page, true);
            $payload['body_md'] = $copy;
            $payload['body_html'] = '';
            $payload['is_public'] = true;
            $payload['is_indexable'] = true;
            $this->workspace->saveWorkingDraft(
                'content_page',
                $page,
                $payload,
                CmsTranslationRevision::STATUS_DRAFT,
                $source === null ? [] : ['translated_from_version_hash' => $source->source_version_hash],
            );
        });
    }

    public function approve(int $id, int $actorId): void
    {
        DB::transaction(function () use ($id, $actorId): void {
            $page = $this->page($id, true);
            $revision = $page->workingRevision;
            if (! $this->canReview($page)) {
                throw new DomainException('The exact staged policy is unavailable for review.');
            }
            $this->assertSourceBinding($page);
            $payload = $revision->payload_json;
            if (($payload['legal_review_required'] ?? false) || ($payload['science_review_required'] ?? false)
                || ($payload['forbidden_claims'] ?? []) !== [] || ($payload['schema_enabled'] ?? false)) {
                throw new DomainException('Independent content safety gates still hold.');
            }
            $payload['publish_allowed'] = true;
            $payload['claim_gate_status'] = 'passed';
            if ($payload['operator_approval_required'] ?? true) {
                $payload['operator_approved_at'] = now()->toISOString();
            }
            $revision->forceFill([
                'payload_json' => $payload,
                'revision_status' => CmsTranslationRevision::STATUS_APPROVED,
                'reviewed_at' => now(),
                'approved_at' => now(),
            ])->save();
            $page->setRelation('workingRevision', $revision->fresh());
            $this->attestations->bindOrCreateApproved(
                attestation: null,
                scopeType: 'cms_editorial_review',
                scopeIdentity: 'content_page:'.$id.':revision:'.$revision->id,
                resources: $this->resources($page),
                actorAdminUserId: $actorId,
            );
        });
    }

    public function publish(int $id, int $expectedRevision, string $expectedPayloadHash): void
    {
        $published = DB::transaction(function () use ($id, $expectedRevision, $expectedPayloadHash): ?ContentPage {
            $page = $this->page($id, true);
            $revision = $page->workingRevision;
            if ($revision->id === $expectedRevision && $page->published_revision_id === $expectedRevision
                && hash_equals($this->workspace->revisionPayloadHash($revision), $expectedPayloadHash)
                && $page->passesPublicReadinessGate() && $page->canonical_path === $this->path($page)
                && $this->hasCandidateBody($page, $revision)) {
                return null;
            }
            if (! $this->prepared($page, $revision) || $revision->id !== $expectedRevision
                || ! hash_equals($this->workspace->revisionPayloadHash($revision), $expectedPayloadHash)
                || $revision->revision_status !== CmsTranslationRevision::STATUS_APPROVED
                || ! $this->attestations->usesSoloOwnerMode()) {
                throw new DomainException('The exact staged policy approval or revision lock is unavailable.');
            }
            $this->assertSourceBinding($page);
            $this->attestations->assertApprovedEvidence($this->resources($page));
            $page->review_state = 'approved';
            $released = $this->workspace->publishWorkingRevision('content_page', $page, $expectedRevision, $expectedPayloadHash);
            if (! $released->passesPublicReadinessGate() || $released->path !== $this->path($released)
                || $released->canonical_path !== $released->path || $released->published_revision_id !== $expectedRevision
                || count((array) $released->headings_json) !== 7) {
                throw new DomainException('The released policy projection did not match the reviewed revision.');
            }

            return $released;
        });
        if ($published !== null) {
            ContentReleaseAudit::log('content_page', $published, 'reviewed_big_five_policy_repair');
        }
    }

    private function page(int $id, bool $lock = false): ContentPage
    {
        [$locale, $slug, $sourceId] = self::PAGES[$id] ?? throw new DomainException('Content page is outside the policy repair scope.');
        $page = ContentPage::query()->withoutGlobalScopes()->where('org_id', 0)
            ->whereKey($id)->where('slug', $slug)->where('locale', $locale)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if (! $page || $page->source_content_id !== $sourceId) {
            throw new DomainException('The exact policy identity is unavailable.');
        }

        $working = CmsTranslationRevision::query()->withoutGlobalScopes()
            ->where('org_id', 0)->where('content_type', 'content_page')->where('content_id', $id)
            ->whereKey($page->working_revision_id)->where('locale', $locale)
            ->where('translation_group_id', $page->translation_group_id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if (! $working) {
            throw new DomainException('The exact policy working revision is unavailable.');
        }

        return $page->setRelation('workingRevision', $working);
    }

    private function prepared(ContentPage $page, CmsTranslationRevision $revision): bool
    {
        return $page->published_revision_id !== $revision->id
            && $revision->content_id === $page->id
            && $revision->locale === $page->locale
            && $revision->translation_group_id === $page->translation_group_id
            && ($revision->payload_json['path'] ?? null) === $this->path($page)
            && ($revision->payload_json['canonical_path'] ?? null) === $this->path($page)
            && $this->hasCandidateBody($page, $revision)
            && ($revision->payload_json['is_public'] ?? false) === true
            && ($revision->payload_json['is_indexable'] ?? false) === true;
    }

    private function hasCandidateBody(ContentPage $page, CmsTranslationRevision $revision): bool
    {
        return hash_equals(self::PAGES[$page->id][6], hash('sha256', (string) ($revision->payload_json['body_md'] ?? '')));
    }

    private function source(ContentPage $page, bool $lock): ?ContentPage
    {
        if ($page->source_content_id === null) {
            return null;
        }
        $source = ContentPage::query()->withoutGlobalScopes()->where('org_id', 0)
            ->whereKey($page->source_content_id)->where('slug', $page->slug)->where('locale', 'zh-CN')
            ->where('translation_group_id', $page->translation_group_id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if (! $source || ! $source->isSourceContent() || ! $source->passesPublicReadinessGate()
            || ! hash_equals($source->freshSourceVersionHash(), (string) $source->source_version_hash)
            || ($source->id === 55 && ! hash_equals(self::PAGES[55][6], hash('sha256', (string) $source->content_md)))) {
            throw new DomainException('The exact reviewed Chinese source is unavailable.');
        }

        return $source;
    }

    private function assertSourceBinding(ContentPage $page): void
    {
        $source = $this->source($page, true);
        if ($source !== null && ((int) $page->workingRevision->source_content_id !== (int) $source->id
            || ! hash_equals((string) $source->source_version_hash, (string) $page->workingRevision->translated_from_version_hash))) {
            throw new DomainException('The prepared policy is not bound to the exact current Chinese source.');
        }
    }

    private function resources(ContentPage $page): array
    {
        return [
            ['surface_id' => 'content_page', 'record' => $this->workspace->editorRecord('content_page', $page)],
            ['surface_id' => 'cms_translation_revision', 'record' => $page->workingRevision],
        ];
    }

    private function path(ContentPage $page): string
    {
        return '/'.($page->locale === 'zh-CN' ? 'zh' : 'en').'/personality/big-five/'.$page->slug;
    }
}
