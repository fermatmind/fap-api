<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;

final class ContentReleaseAudit
{
    public static function log(string $type, object $record, string $source): void
    {
        $targetType = match ($type) {
            'article' => 'article',
            'guide' => 'career_guide',
            'job' => 'career_job',
            'method' => 'method_page',
            'data' => 'data_page',
            'personality' => 'personality_profile',
            'topic' => 'topic_profile',
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
}
