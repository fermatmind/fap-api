<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\SeoImageBundle\SeoImageBundleImporter;
use App\Support\PublicMediaUrlGuard;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class MediaAssetsSeoReleasePreflight extends Command
{
    protected $signature = 'media-assets:seo-release-preflight
        {--package= : Path to a SEO source package directory}
        {--translation-group-id= : Expected translation_group_id}
        {--locales=zh-CN,en : Comma-separated locale list}
        {--json : Emit a JSON summary}
        {--expected-asset-prefix= : Expected asset_key prefix such as article.career.exploration}';

    protected $description = 'Preflight SEO image bundles against Media Library production runner readiness.';

    public function handle(SeoImageBundleImporter $importer): int
    {
        try {
            $plan = $importer->planFromDirectory($this->optionsPayload());
            $summary = $this->preflightSummary($plan);
        } catch (RuntimeException $exception) {
            $summary = $this->failureSummary('runtime_error', $exception->getMessage());
        } catch (Throwable $exception) {
            $summary = $this->failureSummary('unexpected_error', $exception->getMessage());
        }

        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function optionsPayload(): array
    {
        $locales = array_values(array_filter(array_map(
            static fn (string $locale): string => trim($locale),
            explode(',', (string) $this->option('locales'))
        ), static fn (string $locale): bool => $locale !== ''));

        return [
            'package' => (string) $this->option('package'),
            'translation_group_id' => (string) $this->option('translation-group-id'),
            'locales' => $locales,
            'dry_run' => true,
            'json' => (bool) $this->option('json'),
            'write_resolved_package' => true,
            'expected_asset_prefix' => (string) $this->option('expected-asset-prefix'),
            'allow_update_existing' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function preflightSummary(array $plan): array
    {
        $errors = [];
        foreach ((array) ($plan['errors'] ?? []) as $error) {
            if (is_array($error)) {
                $errors[] = $error + ['source' => 'manifest'];
            }
        }

        foreach ($this->runtimeReadinessErrors() as $error) {
            $errors[] = $error;
        }

        $assets = array_values(array_filter((array) ($plan['assets'] ?? []), 'is_array'));
        $resumeRequired = false;
        foreach ($assets as $asset) {
            if ((bool) ($asset['resume_required'] ?? false)) {
                $resumeRequired = true;
                $errors[] = [
                    'field' => 'assets.'.$asset['asset_key'],
                    'code' => 'existing_asset_not_ready',
                    'message' => 'Existing Media Library asset is not synced and CDN-verified; resume with --allow-update-existing from the production runner.',
                ];
            }
        }

        $ok = $errors === [];
        $action = $ok ? 'seo_release_media_ready' : $this->failureAction($errors);
        $needsAllowUpdateExisting = $resumeRequired || count(array_filter(
            $assets,
            static fn (array $asset): bool => ($asset['existing_asset_id'] ?? null) !== null
        )) > 0;

        return [
            'ok' => $ok,
            'dry_run' => true,
            'action' => $action,
            'would_write' => false,
            'package' => $plan['package'] ?? null,
            'translation_group_id' => $plan['translation_group_id'] ?? null,
            'runtime' => $this->redactedRuntimeConfig(),
            'assets_count' => count($assets),
            'assets' => $assets,
            'resume_required' => $resumeRequired,
            'needs_allow_update_existing' => $needsAllowUpdateExisting,
            'next_command' => $this->nextCommand($needsAllowUpdateExisting),
            'errors' => $errors,
            'warnings' => $plan['warnings'] ?? [],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function runtimeReadinessErrors(): array
    {
        $errors = [];
        if ($this->appEnv() !== 'production') {
            $errors[] = [
                'field' => 'app.env',
                'code' => 'media_runtime_not_ready',
                'message' => 'SEO Media Library write stages must run from the production runner.',
            ];
        }

        if (! (bool) config('fap.media.oss_sync_enabled', false)) {
            $errors[] = [
                'field' => 'fap.media.oss_sync_enabled',
                'code' => 'media_runtime_not_ready',
                'message' => 'FAP_MEDIA_OSS_SYNC_ENABLED must be true.',
            ];
        }

        if (! (bool) config('fap.media.cdn_verify_enabled', false)) {
            $errors[] = [
                'field' => 'fap.media.cdn_verify_enabled',
                'code' => 'media_runtime_not_ready',
                'message' => 'FAP_MEDIA_CDN_VERIFY_ENABLED must be true.',
            ];
        }

        if (PublicMediaUrlGuard::canonicalAssetOrigin() !== PublicMediaUrlGuard::DEFAULT_ASSET_ORIGIN) {
            $errors[] = [
                'field' => 'fap.media.asset_origin',
                'code' => 'media_runtime_not_ready',
                'message' => 'FAP_MEDIA_ASSET_ORIGIN must be https://assets.fermatmind.com.',
            ];
        }

        $disk = trim((string) config('fap.media.oss_disk', ''));
        if ($disk === '' || config('filesystems.disks.'.$disk) === null) {
            $errors[] = [
                'field' => 'fap.media.oss_disk',
                'code' => 'media_runtime_not_ready',
                'message' => 'FAP_MEDIA_OSS_DISK must reference a configured filesystem disk.',
            ];
        }

        return $errors;
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function failureAction(array $errors): string
    {
        $codes = array_map(static fn (array $error): string => (string) ($error['code'] ?? ''), $errors);
        if (in_array('media_runtime_not_ready', $codes, true)) {
            return 'media_runtime_not_ready';
        }
        if (in_array('existing_asset_not_ready', $codes, true)) {
            return 'existing_asset_not_ready';
        }

        return 'manifest_importer_incompatible';
    }

    /**
     * @return array<string,mixed>
     */
    private function redactedRuntimeConfig(): array
    {
        $disk = trim((string) config('fap.media.oss_disk', ''));

        return [
            'app_env' => $this->appEnv(),
            'asset_origin' => PublicMediaUrlGuard::canonicalAssetOrigin(),
            'public_storage_prefix' => (string) config('fap.media.public_storage_prefix', ''),
            'oss_sync_enabled' => (bool) config('fap.media.oss_sync_enabled', false),
            'oss_disk' => $disk,
            'oss_disk_configured' => $disk !== '' && config('filesystems.disks.'.$disk) !== null,
            'oss_key_prefix' => (string) config('fap.media.oss_key_prefix', ''),
            'cdn_verify_enabled' => (bool) config('fap.media.cdn_verify_enabled', false),
            'cdn_verify_timeout_seconds' => (int) config('fap.media.cdn_verify_timeout_seconds', 5),
        ];
    }

    private function nextCommand(bool $needsAllowUpdateExisting): string
    {
        $parts = [
            'php artisan media-assets:import-seo-image-bundle',
            '--package='.escapeshellarg((string) $this->option('package')),
            '--translation-group-id='.escapeshellarg((string) $this->option('translation-group-id')),
            '--locales='.escapeshellarg((string) $this->option('locales')),
            '--expected-asset-prefix='.escapeshellarg((string) $this->option('expected-asset-prefix')),
            '--write-resolved-package',
            '--json',
        ];

        if ($needsAllowUpdateExisting) {
            $parts[] = '--allow-update-existing';
        }

        return implode(' ', $parts);
    }

    private function appEnv(): string
    {
        return trim((string) config('app.env', app()->environment()));
    }

    /**
     * @return array<string,mixed>
     */
    private function failureSummary(string $code, string $message): array
    {
        return [
            'ok' => false,
            'dry_run' => true,
            'action' => $code === 'runtime_error' ? 'manifest_importer_incompatible' : 'will_skip',
            'would_write' => false,
            'runtime' => $this->redactedRuntimeConfig(),
            'assets' => [],
            'errors' => [[
                'field' => 'command',
                'code' => $code,
                'message' => $message,
            ]],
            'warnings' => [],
        ];
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
        $this->line('assets_count='.(string) ($summary['assets_count'] ?? 0));
        $this->line('resume_required='.(($summary['resume_required'] ?? false) ? '1' : '0'));
        $this->line('needs_allow_update_existing='.(($summary['needs_allow_update_existing'] ?? false) ? '1' : '0'));
        $this->line('next_command='.(string) ($summary['next_command'] ?? ''));

        foreach ((array) ($summary['errors'] ?? []) as $error) {
            if (is_array($error)) {
                $this->line('preflight_error='.implode(':', [
                    (string) ($error['field'] ?? 'unknown'),
                    (string) ($error['code'] ?? 'unknown'),
                    (string) ($error['message'] ?? ''),
                ]));
            }
        }
    }
}
