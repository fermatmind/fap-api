<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Filament\Ops\Resources\TopicProfileResource\Support\TopicWorkspace;
use App\Models\TopicProfile;
use DomainException;

final class TraditionalTopicPublication
{
    public function read(array $row, bool $lock): array
    {
        $source = $this->find($row, 'zh-CN', $lock);
        $sourceRow = $row;
        $sourceRow['identity']['locale'] = 'zh-CN';
        $sourceRow['snapshot'] = $row['source_snapshot'];
        $states = app(PublicContent53State::class);
        if ($source?->id !== $row['source_id']
            || $states->hash($states->projection($sourceRow)) !== $states->hash($row['source_snapshot'])) {
            throw new DomainException('traditional_topic_source_drift');
        }

        $target = $this->find($row, 'zh-TW', $lock);
        if (! $target) {
            return ['table' => 'topic_profiles', 'missing' => true];
        }

        $revisions = $target->revisions();
        $revision = ($lock ? $revisions->lockForUpdate() : $revisions)->first();
        $published = $target->status === TopicProfile::STATUS_PUBLISHED;
        $snapshot = TopicWorkspace::snapshotPayload($target);
        if ($target->slug !== $row['identity']['slug'] || $target->topic_code !== $source->topic_code
            || ! in_array($target->status, [TopicProfile::STATUS_DRAFT, TopicProfile::STATUS_PUBLISHED], true)
            || $target->is_public !== $published || $target->is_indexable || $target->scheduled_at !== null
            || ($published ? $target->published_at === null || $target->published_at->isFuture() : $target->published_at !== null)
            || $revision === null || $revision->authority_asset_key !== $row['page_id'].':'.$revision->revision_no || $revision->source_hash !== $this->candidateHash($row)
            || $revision->workflow_state !== $target->status
            || ($target->published_revision_id === null ? null : (int) $target->published_revision_id) !== ($published ? $revision->id : null)
            || PromotionContextFactory::canonicalJson($revision->snapshot_json) !== PromotionContextFactory::canonicalJson($snapshot)) {
            throw new DomainException('traditional_topic_target_collision');
        }

        return [
            'table' => 'topic_profiles',
            'missing' => false,
            'retained_draft' => ! $published,
            'id' => (int) $target->id,
            'revision' => (int) $revision->id,
        ];
    }

    public function publish(array $row, array $before, PromotionContext $context): bool
    {
        if ($this->read($row, true) !== $before) {
            throw new DomainException('traditional_topic_target_drift');
        }
        if (! $before['missing'] && ! $before['retained_draft']) {
            return false;
        }

        $target = $before['missing'] ? new TopicProfile : $this->find($row, 'zh-TW', true);
        $draft = $row['native_draft'];
        $target->fill($row['identity'] + $draft['profile'] + [
            'status' => TopicProfile::STATUS_PUBLISHED, 'is_public' => true, 'is_indexable' => false,
            'published_at' => now(), 'scheduled_at' => null,
        ])->save();

        if ($before['missing']) {
            $target->sections()->createMany($draft['sections']);
            $target->entries()->createMany($draft['entries']);
            $target->seoMeta()->create($draft['seo_meta']);
        }
        TopicWorkspace::validatePublicWorkspace($target);
        $this->recordRevision($target, $row, $context);
        $states = app(PublicContent53State::class);
        if ($states->hash($states->projection($row)) !== $states->hash($row['snapshot'])) {
            throw new DomainException('traditional_topic_public_readback_drift');
        }

        return true;
    }

    public function restore(array $row, array $before, array $after, PromotionContext $context): void
    {
        if (! $before['missing'] && ! $before['retained_draft']) {
            return;
        }
        if ($this->read($row, true) !== $after) {
            throw new DomainException('traditional_topic_rollback_conflict');
        }
        $target = $this->find($row, 'zh-TW', true);
        $target->update(['status' => TopicProfile::STATUS_DRAFT, 'is_public' => false, 'published_at' => null]);
        $this->recordRevision($target, $row, $context);
    }

    private function find(array $row, string $locale, bool $lock): ?TopicProfile
    {
        $query = TopicProfile::query()->withoutGlobalScopes()->where(['org_id' => 0, 'locale' => $locale])
            ->where(fn ($query) => $query->where('slug', $row['identity']['slug'])->orWhere('topic_code', $row['native_draft']['profile']['topic_code']));
        $matches = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($matches->count() > 1) {
            throw new DomainException('traditional_topic_identity_collision');
        }
        $profile = $matches->first();
        if ($lock && $profile) {
            $profile->load([
                'sections' => fn ($query) => $query->lockForUpdate(),
                'entries' => fn ($query) => $query->lockForUpdate(),
                'seoMeta' => fn ($query) => $query->lockForUpdate(),
            ]);
        }

        return $profile;
    }

    private function candidateHash(array $row): string
    {
        return hash('sha256', PromotionContextFactory::canonicalJson($row['native_draft']));
    }

    private function recordRevision(TopicProfile $target, array $row, PromotionContext $context): void
    {
        $target->refresh();
        $number = TopicWorkspace::nextRevisionNo($target);
        $revision = $target->revisions()->create([
            'revision_no' => $number,
            'authority_asset_key' => $row['page_id'].':'.$number,
            'authority_package_sha256' => $context->packageSha256,
            'source_hash' => $this->candidateHash($row),
            'snapshot_json' => TopicWorkspace::snapshotPayload($target),
            'workflow_state' => $target->status,
            'created_at' => now(),
        ]);
        $target->forceFill(['published_revision_id' => $target->is_public ? $revision->id : null])->saveQuietly();
    }
}
