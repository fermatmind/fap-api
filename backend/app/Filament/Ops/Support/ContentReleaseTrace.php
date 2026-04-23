<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Models\ArticleRevision;
use App\Models\AuditLog;
use App\Models\CareerGuideRevision;
use App\Models\CareerJobRevision;

final class ContentReleaseTrace
{
    /**
     * @return array<string, mixed>
     */
    public static function meta(string $type, object $record): array
    {
        $currentRevision = self::currentRevision($type, $record);
        $previousRevision = self::previousRevision($type, $record, $currentRevision);
        $previousPublish = self::previousPublish($type, $record);

        return [
            'revision_no' => $currentRevision['revision_no'] ?? null,
            'revision_created_at' => $currentRevision['created_at'] ?? null,
            'diff_summary' => self::diffSummary($type, $currentRevision['snapshot'] ?? null, $previousRevision['snapshot'] ?? null),
            'rollback_target' => self::rollbackTarget($previousPublish, $previousRevision),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function currentRevision(string $type, object $record): ?array
    {
        return match ($type) {
            'article' => self::mapArticleRevision(
                ArticleRevision::query()
                    ->withoutGlobalScopes()
                    ->where('org_id', (int) data_get($record, 'org_id', 0))
                    ->where('article_id', (int) data_get($record, 'id', 0))
                    ->latest('revision_no')
                    ->first()
            ),
            'guide' => self::mapGuideRevision(
                CareerGuideRevision::query()
                    ->where('career_guide_id', (int) data_get($record, 'id', 0))
                    ->latest('revision_no')
                    ->first()
            ),
            'job' => self::mapJobRevision(
                CareerJobRevision::query()
                    ->where('job_id', (int) data_get($record, 'id', 0))
                    ->latest('revision_no')
                    ->first()
            ),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>|null  $currentRevision
     * @return array<string, mixed>|null
     */
    private static function previousRevision(string $type, object $record, ?array $currentRevision): ?array
    {
        $currentRevisionNo = (int) ($currentRevision['revision_no'] ?? 0);

        return match ($type) {
            'article' => self::mapArticleRevision(
                ArticleRevision::query()
                    ->withoutGlobalScopes()
                    ->where('org_id', (int) data_get($record, 'org_id', 0))
                    ->where('article_id', (int) data_get($record, 'id', 0))
                    ->when($currentRevisionNo > 0, fn ($query) => $query->where('revision_no', '<', $currentRevisionNo))
                    ->latest('revision_no')
                    ->first()
            ),
            'guide' => self::mapGuideRevision(
                CareerGuideRevision::query()
                    ->where('career_guide_id', (int) data_get($record, 'id', 0))
                    ->when($currentRevisionNo > 0, fn ($query) => $query->where('revision_no', '<', $currentRevisionNo))
                    ->latest('revision_no')
                    ->first()
            ),
            'job' => self::mapJobRevision(
                CareerJobRevision::query()
                    ->where('job_id', (int) data_get($record, 'id', 0))
                    ->when($currentRevisionNo > 0, fn ($query) => $query->where('revision_no', '<', $currentRevisionNo))
                    ->latest('revision_no')
                    ->first()
            ),
            default => null,
        };
    }

    private static function previousPublish(string $type, object $record): ?AuditLog
    {
        return AuditLog::query()
            ->withoutGlobalScopes()
            ->where('action', 'content_release_publish')
            ->where('target_type', self::targetType($type))
            ->where('target_id', (string) data_get($record, 'id', ''))
            ->latest('created_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>|null  $currentSnapshot
     * @param  array<string, mixed>|null  $previousSnapshot
     * @return array<string, mixed>
     */
    private static function diffSummary(string $type, ?array $currentSnapshot, ?array $previousSnapshot): array
    {
        $changedFields = [];

        foreach (self::monitoredFields($type) as $path => $label) {
            $currentValue = self::normalizeValue(data_get($currentSnapshot, $path));
            $previousValue = self::normalizeValue(data_get($previousSnapshot, $path));

            if ($currentValue !== $previousValue) {
                $changedFields[] = $label;
            }
        }

        return [
            'changed_count' => count($changedFields),
            'changed_fields' => array_slice($changedFields, 0, 5),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $previousRevision
     * @return array<string, mixed>|null
     */
    private static function rollbackTarget(?AuditLog $previousPublish, ?array $previousRevision): ?array
    {
        if ($previousPublish instanceof AuditLog) {
            $meta = is_array($previousPublish->meta_json) ? $previousPublish->meta_json : [];

            return [
                'kind' => 'previous_publish',
                'title' => trim((string) data_get($meta, 'title', '')),
                'revision_no' => data_get($meta, 'revision_no'),
                'published_at' => data_get($meta, 'published_at'),
                'source' => trim((string) data_get($meta, 'source', '')),
                'audit_created_at' => optional($previousPublish->created_at)?->toIso8601String(),
            ];
        }

        if ($previousRevision !== null) {
            return [
                'kind' => 'previous_revision',
                'title' => trim((string) ($previousRevision['title'] ?? '')),
                'revision_no' => $previousRevision['revision_no'] ?? null,
                'published_at' => null,
                'source' => 'revision_history',
                'audit_created_at' => $previousRevision['created_at'] ?? null,
            ];
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function monitoredFields(string $type): array
    {
        return match ($type) {
            'article' => [
                'title' => 'Title',
                'excerpt' => 'Excerpt',
                'content_md' => 'Body',
                'slug' => 'Slug',
                'locale' => 'Locale',
                'is_public' => 'Visibility',
                'is_indexable' => 'Indexability',
                'seo_meta.seo_title' => 'SEO Title',
                'seo_meta.seo_description' => 'SEO Description',
                'seo_meta.canonical_url' => 'Canonical URL',
                'seo_meta.robots' => 'Robots',
            ],
            'support_article' => [
                'title' => 'Title',
                'summary' => 'Summary',
                'body_md' => 'Body',
                'slug' => 'Slug',
                'locale' => 'Locale',
                'status' => 'Status',
                'review_state' => 'Review State',
                'seo_title' => 'SEO Title',
                'seo_description' => 'SEO Description',
                'canonical_path' => 'Canonical Path',
            ],
            'interpretation_guide' => [
                'title' => 'Title',
                'summary' => 'Summary',
                'body_md' => 'Body',
                'slug' => 'Slug',
                'locale' => 'Locale',
                'status' => 'Status',
                'review_state' => 'Review State',
                'seo_title' => 'SEO Title',
                'seo_description' => 'SEO Description',
                'canonical_path' => 'Canonical Path',
            ],
            'content_page' => [
                'title' => 'Title',
                'summary' => 'Summary',
                'content_md' => 'Body',
                'slug' => 'Slug',
                'path' => 'Path',
                'locale' => 'Locale',
                'status' => 'Status',
                'review_state' => 'Review State',
                'seo_title' => 'SEO Title',
                'seo_description' => 'SEO Description',
                'canonical_path' => 'Canonical Path',
            ],
            'guide' => [
                'guide.title' => 'Title',
                'guide.excerpt' => 'Excerpt',
                'guide.body_md' => 'Body',
                'guide.slug' => 'Slug',
                'guide.locale' => 'Locale',
                'guide.is_public' => 'Visibility',
                'guide.is_indexable' => 'Indexability',
                'seo_meta.seo_title' => 'SEO Title',
                'seo_meta.seo_description' => 'SEO Description',
                'seo_meta.canonical_url' => 'Canonical URL',
                'seo_meta.robots' => 'Robots',
            ],
            'job' => [
                'job.title' => 'Title',
                'job.excerpt' => 'Excerpt',
                'job.body_md' => 'Body',
                'job.slug' => 'Slug',
                'job.locale' => 'Locale',
                'job.is_public' => 'Visibility',
                'job.is_indexable' => 'Indexability',
                'seo_meta.seo_title' => 'SEO Title',
                'seo_meta.seo_description' => 'SEO Description',
                'seo_meta.canonical_url' => 'Canonical URL',
                'seo_meta.robots' => 'Robots',
            ],
            default => [],
        };
    }

    private static function normalizeValue(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return trim((string) $value);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function mapArticleRevision(?ArticleRevision $revision): ?array
    {
        if (! $revision instanceof ArticleRevision) {
            return null;
        }

        return [
            'revision_no' => (int) $revision->revision_no,
            'title' => (string) $revision->title,
            'created_at' => optional($revision->created_at)?->toIso8601String(),
            'snapshot' => is_array($revision->payload_json) ? $revision->payload_json : [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function mapGuideRevision(?CareerGuideRevision $revision): ?array
    {
        if (! $revision instanceof CareerGuideRevision) {
            return null;
        }

        $snapshot = is_array($revision->snapshot_json) ? $revision->snapshot_json : [];

        return [
            'revision_no' => (int) $revision->revision_no,
            'title' => trim((string) data_get($snapshot, 'guide.title', '')),
            'created_at' => optional($revision->created_at)?->toIso8601String(),
            'snapshot' => $snapshot,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function mapJobRevision(?CareerJobRevision $revision): ?array
    {
        if (! $revision instanceof CareerJobRevision) {
            return null;
        }

        $snapshot = is_array($revision->snapshot_json) ? $revision->snapshot_json : [];

        return [
            'revision_no' => (int) $revision->revision_no,
            'title' => trim((string) data_get($snapshot, 'job.title', '')),
            'created_at' => optional($revision->created_at)?->toIso8601String(),
            'snapshot' => $snapshot,
        ];
    }

    private static function targetType(string $type): string
    {
        return match ($type) {
            'article' => 'article',
            'guide' => 'career_guide',
            'job' => 'career_job',
            default => 'content',
        };
    }
}
