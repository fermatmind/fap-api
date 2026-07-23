<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\DTO\Career\CareerConversionClosureSummary;
use App\Models\CareerFeedbackRecord;
use App\Models\CareerShortlistItem;
use App\Support\SchemaBaseline;
use Illuminate\Support\Facades\DB;

final class CareerConversionClosureBuilder
{
    public const SUMMARY_KIND = 'career_conversion_closure';

    public const SUMMARY_VERSION = 'career.conversion_closure.v1';

    public const SCOPE = 'career_all_342';

    public function build(): CareerConversionClosureSummary
    {
        $counts = [
            'career_job_detail_cta_click' => $this->countEvent('career_job_detail_cta_click'),
            'career_support_link_click' => $this->countEvent('career_support_link_click'),
            'career_shortlist_add' => $this->countEvent('career_shortlist_add'),
            'career_feedback_submit' => $this->countEvent('career_feedback_submit'),
            'shortlist_items' => SchemaBaseline::hasTable('career_shortlist_items') ? CareerShortlistItem::query()->count() : 0,
            'feedback_records' => SchemaBaseline::hasTable('career_feedback_records') ? CareerFeedbackRecord::query()->count() : 0,
        ];

        $readiness = [
            'cta_support_ready' => $counts['career_job_detail_cta_click'] > 0 && $counts['career_support_link_click'] > 0,
            'shortlist_wired' => $counts['shortlist_items'] > 0 && $counts['career_shortlist_add'] > 0,
            'feedback_included' => $counts['feedback_records'] > 0 && $counts['career_feedback_submit'] > 0,
        ];
        $readiness['closure_ready'] = $readiness['cta_support_ready']
            && $readiness['shortlist_wired']
            && $readiness['feedback_included'];

        return new CareerConversionClosureSummary(
            summaryKind: self::SUMMARY_KIND,
            summaryVersion: self::SUMMARY_VERSION,
            scope: self::SCOPE,
            counts: $counts,
            readiness: $readiness,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildForSubjectSlug(string $subjectSlug): array
    {
        $slug = trim(strtolower($subjectSlug));
        if ($slug === '') {
            return $this->summaryForSlug(null, $this->emptyCounts());
        }

        $counts = [
            'career_job_detail_cta_click' => $this->countEventBySubject('career_job_detail_cta_click', 'job_slug', $slug),
            'career_support_link_click' => $this->countEventBySupportSubject($slug),
            'career_shortlist_add' => $this->countEventBySubject('career_shortlist_add', 'job_slug', $slug),
            'career_feedback_submit' => $this->countEventBySubject('career_feedback_submit', 'recommendation_type', $slug),
            'shortlist_items' => SchemaBaseline::hasTable('career_shortlist_items')
                ? CareerShortlistItem::query()->where('subject_kind', 'job_slug')->where('subject_slug', $slug)->count()
                : 0,
            'feedback_records' => SchemaBaseline::hasTable('career_feedback_records')
                ? CareerFeedbackRecord::query()->where('subject_kind', 'recommendation_type')->where('subject_slug', $slug)->count()
                : 0,
        ];

        return $this->summaryForSlug($slug, $counts);
    }

    /**
     * Read each conversion source at most once and build the same per-slug
     * projection used by buildForSubjectSlug().
     *
     * @param  list<string>  $subjectSlugs
     * @return array<string, array<string, mixed>>
     */
    public function buildForSubjectSlugs(array $subjectSlugs): array
    {
        $slugs = array_values(array_unique(array_filter(array_map(
            static fn (mixed $slug): string => is_string($slug) ? trim(strtolower($slug)) : '',
            $subjectSlugs,
        ))));
        sort($slugs, SORT_STRING);
        if ($slugs === []) {
            return [];
        }

        $countsBySlug = [];
        foreach ($slugs as $slug) {
            $countsBySlug[$slug] = $this->emptyCounts();
        }

        if (SchemaBaseline::hasTable('events')) {
            $events = DB::table('events')
                ->select(['event_name', 'meta_json'])
                ->where('scale_code', 'CAREER')
                ->whereIn('event_name', [
                    'career_job_detail_cta_click',
                    'career_support_link_click',
                    'career_shortlist_add',
                    'career_feedback_submit',
                ])
                ->whereIn('meta_json->subject_key', $slugs)
                ->get();

            foreach ($events as $event) {
                $meta = $this->decodeMetaJson($event->meta_json ?? null);
                $slug = trim(strtolower((string) ($meta['subject_key'] ?? '')));
                $eventName = (string) ($event->event_name ?? '');
                $expectedSubjectKind = $eventName === 'career_feedback_submit'
                    ? 'recommendation_type'
                    : 'job_slug';
                if (
                    ! isset($countsBySlug[$slug])
                    || ($meta['subject_kind'] ?? null) !== $expectedSubjectKind
                    || ! array_key_exists($eventName, $countsBySlug[$slug])
                ) {
                    continue;
                }

                $countsBySlug[$slug][$eventName]++;
            }
        }

        if (SchemaBaseline::hasTable('career_shortlist_items')) {
            CareerShortlistItem::query()
                ->selectRaw('subject_slug, COUNT(*) AS aggregate_count')
                ->where('subject_kind', 'job_slug')
                ->whereIn('subject_slug', $slugs)
                ->groupBy('subject_slug')
                ->get()
                ->each(function (CareerShortlistItem $row) use (&$countsBySlug): void {
                    $slug = trim(strtolower((string) $row->subject_slug));
                    if (isset($countsBySlug[$slug])) {
                        $countsBySlug[$slug]['shortlist_items'] = (int) $row->getAttribute('aggregate_count');
                    }
                });
        }

        if (SchemaBaseline::hasTable('career_feedback_records')) {
            CareerFeedbackRecord::query()
                ->selectRaw('subject_slug, COUNT(*) AS aggregate_count')
                ->where('subject_kind', 'recommendation_type')
                ->whereIn('subject_slug', $slugs)
                ->groupBy('subject_slug')
                ->get()
                ->each(function (CareerFeedbackRecord $row) use (&$countsBySlug): void {
                    $slug = trim(strtolower((string) $row->subject_slug));
                    if (isset($countsBySlug[$slug])) {
                        $countsBySlug[$slug]['feedback_records'] = (int) $row->getAttribute('aggregate_count');
                    }
                });
        }

        $summaries = [];
        foreach ($slugs as $slug) {
            $summaries[$slug] = $this->summaryForSlug($slug, $countsBySlug[$slug]);
        }

        return $summaries;
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    private function summaryForSlug(?string $slug, array $counts): array
    {
        $readiness = [
            'cta_support_ready' => $counts['career_job_detail_cta_click'] > 0 && $counts['career_support_link_click'] > 0,
            'shortlist_wired' => $counts['shortlist_items'] > 0 && $counts['career_shortlist_add'] > 0,
            'feedback_included' => $counts['feedback_records'] > 0 && $counts['career_feedback_submit'] > 0,
        ];
        $readiness['closure_ready'] = $readiness['cta_support_ready']
            && $readiness['shortlist_wired']
            && $readiness['feedback_included'];

        return [
            'subject_slug' => $slug,
            'counts' => $counts,
            'readiness' => $readiness,
        ];
    }

    /** @return array<string, int> */
    private function emptyCounts(): array
    {
        return [
            'career_job_detail_cta_click' => 0,
            'career_support_link_click' => 0,
            'career_shortlist_add' => 0,
            'career_feedback_submit' => 0,
            'shortlist_items' => 0,
            'feedback_records' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function decodeMetaJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return (array) $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function countEvent(string $eventName): int
    {
        if (! SchemaBaseline::hasTable('events')) {
            return 0;
        }

        return DB::table('events')
            ->where('scale_code', 'CAREER')
            ->where('event_name', $eventName)
            ->count();
    }

    private function countEventBySubject(string $eventName, string $subjectKind, string $subjectKey): int
    {
        if (! SchemaBaseline::hasTable('events')) {
            return 0;
        }

        return DB::table('events')
            ->where('scale_code', 'CAREER')
            ->where('event_name', $eventName)
            ->where('meta_json->subject_kind', $subjectKind)
            ->where('meta_json->subject_key', $subjectKey)
            ->count();
    }

    private function countEventBySupportSubject(string $subjectKey): int
    {
        if (! SchemaBaseline::hasTable('events')) {
            return 0;
        }

        return DB::table('events')
            ->where('scale_code', 'CAREER')
            ->where('event_name', 'career_support_link_click')
            ->where(function ($query) use ($subjectKey): void {
                $query->where('meta_json->subject_kind', 'job_slug')
                    ->where('meta_json->subject_key', $subjectKey);
            })
            ->count();
    }
}
