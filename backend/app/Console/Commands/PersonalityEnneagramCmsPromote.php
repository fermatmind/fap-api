<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\EnneagramCmsPromotionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityEnneagramCmsPromote extends Command
{
    private const OPERATOR_APPROVAL = 'ENNEAGRAM-CMS-PROMOTION-CONTRACT-01';

    private const WRITE_SAFETY_FLAGS = [
        'promote-live-content',
        'no-publish',
        'no-index',
        'no-sitemap',
        'no-llms',
        'no-search-release',
    ];

    protected $signature = 'personality:enneagram-cms-promote
        {--package= : Path to the Enneagram agent recommendation JSON package}
        {--dry-run : Plan promotion without database writes}
        {--write : Promote matching Enneagram public content draft assets to live content-ready state}
        {--json : Emit the full JSON summary}
        {--output= : Optional path to write the JSON summary}
        {--promote-live-content : Required for --write; confirms live content promotion intent}
        {--no-publish : Required for --write; confirms no published/index release state}
        {--no-index : Required for --write; confirms no indexability action}
        {--no-sitemap : Required for --write; confirms no sitemap action}
        {--no-llms : Required for --write; confirms no llms action}
        {--no-search-release : Required for --write; confirms no search release action}
        {--operator-approved= : Required exact approval token for --write}';

    protected $description = 'Promote Enneagram public profile CMS draft assets into live content-ready state with no index/search side effects.';

    public function handle(EnneagramCmsPromotionService $service): int
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
    private function buildCommandSummary(EnneagramCmsPromotionService $service): array
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

        $packagePath = $this->resolvePath(trim((string) $this->option('package')));
        $raw = (string) File::get($packagePath);
        $package = json_decode($raw, true);
        if (! is_array($package)) {
            throw new RuntimeException('Package must be a JSON object.');
        }

        $sourceSha256 = hash('sha256', $raw);
        $summary = $write
            ? $service->promote($package, $sourceSha256)
            : $service->plan($package, $sourceSha256);

        return array_merge($summary, [
            'package_path' => $packagePath,
            'command' => 'personality:enneagram-cms-promote',
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
        if ($path === '') {
            throw new RuntimeException('--package is required.');
        }

        $resolved = str_starts_with($path, '/')
            ? $path
            : base_path($path);

        if (! File::isFile($resolved)) {
            throw new RuntimeException('Package file not found: '.$resolved);
        }

        return $resolved;
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
        $this->line('would_promote_count='.(string) ($summary['would_promote_count'] ?? 0));
        $this->line('promoted_count='.(string) ($summary['promoted_count'] ?? 0));
        $this->line('skipped_existing_count='.(string) ($summary['skipped_existing_count'] ?? 0));
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
            'artifact' => 'ENNEAGRAM-CMS-PROMOTION-CONTRACT-01',
            'command' => 'personality:enneagram-cms-promote',
            'ok' => false,
            'status' => 'fail',
            'dry_run' => (bool) $this->option('dry-run'),
            'write' => (bool) $this->option('write'),
            'writes_attempted' => (bool) $this->option('write'),
            'writes_committed' => false,
            'content_promotion_attempted' => false,
            'publish_attempted' => false,
            'index_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'search_release_attempted' => false,
            'enqueue_attempted' => false,
            'external_calls_attempted' => false,
            'row_count' => 0,
            'would_promote_count' => 0,
            'promoted_count' => 0,
            'errors' => [
                [
                    'code' => $code,
                    'message' => $message,
                ],
            ],
            'warnings' => [],
        ];
    }
}
