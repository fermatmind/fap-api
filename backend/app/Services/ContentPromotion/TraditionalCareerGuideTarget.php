<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\CareerCms\Baseline\CareerGuideBaselineImporter;
use App\CareerCms\Baseline\CareerGuideBaselineNormalizer;
use App\Models\CareerGuide;
use DomainException;
use Illuminate\Support\Facades\DB;

final class TraditionalCareerGuideTarget
{
    public function __construct(
        private readonly CareerGuideBaselineNormalizer $normalizer,
        private readonly CareerGuideBaselineImporter $importer,
    ) {}

    public function read(array $row, bool $lock): array
    {
        if ($lock) {
            $this->requireTransaction();
        }
        $draft = $this->draft($row);
        $query = CareerGuide::query()->withoutGlobalScopes()->where(['org_id' => 0, 'locale' => 'zh-TW'])
            ->where(fn ($query) => $query->where('slug', $draft['slug'])->orWhere('guide_code', $draft['guide_code']));
        $records = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($records->isEmpty()) {
            return ['visibility' => 'missing'];
        }
        $guide = $records->first();
        if ($records->count() !== 1 || $guide->slug !== $draft['slug'] || $guide->guide_code !== $draft['guide_code']
            || ! in_array($guide->lifecycle_state, [null, 'active'], true)) {
            throw new DomainException('traditional_career_guide_target_collision');
        }
        $revisions = $guide->revisions();
        $latest = ($lock ? $revisions->lockForUpdate() : $revisions)->first();
        $published = $guide->status === CareerGuide::STATUS_PUBLISHED;
        $payload = $published ? array_replace($draft, ['is_public' => true]) : $draft;
        if ($latest?->note !== $this->note($row) || $guide->is_public !== $published
            || $guide->is_indexable || $guide->scheduled_at !== null
            || ($published && ($guide->published_at === null || $guide->published_at->isFuture()))
            || (! $published && $guide->status !== CareerGuide::STATUS_DRAFT)
            || $this->importer->planOperation($payload, true, $guide->status)['action'] !== 'skip') {
            throw new DomainException('traditional_career_guide_target_collision');
        }

        return [
            'visibility' => $guide->status,
            'id' => (int) $guide->id,
            'revision' => (int) $latest->id,
            'updated_at' => $guide->updated_at?->toISOString(),
        ];
    }

    public function publish(array $row, array $before): bool
    {
        $this->requireTransaction();
        if ($this->read($row, true) !== $before) {
            throw new DomainException('traditional_career_guide_target_drift');
        }
        if ($before['visibility'] === CareerGuide::STATUS_PUBLISHED) {
            return false;
        }
        $this->write($row, CareerGuide::STATUS_PUBLISHED);

        return true;
    }

    public function restore(array $row, array $before, array $after): void
    {
        $this->requireTransaction();
        if ($before['visibility'] === CareerGuide::STATUS_PUBLISHED) {
            return;
        }
        if ($after['visibility'] !== CareerGuide::STATUS_PUBLISHED || $this->read($row, true) !== $after) {
            throw new DomainException('traditional_career_guide_rollback_conflict');
        }
        $this->write($row, CareerGuide::STATUS_DRAFT);
    }

    private function write(array $row, string $status): void
    {
        $payload = array_replace($this->draft($row), ['is_public' => $status === CareerGuide::STATUS_PUBLISHED]);
        $summary = $this->importer->import([$payload], [
            'upsert' => true, 'status' => $status, 'revision_note' => $this->note($row),
        ]);
        if ($summary['revisions_to_create'] !== 1 || $this->read($row, true)['visibility'] !== $status) {
            throw new DomainException('traditional_career_guide_target_readback_drift');
        }
    }

    private function draft(array $row): array
    {
        $draft = $row['native_draft'];
        if ($row['identity'] !== ['org_id' => 0, 'slug' => $draft['slug'], 'locale' => 'zh-TW']
            || $draft['locale'] !== 'zh-TW' || $draft['status'] !== CareerGuide::STATUS_DRAFT
            || $draft['is_public'] !== false || $draft['is_indexable'] !== false) {
            throw new DomainException('traditional_career_guide_target_invalid');
        }

        return $this->normalizer->normalizeDocuments([[
            'file' => 'traditional-career-guides.json',
            'payload' => ['meta' => ['locale' => 'zh-TW'], 'guides' => [$draft]],
        ]])[0];
    }

    private function note(array $row): string
    {
        return 'Traditional career guide '.hash('sha256', json_encode($row['native_draft'], JSON_THROW_ON_ERROR));
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new DomainException('traditional_career_guide_transaction_required');
        }
    }
}
