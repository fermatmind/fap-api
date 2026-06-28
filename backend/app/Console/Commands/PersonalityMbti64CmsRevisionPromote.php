<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\Mbti64CmsRevisionPromotionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityMbti64CmsRevisionPromote extends Command
{
    private const OPERATOR_APPROVAL = 'MBTI64-BACKEND-PROMOTION-CONTRACT-01';

    private const WRITE_SAFETY_FLAGS = [
        'promote-live-content',
        'no-index',
        'no-sitemap',
        'no-llms',
        'no-search-release',
    ];

    protected $signature = 'personality:mbti64-cms-revision-promote
        {--package= : Path to the MBTI64 pilot V2.1 or agent projection JSON package}
        {--dry-run : Plan promotion without database writes}
        {--write : Promote latest matching revision snapshots to live CMS content fields}
        {--visible-query-backed-3 : Restrict agent projection promotion planning/write to the approved 3 query-backed visible MBTI64 URLs}
        {--fresh-query-backed-5 : Restrict agent projection promotion planning/write to the fresh 5 query-backed MBTI64 URLs}
        {--next-batch-6 : Restrict agent projection promotion planning/write to the approved next-batch 6 MBTI64 URLs}
        {--remaining-58 : Restrict agent projection promotion planning/write to the approved 58 remaining competitor-gap MBTI64 variant URLs}
        {--json : Emit the full JSON summary}
        {--output= : Optional path to write the JSON summary}
        {--promote-live-content : Required for --write; confirms live CMS content promotion intent}
        {--no-index : Required for --write; confirms no indexability action}
        {--no-sitemap : Required for --write; confirms no sitemap action}
        {--no-llms : Required for --write; confirms no llms action}
        {--no-search-release : Required for --write; confirms no search release or queue action}
        {--operator-approved= : Required exact approval token for --write}';

    protected $description = 'Promote MBTI64 CMS draft revisions into live CMS content with no index/search side effects.';

    public function handle(Mbti64CmsRevisionPromotionService $service): int
    {
        try {
            $summary = $this->buildCommandSummary($service);
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
     * @return array<string,mixed>
     */
    private function buildCommandSummary(Mbti64CmsRevisionPromotionService $service): array
    {
        $write = (bool) $this->option('write');
        $dryRun = (bool) $this->option('dry-run');

        if ($write && $dryRun) {
            throw new RuntimeException('--write cannot be combined with --dry-run.');
        }

        if (! $write && ! $dryRun) {
            throw new RuntimeException('Either --dry-run or --write is required.');
        }

        if ($write) {
            $this->assertWriteGuards();
        }

        $packagePath = trim((string) $this->option('package'));
        if ($packagePath === '') {
            throw new RuntimeException('--package is required.');
        }

        $resolved = $this->resolvePath($packagePath);
        $raw = (string) File::get($resolved);
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Package must be a JSON object.');
        }

        $summary = $write
            ? $service->promote($decoded, hash('sha256', $raw), $this->optionsPayload())
            : $service->plan($decoded, hash('sha256', $raw), $this->optionsPayload());

        return array_merge($summary, [
            'package_path' => $resolved,
            'command' => 'personality:mbti64-cms-revision-promote',
        ]);
    }

    private function assertWriteGuards(): void
    {
        foreach (self::WRITE_SAFETY_FLAGS as $flag) {
            if (! (bool) $this->option($flag)) {
                throw new RuntimeException('--'.$flag.' is required with --write.');
            }
        }

        if ((string) $this->option('operator-approved') !== self::OPERATOR_APPROVAL) {
            throw new RuntimeException('--operator-approved='.self::OPERATOR_APPROVAL.' is required with --write.');
        }
    }

    private function resolvePath(string $path): string
    {
        $resolved = str_starts_with($path, '/')
            ? $path
            : base_path($path);

        if (! File::isFile($resolved)) {
            throw new RuntimeException('Package file not found: '.$resolved);
        }

        return $resolved;
    }

    /**
     * @return array<string,mixed>
     */
    private function optionsPayload(): array
    {
        return [
            'dry_run' => (bool) $this->option('dry-run'),
            'write' => (bool) $this->option('write'),
            'promote_live_content' => (bool) $this->option('promote-live-content'),
            'no_index' => (bool) $this->option('no-index'),
            'no_sitemap' => (bool) $this->option('no-sitemap'),
            'no_llms' => (bool) $this->option('no-llms'),
            'no_search_release' => (bool) $this->option('no-search-release'),
            'operator_approved' => (string) $this->option('operator-approved'),
            'visible_query_backed_3' => (bool) $this->option('visible-query-backed-3'),
            'fresh_query_backed_5' => (bool) $this->option('fresh-query-backed-5'),
            'next_batch_6' => (bool) $this->option('next-batch-6'),
            'remaining_58' => (bool) $this->option('remaining-58'),
        ];
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
        $this->line('write='.(($summary['write'] ?? false) ? '1' : '0'));
        $this->line('writes_committed='.(($summary['writes_committed'] ?? false) ? '1' : '0'));
        $this->line('promoted_count='.(string) ($summary['promoted_count'] ?? 0));
        $this->line('skipped_existing_count='.(string) ($summary['skipped_existing_count'] ?? 0));
        $this->line('errors_count='.(string) count((array) ($summary['errors'] ?? [])));
        $this->line('warnings_count='.(string) count((array) ($summary['warnings'] ?? [])));
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
            'artifact' => 'MBTI64-BACKEND-PROMOTION-CONTRACT-01',
            'status' => 'fail',
            'ok' => false,
            'dry_run' => (bool) $this->option('dry-run'),
            'write' => (bool) $this->option('write'),
            'writes_committed' => false,
            'content_promotion_attempted' => false,
            'index_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'search_release_attempted' => false,
            'queue_enqueue_attempted' => false,
            'errors' => [[
                'field' => 'command',
                'code' => $code,
                'message' => $message,
            ]],
            'warnings' => [],
        ];
    }
}
