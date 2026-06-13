<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\SeoImageBundle\SeoImageBundleImporter;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class MediaAssetsImportSeoImageBundle extends Command
{
    protected $signature = 'media-assets:import-seo-image-bundle
        {--package= : Path to a SEO source package directory}
        {--translation-group-id= : Expected translation_group_id}
        {--locales=zh-CN,en : Comma-separated locale list}
        {--dry-run : Validate and plan without writing DB, storage, or package files}
        {--json : Emit a JSON summary}
        {--write-resolved-package : Write a resolved package copy with CMS image metadata backfilled}
        {--resolved-output-dir= : Safe output directory for --write-resolved-package}
        {--expected-asset-prefix= : Expected asset_key prefix such as article.career.exploration}
        {--allow-update-existing : Allow updating an existing Media Library asset}';

    protected $description = 'Import SEO image asset bundles into Media Library and emit CMS draft image metadata.';

    public function handle(SeoImageBundleImporter $importer): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            $summary = $dryRun
                ? $importer->planFromDirectory($this->optionsPayload())
                : $importer->importFromDirectory($this->optionsPayload());
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
            'dry_run' => (bool) $this->option('dry-run'),
            'json' => (bool) $this->option('json'),
            'write_resolved_package' => (bool) $this->option('write-resolved-package'),
            'resolved_output_dir' => (string) $this->option('resolved-output-dir'),
            'expected_asset_prefix' => (string) $this->option('expected-asset-prefix'),
            'allow_update_existing' => (bool) $this->option('allow-update-existing'),
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
        $this->line('dry_run='.(($summary['dry_run'] ?? false) ? '1' : '0'));
        $this->line('action='.(string) ($summary['action'] ?? 'will_skip'));
        $this->line('would_write='.(($summary['would_write'] ?? false) ? '1' : '0'));
        $this->line('translation_group_id='.(string) ($summary['translation_group_id'] ?? ''));
        $this->line('assets_count='.(string) ($summary['assets_count'] ?? 0));
        $this->line('would_create='.(string) ($summary['would_create'] ?? 0));
        $this->line('would_update='.(string) ($summary['would_update'] ?? 0));
        $this->line('would_generate_variants='.(string) ($summary['would_generate_variants'] ?? 0));
        $this->line('would_patch_package='.(string) ($summary['would_patch_package'] ?? 0));
        $this->line('errors_count='.(string) count((array) ($summary['errors'] ?? [])));
        $this->line('warnings_count='.(string) count((array) ($summary['warnings'] ?? [])));

        foreach ((array) ($summary['assets'] ?? []) as $asset) {
            if (! is_array($asset)) {
                continue;
            }
            $this->line(sprintf(
                'asset=%s:%s:create=%s:update=%s:variants=%s',
                (string) ($asset['role'] ?? ''),
                (string) ($asset['asset_key'] ?? ''),
                ($asset['would_create'] ?? false) ? '1' : '0',
                ($asset['would_update'] ?? false) ? '1' : '0',
                ($asset['would_generate_variants'] ?? false) ? '1' : '0'
            ));
        }

        foreach ((array) ($summary['errors'] ?? []) as $error) {
            if (is_array($error)) {
                $this->line('validation_error='.$this->issueLine($error));
            }
        }
        foreach ((array) ($summary['warnings'] ?? []) as $warning) {
            if (is_array($warning)) {
                $this->line('validation_warning='.$this->issueLine($warning));
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function failureSummary(string $code, string $message): array
    {
        return [
            'ok' => false,
            'dry_run' => (bool) $this->option('dry-run'),
            'action' => 'will_skip',
            'would_write' => false,
            'errors' => [[
                'field' => 'command',
                'code' => $code,
                'message' => $message,
            ]],
            'warnings' => [],
            'assets' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $issue
     */
    private function issueLine(array $issue): string
    {
        return implode(':', [
            (string) ($issue['field'] ?? 'unknown'),
            (string) ($issue['code'] ?? 'unknown'),
            (string) ($issue['message'] ?? ''),
        ]);
    }
}
