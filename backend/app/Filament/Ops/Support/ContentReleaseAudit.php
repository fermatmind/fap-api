<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Models\ContentPage;
use App\Models\InterpretationGuide;
use App\Models\SupportArticle;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class ContentReleaseAudit
{
    public static function log(string $type, object $record, string $source): void
    {
        $targetType = match ($type) {
            'article' => 'article',
            'support_article' => 'support_article',
            'interpretation_guide' => 'interpretation_guide',
            'content_page' => 'content_page',
            'guide' => 'career_guide',
            'job' => 'career_job',
            default => 'content',
        };

        $guard = (string) config('admin.guard', 'admin');
        $actor = auth($guard)->user();
        $request = app()->bound('request') && request() instanceof Request
            ? request()
            : Request::create('/ops/content-release', 'POST');

        app(AuditLogger::class)->log(
            $request,
            'content_release_publish',
            $targetType,
            (string) data_get($record, 'id'),
            [
                'title' => trim((string) data_get($record, 'title', '')),
                'locale' => trim((string) data_get($record, 'locale', '')),
                'status_after' => trim((string) data_get($record, 'status', 'published')),
                'visibility' => data_get($record, 'is_public') ? 'public' : 'private',
                'published_at' => optional(data_get($record, 'published_at'))?->toISOString(),
                'actor_email' => is_object($actor) ? trim((string) data_get($actor, 'email', '')) : '',
                'source' => $source,
            ] + ContentReleaseTrace::meta($type, $record),
            reason: 'cms_release_workspace',
            result: 'success',
        );

        ContentReleaseFollowUp::dispatch($type, $record, $source, $request);
    }

    /**
     * @param  list<string>  $contentFields
     */
    public static function shouldDispatchPublishedFollowUp(string $type, Model $record, array $contentFields = []): bool
    {
        if (! self::isEligiblePublishedSurface($type, $record)) {
            return false;
        }

        if ($record->wasRecentlyCreated) {
            return true;
        }

        $trackedFields = array_values(array_unique(array_merge(
            self::baseTrackedFields($type),
            $contentFields,
        )));

        foreach ($trackedFields as $field) {
            if ($record->wasChanged($field)) {
                return true;
            }
        }

        return false;
    }

    private static function isEligiblePublishedSurface(string $type, object $record): bool
    {
        return match ($type) {
            'support_article' => $record instanceof SupportArticle
                && (string) $record->status === SupportArticle::STATUS_PUBLISHED
                && (string) $record->review_state === SupportArticle::REVIEW_APPROVED,
            'interpretation_guide' => $record instanceof InterpretationGuide
                && (string) $record->status === InterpretationGuide::STATUS_PUBLISHED
                && (string) $record->review_state === InterpretationGuide::REVIEW_APPROVED,
            'content_page' => $record instanceof ContentPage
                && (string) $record->status === ContentPage::STATUS_PUBLISHED
                && (bool) $record->is_public,
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    private static function baseTrackedFields(string $type): array
    {
        return match ($type) {
            'support_article', 'interpretation_guide' => [
                'status',
                'review_state',
                'published_at',
                'slug',
                'locale',
                'canonical_path',
            ],
            'content_page' => [
                'status',
                'is_public',
                'published_at',
                'slug',
                'locale',
                'path',
                'canonical_path',
            ],
            default => [],
        };
    }
}
