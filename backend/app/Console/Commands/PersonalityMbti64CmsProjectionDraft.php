<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\Mbti64CmsProjectionDraftWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityMbti64CmsProjectionDraft extends Command
{
    private const OPERATOR_APPROVAL = 'MBTI64-CMS-PROJECTION-DRAFT-88-01';

    private const WRITE_SAFETY_FLAGS = [
        'draft-only',
        'no-publish',
        'no-index',
        'no-sitemap',
        'no-llms',
        'no-search-release',
    ];

    protected $signature = 'personality:mbti64-cms-projection-draft
        {--package= : Path to the MBTI64 agent expansion recommendation JSON package}
        {--qa= : Path to the MBTI64 agent expansion QA JSON artifact}
        {--dry-run : Validate and plan without database writes}
        {--write : Create CMS projection draft revision rows}
        {--visible-query-backed-3 : Dry-run only; restrict planning to the approved 3 query-backed visible MBTI64 URLs}
        {--json : Emit the full JSON summary}
        {--output= : Optional path to write the JSON summary}
        {--draft-only : Required for --write; confirms revision draft only}
        {--no-publish : Required for --write; confirms no publish action}
        {--no-index : Required for --write; confirms no indexability action}
        {--no-sitemap : Required for --write; confirms no sitemap action}
        {--no-llms : Required for --write; confirms no llms action}
        {--no-search-release : Required for --write; confirms no search release action}
        {--operator-approved= : Required exact approval token for --write}';

    protected $description = 'Create MBTI64 agent projection CMS draft revisions with explicit no-publish/no-index guards.';

    public function handle(Mbti64CmsProjectionDraftWriter $writer): int
    {
        try {
            $summary = $this->buildCommandSummary($writer);
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
    private function buildCommandSummary(Mbti64CmsProjectionDraftWriter $writer): array
    {
        $write = (bool) $this->option('write');
        $dryRun = (bool) $this->option('dry-run');

        if ($write && $dryRun) {
            throw new RuntimeException('--write cannot be combined with --dry-run.');
        }

        if (! $write && ! $dryRun) {
            throw new RuntimeException('Either --dry-run or --write is required.');
        }

        if ($write && (bool) $this->option('visible-query-backed-3')) {
            throw new RuntimeException('--visible-query-backed-3 is dry-run only and cannot be combined with --write.');
        }

        if ($write) {
            $this->assertWriteGuards();
        }

        $packagePath = trim((string) $this->option('package'));
        if ($packagePath === '') {
            throw new RuntimeException('--package is required.');
        }

        $qaPath = trim((string) $this->option('qa'));
        if ($qaPath === '') {
            throw new RuntimeException('--qa is required.');
        }

        $resolvedPackage = $this->resolvePath($packagePath, 'Package');
        $resolvedQa = $this->resolvePath($qaPath, 'QA artifact');
        $packageRaw = (string) File::get($resolvedPackage);
        $qaRaw = (string) File::get($resolvedQa);
        $package = json_decode($packageRaw, true);
        $qa = json_decode($qaRaw, true);
        if (! is_array($package)) {
            throw new RuntimeException('Package must be a JSON object.');
        }
        if (! is_array($qa)) {
            throw new RuntimeException('QA artifact must be a JSON object.');
        }

        $summary = $write
            ? $writer->write($package, $qa, hash('sha256', $packageRaw), hash('sha256', $qaRaw), $this->optionsPayload())
            : $writer->plan($package, $qa, hash('sha256', $packageRaw), hash('sha256', $qaRaw), $this->optionsPayload());

        return array_merge($summary, [
            'package_path' => $resolvedPackage,
            'qa_path' => $resolvedQa,
            'command' => 'personality:mbti64-cms-projection-draft',
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

    private function resolvePath(string $path, string $label): string
    {
        $resolved = str_starts_with($path, '/')
            ? $path
            : base_path($path);

        if (! File::isFile($resolved)) {
            throw new RuntimeException($label.' file not found: '.$resolved);
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
            'visible_query_backed_3' => (bool) $this->option('visible-query-backed-3'),
            'draft_only' => (bool) $this->option('draft-only'),
            'no_publish' => (bool) $this->option('no-publish'),
            'no_index' => (bool) $this->option('no-index'),
            'no_sitemap' => (bool) $this->option('no-sitemap'),
            'no_llms' => (bool) $this->option('no-llms'),
            'no_search_release' => (bool) $this->option('no-search-release'),
            'operator_approved' => (string) $this->option('operator-approved'),
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
        $this->line('created_revision_count='.(string) ($summary['created_revision_count'] ?? 0));
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
            'artifact' => 'MBTI64-CMS-PROJECTION-DRAFT-88-01',
            'status' => 'fail',
            'ok' => false,
            'dry_run' => (bool) $this->option('dry-run'),
            'write' => (bool) $this->option('write'),
            'writes_committed' => false,
            'publish_attempted' => false,
            'index_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'search_release_attempted' => false,
            'errors' => [[
                'field' => 'command',
                'code' => $code,
                'message' => $message,
            ]],
            'warnings' => [],
        ];
    }
}
