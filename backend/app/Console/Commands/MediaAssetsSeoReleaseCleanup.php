<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\MediaAsset;
use App\Services\Cms\MediaAssetStorageSyncService;
use App\Support\PublicMediaUrlGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

final class MediaAssetsSeoReleaseCleanup extends Command
{
    protected $signature = 'media-assets:seo-release-cleanup
        {--asset-prefix= : Media asset key prefix, e.g. article.enneagram.topic}
        {--translation-group-id= : Optional article translation_group_id for CMS reference readback}
        {--dry-run : Audit only; this is also the default when --resync is not provided}
        {--resync : Re-run Media Library OSS sync and CDN verification for matching assets}
        {--json : Emit a JSON summary}';

    protected $description = 'Audit and resync half-failed SEO Media Library assets without deleting records.';

    public function handle(MediaAssetStorageSyncService $syncService): int
    {
        try {
            $summary = $this->runCleanup($syncService);
        } catch (Throwable $throwable) {
            $summary = [
                'ok' => false,
                'action' => 'unexpected_error',
                'would_write' => false,
                'errors' => [[
                    'field' => 'command',
                    'code' => 'unexpected_error',
                    'message' => $throwable->getMessage(),
                ]],
                'warnings' => [],
                'assets' => [],
            ];
        }

        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function runCleanup(MediaAssetStorageSyncService $syncService): array
    {
        $prefix = strtolower(trim((string) $this->option('asset-prefix')));
        if ($prefix === '') {
            return $this->failureSummary('asset_prefix_required', '--asset-prefix is required.');
        }

        $dryRun = (bool) $this->option('dry-run') || ! (bool) $this->option('resync');
        $resync = (bool) $this->option('resync') && ! $dryRun;
        $errors = [];

        if ($resync) {
            $errors = $this->runtimeReadinessErrors();
            if ($errors !== []) {
                return [
                    'ok' => false,
                    'action' => 'media_runtime_not_ready',
                    'dry_run' => false,
                    'would_write' => false,
                    'asset_prefix' => $prefix,
                    'translation_group_id' => $this->translationGroupId(),
                    'assets' => $this->assetPayloads($prefix),
                    'errors' => $errors,
                    'warnings' => [],
                    'deletion_held' => true,
                ];
            }
        }

        $assets = $this->matchingAssets($prefix);
        $payloads = [];
        foreach ($assets as $asset) {
            $fresh = $resync ? $syncService->syncAndVerify($asset) : $asset->fresh('variants');
            $payloads[] = $this->assetPayload($fresh ?? $asset);
        }

        $notReady = count(array_filter($payloads, static fn (array $asset): bool => ! (bool) ($asset['ready_for_cms'] ?? false)));

        return [
            'ok' => $resync ? $notReady === 0 : true,
            'action' => $resync ? 'resynced_media_assets' : 'cleanup_dry_run',
            'dry_run' => $dryRun,
            'would_write' => $resync,
            'asset_prefix' => $prefix,
            'translation_group_id' => $this->translationGroupId(),
            'assets_count' => count($payloads),
            'not_ready_count' => $notReady,
            'assets' => $payloads,
            'errors' => $resync && $notReady > 0 ? [[
                'field' => 'assets',
                'code' => 'media_asset_cdn_not_ready',
                'message' => 'One or more assets remain unverified after resync.',
            ]] : [],
            'warnings' => [],
            'deletion_held' => true,
        ];
    }

    /**
     * @return list<MediaAsset>
     */
    private function matchingAssets(string $prefix): array
    {
        return MediaAsset::query()
            ->withoutGlobalScopes()
            ->with('variants')
            ->where('org_id', 0)
            ->where('asset_key', 'like', $prefix.'%')
            ->orderBy('asset_key')
            ->get()
            ->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function assetPayloads(string $prefix): array
    {
        return array_map(fn (MediaAsset $asset): array => $this->assetPayload($asset), $this->matchingAssets($prefix));
    }

    /**
     * @return array<string,mixed>
     */
    private function assetPayload(MediaAsset $asset): array
    {
        $asset->loadMissing('variants');
        $url = PublicMediaUrlGuard::canonicalMediaUrl((string) $asset->disk, $asset->path, $asset->url);
        $ready = (string) $asset->sync_status === MediaAsset::SYNC_SYNCED
            && (string) $asset->cdn_status === MediaAsset::CDN_VERIFIED
            && $url !== null;

        return [
            'id' => (int) $asset->id,
            'asset_key' => (string) $asset->asset_key,
            'status' => (string) $asset->status,
            'is_public' => (bool) $asset->is_public,
            'sync_status' => (string) $asset->sync_status,
            'cdn_status' => (string) $asset->cdn_status,
            'last_error' => $asset->last_error,
            'url' => $url,
            'ready_for_cms' => $ready,
            'cms_reference_status' => $this->cmsReferenceStatus((string) $asset->asset_key),
            'public_url_head' => $this->headStatus($url),
            'variants' => $asset->variants->map(fn ($variant): array => [
                'variant_key' => (string) $variant->variant_key,
                'sync_status' => (string) $variant->sync_status,
                'cdn_status' => (string) $variant->cdn_status,
                'url' => PublicMediaUrlGuard::canonicalMediaUrl((string) $asset->disk, $variant->path, $variant->url),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function cmsReferenceStatus(string $assetKey): array
    {
        $query = Article::query()
            ->withoutGlobalScopes()
            ->with('seoMeta')
            ->select(['id', 'slug', 'locale', 'translation_group_id', 'cover_image_variants']);

        if ($this->translationGroupId() !== '') {
            $query->where('translation_group_id', $this->translationGroupId());
        }

        $references = [];
        foreach ($query->get() as $article) {
            $variants = is_array($article->cover_image_variants) ? $article->cover_image_variants : [];
            $schema = is_array($article->seoMeta?->schema_json) ? $article->seoMeta->schema_json : [];
            $encoded = json_encode([$variants, $schema], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (! is_string($encoded) || ! str_contains($encoded, $assetKey)) {
                continue;
            }

            $references[] = [
                'article_id' => (int) $article->id,
                'locale' => (string) $article->locale,
                'slug' => (string) $article->slug,
                'translation_group_id' => (string) $article->translation_group_id,
            ];
        }

        return [
            'referenced' => $references !== [],
            'references_count' => count($references),
            'references' => $references,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function headStatus(?string $url): array
    {
        if ($url === null) {
            return [
                'checked' => false,
                'ok' => false,
                'status' => null,
                'content_type' => null,
            ];
        }

        try {
            $response = Http::timeout(max(1, (int) config('fap.media.cdn_verify_timeout_seconds', 5)))
                ->withoutRedirecting()
                ->head($url);
            $contentType = strtolower((string) $response->header('Content-Type', ''));

            return [
                'checked' => true,
                'ok' => $response->successful() && str_starts_with($contentType, 'image/'),
                'status' => $response->status(),
                'content_type' => $contentType,
            ];
        } catch (Throwable $throwable) {
            return [
                'checked' => true,
                'ok' => false,
                'status' => null,
                'content_type' => null,
                'error' => mb_substr($throwable->getMessage(), 0, 500),
            ];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function runtimeReadinessErrors(): array
    {
        $errors = [];
        if (! (bool) config('fap.media.oss_sync_enabled', false)) {
            $errors[] = [
                'field' => 'fap.media.oss_sync_enabled',
                'code' => 'media_runtime_not_ready',
                'message' => 'FAP_MEDIA_OSS_SYNC_ENABLED must be true before resync.',
            ];
        }
        if (! (bool) config('fap.media.cdn_verify_enabled', false)) {
            $errors[] = [
                'field' => 'fap.media.cdn_verify_enabled',
                'code' => 'media_runtime_not_ready',
                'message' => 'FAP_MEDIA_CDN_VERIFY_ENABLED must be true before resync.',
            ];
        }

        return $errors;
    }

    /**
     * @return array<string,mixed>
     */
    private function failureSummary(string $code, string $message): array
    {
        return [
            'ok' => false,
            'action' => 'will_skip',
            'dry_run' => true,
            'would_write' => false,
            'asset_prefix' => (string) $this->option('asset-prefix'),
            'translation_group_id' => $this->translationGroupId(),
            'assets' => [],
            'errors' => [[
                'field' => 'command',
                'code' => $code,
                'message' => $message,
            ]],
            'warnings' => [],
            'deletion_held' => true,
        ];
    }

    private function translationGroupId(): string
    {
        return trim((string) $this->option('translation-group-id'));
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function emitSummary(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

            return;
        }

        $this->line('ok='.(($summary['ok'] ?? false) ? '1' : '0'));
        $this->line('action='.(string) ($summary['action'] ?? 'will_skip'));
        $this->line('dry_run='.(($summary['dry_run'] ?? true) ? '1' : '0'));
        $this->line('would_write='.(($summary['would_write'] ?? false) ? '1' : '0'));
        $this->line('asset_prefix='.(string) ($summary['asset_prefix'] ?? ''));
        $this->line('assets_count='.(string) ($summary['assets_count'] ?? 0));
        $this->line('not_ready_count='.(string) ($summary['not_ready_count'] ?? 0));
        $this->line('deletion_held='.(($summary['deletion_held'] ?? true) ? '1' : '0'));
    }
}
