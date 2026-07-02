<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\Mbti64ComparisonAssetsDryRunPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityMbti64ComparisonAssetsDryRun extends Command
{
    protected $signature = 'personality:mbti64-comparison-assets-dry-run
        {--source-dir=docs/seo/import-packages/mbti-comparison-content-assets-draft-20260702 : Directory containing comparisons/*_CMS_READY.json}
        {--json : Emit the full JSON summary}
        {--output= : Optional path to write the JSON summary}';

    protected $description = 'Validate GPT-generated MBTI64 A/T comparison content assets as a no-write CMS draft dry-run.';

    public function handle(Mbti64ComparisonAssetsDryRunPlanner $planner): int
    {
        try {
            $summary = $planner->planSourceDir((string) $this->option('source-dir'));
        } catch (RuntimeException $exception) {
            $summary = $this->failureSummary('runtime_error', $exception->getMessage());
        } catch (Throwable $exception) {
            $summary = $this->failureSummary('unexpected_error', $exception->getMessage());
        }

        $this->writeOutputFile($summary);
        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function emitSummary(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $summary,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ));

            return;
        }

        $this->line('ok='.(($summary['ok'] ?? false) ? '1' : '0'));
        $this->line('status='.(string) ($summary['status'] ?? 'fail'));
        $this->line('dry_run='.(($summary['dry_run'] ?? false) ? '1' : '0'));
        $this->line('writes_committed='.(($summary['writes_committed'] ?? false) ? '1' : '0'));
        $this->line('assets_found='.(string) ($summary['assets_found'] ?? 0));
        $this->line('valid_count='.(string) ($summary['valid_count'] ?? 0));
        $this->line('rows_would_stage='.(string) ($summary['rows_would_stage'] ?? 0));
        $this->line('errors_count='.(string) ($summary['errors_count'] ?? count((array) ($summary['errors'] ?? []))));
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function writeOutputFile(array $summary): void
    {
        $output = trim((string) $this->option('output'));
        if ($output === '') {
            return;
        }

        $resolved = str_starts_with($output, '/')
            ? $output
            : base_path($output);
        File::ensureDirectoryExists(dirname($resolved));
        File::put($resolved, ((string) json_encode(
            $summary,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        )).PHP_EOL);
    }

    /**
     * @return array<string,mixed>
     */
    private function failureSummary(string $code, string $message): array
    {
        return [
            'artifact' => 'MBTI64-COMPARISON-ASSETS-DRY-RUN-01',
            'status' => 'fail',
            'ok' => false,
            'dry_run' => true,
            'write' => false,
            'writes_committed' => false,
            'cms_write_attempted' => false,
            'publish_attempted' => false,
            'index_attempted' => false,
            'queue_enqueue_attempted' => false,
            'search_release_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'canonical_hreflang_jsonld_release_attempted' => false,
            'errors' => [[
                'field' => 'command',
                'code' => $code,
                'message' => $message,
            ]],
            'warnings' => [],
        ];
    }
}
