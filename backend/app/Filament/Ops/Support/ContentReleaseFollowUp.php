<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Models\ContentPage;
use App\Models\InterpretationGuide;
use App\Models\SupportArticle;
use App\Services\Audit\AuditLogger;
use App\Services\Cms\ArticleSeoService;
use App\Services\Cms\CareerGuideSeoService;
use App\Services\Cms\CareerJobSeoService;
use App\Services\Ops\OpsAlertService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

final class ContentReleaseFollowUp
{
    public static function dispatch(string $type, object $record, string $source, Request $request): void
    {
        $payload = self::payload($type, $record, $source);

        foreach (self::cacheInvalidationUrls() as $endpoint) {
            self::postEvent(
                request: $request,
                action: 'content_release_cache_signal',
                endpoint: $endpoint,
                payload: $payload,
                alertLabel: 'cache invalidation'
            );
        }

        $broadcastWebhook = self::broadcastWebhook();
        if ($broadcastWebhook !== '') {
            self::postEvent(
                request: $request,
                action: 'content_release_broadcast',
                endpoint: $broadcastWebhook,
                payload: $payload,
                alertLabel: 'event broadcast'
            );
        }
    }

    /**
     * @return list<string>
     */
    private static function cacheInvalidationUrls(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) config('ops.content_release_observability.cache_invalidation_urls', [])
        )));
    }

    private static function broadcastWebhook(): string
    {
        return trim((string) config('ops.content_release_observability.broadcast_webhook', ''));
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(string $type, object $record, string $source): array
    {
        $title = trim((string) data_get($record, 'title', 'Untitled'));
        $locale = trim((string) data_get($record, 'locale', ''));
        $publishedAt = optional(data_get($record, 'published_at'))?->toISOString();
        $paths = self::invalidateUrls($type, $record);

        return [
            'event' => 'content_release_publish',
            'text' => sprintf(
                '[CMS release] %s published via %s (%s)',
                $title !== '' ? $title : 'Untitled',
                $source,
                $type
            ),
            'source' => $source,
            'content' => [
                'type' => $type,
                'id' => (int) data_get($record, 'id'),
                'org_id' => (int) data_get($record, 'org_id', 0),
                'title' => $title,
                'slug' => trim((string) data_get($record, 'slug', '')),
                'locale' => $locale,
                'status' => trim((string) data_get($record, 'status', 'published')),
                'visibility' => data_get($record, 'is_public') ? 'public' : 'private',
                'published_at' => $publishedAt,
            ],
            'cache_signal' => [
                'kind' => 'invalidate',
                'paths' => $paths,
                'urls' => $paths,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private static function invalidateUrls(string $type, object $record): array
    {
        return array_values(array_filter(match ($type) {
            'article' => [
                app(ArticleSeoService::class)->buildCanonicalUrl(
                    (string) data_get($record, 'slug', ''),
                    (string) data_get($record, 'locale', 'en'),
                ),
                app(ArticleSeoService::class)->buildListUrl((string) data_get($record, 'locale', 'en')),
            ],
            'support_article' => $record instanceof SupportArticle
                ? [
                    trim((string) ($record->canonical_path ?: '/support/articles/'.$record->slug)),
                    '/support',
                ]
                : [],
            'interpretation_guide' => $record instanceof InterpretationGuide
                ? [
                    trim((string) ($record->canonical_path ?: '/support/guides/'.$record->slug)),
                    '/support',
                ]
                : [],
            'content_page' => $record instanceof ContentPage
                ? array_values(array_filter([
                    trim((string) ($record->canonical_path ?: $record->path ?: '/'.$record->slug)),
                    (string) data_get($record, 'kind') === ContentPage::KIND_HELP ? '/support' : null,
                ]))
                : [],
            'guide' => $record instanceof CareerGuide
                ? [app(CareerGuideSeoService::class)->buildCanonicalUrl($record)]
                : [],
            'job' => $record instanceof CareerJob
                ? [app(CareerJobSeoService::class)->buildCanonicalUrl($record, (string) data_get($record, 'locale', 'en'))]
                : [],
            default => [],
        }));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function postEvent(
        Request $request,
        string $action,
        string $endpoint,
        array $payload,
        string $alertLabel,
    ): void {
        $audit = app(AuditLogger::class);
        $meta = [
            'endpoint' => $endpoint,
            'source' => (string) ($payload['source'] ?? 'unknown'),
            'title' => data_get($payload, 'content.title', ''),
            'locale' => data_get($payload, 'content.locale', ''),
            'visibility' => data_get($payload, 'content.visibility', ''),
            'published_at' => data_get($payload, 'content.published_at'),
            'cache_urls' => data_get($payload, 'cache_signal.urls', []),
        ];

        try {
            $requestBuilder = Http::acceptJson()
                ->timeout((int) config('ops.content_release_observability.http_timeout_seconds', 5))
                ->withHeaders([
                    'X-FM-Content-Release-Source' => (string) ($payload['source'] ?? 'unknown'),
                ]);

            $secret = self::cacheInvalidationSecret();
            if ($secret !== '' && $action === 'content_release_cache_signal') {
                $requestBuilder = $requestBuilder->withHeaders([
                    'X-FM-Content-Release-Token' => $secret,
                ]);
            }

            $response = $requestBuilder->post($endpoint, $payload);

            if (! $response->successful()) {
                throw new RequestException($response);
            }

            $audit->log(
                $request,
                $action,
                (string) data_get($payload, 'content.type', 'content'),
                (string) data_get($payload, 'content.id', ''),
                $meta + ['http_status' => $response->status()],
                reason: 'cms_release_observability',
                result: 'success',
            );
        } catch (\Throwable $exception) {
            $audit->log(
                $request,
                $action,
                (string) data_get($payload, 'content.type', 'content'),
                (string) data_get($payload, 'content.id', ''),
                $meta + ['error' => trim($exception->getMessage())],
                reason: 'cms_release_observability',
                result: 'failed',
            );

            self::alertFailure($request, $payload, $endpoint, $alertLabel, $exception);
        }
    }

    private static function cacheInvalidationSecret(): string
    {
        return trim((string) config('ops.content_release_observability.cache_invalidation_secret', ''));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function alertFailure(
        Request $request,
        array $payload,
        string $endpoint,
        string $alertLabel,
        \Throwable $exception,
    ): void {
        $alertWebhook = trim((string) config('ops.alert.webhook', ''));
        if ($alertWebhook === '') {
            return;
        }

        $message = sprintf(
            '[CMS release follow-up failed] %s for %s #%s via %s -> %s (%s)',
            $alertLabel,
            (string) data_get($payload, 'content.type', 'content'),
            (string) data_get($payload, 'content.id', ''),
            (string) data_get($payload, 'source', 'unknown'),
            $endpoint,
            trim($exception->getMessage())
        );

        OpsAlertService::send($message);

        app(AuditLogger::class)->log(
            $request,
            'content_release_failure_alert',
            (string) data_get($payload, 'content.type', 'content'),
            (string) data_get($payload, 'content.id', ''),
            [
                'title' => data_get($payload, 'content.title', ''),
                'source' => data_get($payload, 'source', ''),
                'alert_label' => $alertLabel,
                'failed_endpoint' => $endpoint,
                'alert_webhook' => $alertWebhook,
            ],
            reason: 'cms_release_observability',
            result: 'success',
        );
    }
}
