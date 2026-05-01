<?php

declare(strict_types=1);

namespace App\Services\Career;

use App\Models\CareerShortlistItem;
use App\Models\Occupation;
use App\Models\RecommendationSnapshot;
use Illuminate\Validation\ValidationException;

final class CareerShortlistService
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{item:CareerShortlistItem,is_new:bool}
     */
    public function add(array $input): array
    {
        $subjectKind = $this->normalizeText($input['subject_kind'] ?? null) ?? 'job_slug';
        $subjectSlug = $this->normalizeText($input['subject_slug'] ?? null) ?? '';
        $sourcePageType = $this->normalizeText($input['source_page_type'] ?? null) ?? 'career_recommendation_detail';
        $visitorKey = $this->normalizeText($input['visitor_key'] ?? null) ?? '';

        $existing = CareerShortlistItem::query()
            ->where('visitor_key', $visitorKey)
            ->where('subject_kind', $subjectKind)
            ->where('subject_slug', $subjectSlug)
            ->where('source_page_type', $sourcePageType)
            ->first();

        if ($existing instanceof CareerShortlistItem) {
            return ['item' => $existing, 'is_new' => false];
        }

        $occupation = $this->resolvePublicOccupation($subjectSlug);

        $latestSnapshot = null;
        $latestSnapshot = RecommendationSnapshot::query()
            ->where('occupation_id', $occupation->id)
            ->whereNotNull('compiled_at')
            ->whereNotNull('compile_run_id')
            ->whereHas('contextSnapshot', static function ($query): void {
                $query->where('context_payload->materialization', 'career_first_wave');
            })
            ->whereHas('profileProjection', static function ($query): void {
                $query->where('projection_payload->materialization', 'career_first_wave');
            })
            ->orderByDesc('compiled_at')
            ->orderByDesc('created_at')
            ->first();

        $item = CareerShortlistItem::query()->create([
            'visitor_key' => $visitorKey,
            'subject_kind' => $subjectKind,
            'subject_slug' => $subjectSlug,
            'source_page_type' => $sourcePageType,
            'occupation_id' => $occupation->id,
            'context_snapshot_id' => $latestSnapshot?->context_snapshot_id,
            'profile_projection_id' => $latestSnapshot?->profile_projection_id,
            'recommendation_snapshot_id' => $latestSnapshot?->id,
        ]);

        return ['item' => $item, 'is_new' => true];
    }

    /**
     * @return array{is_shortlisted:bool,latest_item:?CareerShortlistItem}
     */
    public function resolveState(string $visitorKey, string $subjectKind, string $subjectSlug, string $sourcePageType): array
    {
        $latestItem = CareerShortlistItem::query()
            ->where('visitor_key', $visitorKey)
            ->where('subject_kind', $subjectKind)
            ->where('subject_slug', $subjectSlug)
            ->where('source_page_type', $sourcePageType)
            ->orderByDesc('created_at')
            ->first();

        return [
            'is_shortlisted' => $latestItem instanceof CareerShortlistItem,
            'latest_item' => $latestItem,
        ];
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim(strtolower((string) $value));

        return $normalized === '' ? null : $normalized;
    }

    private function resolvePublicOccupation(string $subjectSlug): Occupation
    {
        /** @var Occupation|null $occupation */
        $occupation = Occupation::query()
            ->where('canonical_slug', $subjectSlug)
            ->first();

        if ($occupation instanceof Occupation) {
            return $occupation;
        }

        throw ValidationException::withMessages([
            'subject_slug' => 'subject_slug must reference an existing public career job.',
        ]);
    }
}
