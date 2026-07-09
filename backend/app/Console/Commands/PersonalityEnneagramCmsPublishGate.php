<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\EnneagramCmsPublishGateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityEnneagramCmsPublishGate extends Command
{
    private const OPERATOR_APPROVAL = 'ENNEAGRAM-ZH13-CMS-PUBLISH-GATE-01';

    private const WRITE_SAFETY_FLAGS = [
        'no-llms',
        'no-search-release',
    ];

    protected $signature = 'personality:enneagram-cms-publish-gate
        {--package= : Path to the Enneagram agent recommendation JSON package}
        {--dry-run : Plan publish gate without database writes}
        {--write : Publish matching Enneagram public content assets to live published state}
        {--json : Emit the full JSON summary}
        {--output= : Optional path to write the JSON summary}
        {--draft-only : (deprecated; retained for backward compatibility only)}
        {--no-publish : (deprecated; retained for backward compatibility only)}
        {--no-index : (deprecated; retained for backward compatibility only)}
        {--no-sitemap : (deprecated; retained for backward compatibility only)}
        {--no-llms : Required for --write; confirms no LLMs release}
        {--no-search-release : Required for --write; confirms no search release}
        {--operator-approved= : Required exact approval token for --write}';

    protected $description = 'Publish Enneagram public profile CMS assets to live published state with index/sitemap eligibility but no LLMs/search release.';

    public function handle(EnneagramCmsPublishGateService $service): int
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
    private function buildCommandSummary(EnneagramCmsPublishGateService $service): array
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

        // Verify package is enneagram framework
        $framework = (string) ($package['framework'] ?? '');
        if ($framework !== 'enneagram') {
            throw new RuntimeException('Only Enneagram framework packages are supported.');
        }

        $sourceSha256 = hash('sha256', $raw);
        $summary = $write
            ? $service->publish($package, $sourceSha256)
            : $service->plan($package, $sourceSha256);

        // After successful write, set publish_performed / index_attempted flags
        if ($write && ($summary['ok'] ?? false)) {
            $summary['publish_performed'] = true;
            $summary['index_attempted'] = true;

            // Ensure LLMs/search are NOT released
            if (($summary['published_count'] ?? 0) > 0) {
                $summary['llms_release_attempted'] = false;
                $summary['search_release_attempted'] = false;
            }
        }

        return array_merge($summary, [
            'package_path' => $packagePath,
            'command' => 'personality:enneagram-cms-publish-gate',
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
        $this->line('row_count='.(string) ($summary['row_count'] ?? 0));
        $this->line('would_publish_count='.(string) ($summary['would_publish_count'] ?? 0));
        $this->line('published_count='.(string) ($summary['published_count'] ?? 0));
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
            'artifact' => 'ENNEAGRAM-CMS-PUBLISH-GATE-CONTRACT-01',
            'command' => 'personality:enneagram-cms-publish-gate',
            'ok' => false,
            'status' => 'fail',
            'dry_run' => (bool) $this->option('dry-run'),
            'write' => (bool) $this->option('write'),
            'writes_attempted' => false,
            'writes_committed' => false,
            'publish_attempted' => false,
            'publish_performed' => false,
            'index_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'search_release_attempted' => false,
            'enqueue_attempted' => false,
            'external_calls_attempted' => false,
            'row_count' => 0,
            'would_publish_count' => 0,
            'published_count' => 0,
            'skipped_existing_count' => 0,
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
