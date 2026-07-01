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

    private const VISIBLE_QUERY_BACKED_3_OPERATOR_APPROVAL = 'MBTI64-CMS-PROJECTION-DRAFT-VISIBLE-3-WRITE-01';

    private const FRESH_QUERY_BACKED_3_OPERATOR_APPROVAL = 'MBTI64-CMS-PROJECTION-DRAFT-FRESH-3-WRITE-01';

    private const FRESH_QUERY_BACKED_5_OPERATOR_APPROVAL = 'MBTI64-CMS-PROJECTION-DRAFT-FRESH-5-WRITE-01';

    private const AGENT_BATCH_OPERATOR_APPROVAL = 'MBTI64-AGENT-CMS-DRAFT-BATCH-SAFE-WRITER-01';

    private const NEXT_BATCH_6_OPERATOR_APPROVAL = 'PERSONALITY-AGENT-CMS-DRAFT-NEXT-BATCH-6-WRITE-01';

    private const REMAINING_58_OPERATOR_APPROVAL = 'MBTI64-REMAINING-58-COMPETITOR-GAP-CMS-DRAFT-WRITE-01';

    private const REMAINING_58_V2_MODULE_REWRITE_OPERATOR_APPROVAL = 'MBTI64-REMAINING-58-COMPETITOR-GAP-V2-MODULE-DRAFT-REWRITE-01';

    private const V8_5_V5_BILINGUAL_64_OPERATOR_APPROVAL = 'MBTI64-ZH32-EN32-V8_5-V5-CMS-DRAFT-WRITE-01';

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
        {--visible-query-backed-3 : Restrict planning/write to the approved 3 query-backed visible MBTI64 URLs}
        {--fresh-query-backed-3 : Restrict planning/write to the fresh 3 query-backed MBTI64 URLs}
        {--fresh-query-backed-5 : Restrict planning/write to the fresh 5 query-backed MBTI64 URLs}
        {--next-batch-6 : Restrict planning/write to the approved 6 next-batch MBTI64 URLs}
        {--remaining-58 : Restrict planning/write to the approved 58 remaining competitor-gap MBTI64 variant URLs}
        {--rewrite-existing-v2-modules : For --remaining-58 only, create patched draft revisions when same-hash existing drafts lack first-class V2 module fields}
        {--v8-5-v5-bilingual-64 : Restrict planning/write to the fixed 64 MBTI64 V8.5/V5 bilingual variant URLs}
        {--agent-batch-size= : Restrict planning/write to an artifact-order batch; only 5 or 10 are allowed}
        {--agent-batch-offset= : Zero-based artifact-order offset for --agent-batch-size; defaults to 0}
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

        $expectedApproval = match (true) {
            (bool) $this->option('visible-query-backed-3') => self::VISIBLE_QUERY_BACKED_3_OPERATOR_APPROVAL,
            (bool) $this->option('fresh-query-backed-3') => self::FRESH_QUERY_BACKED_3_OPERATOR_APPROVAL,
            (bool) $this->option('fresh-query-backed-5') => self::FRESH_QUERY_BACKED_5_OPERATOR_APPROVAL,
            (bool) $this->option('next-batch-6') => self::NEXT_BATCH_6_OPERATOR_APPROVAL,
            (bool) $this->option('remaining-58') && (bool) $this->option('rewrite-existing-v2-modules') => self::REMAINING_58_V2_MODULE_REWRITE_OPERATOR_APPROVAL,
            (bool) $this->option('remaining-58') => self::REMAINING_58_OPERATOR_APPROVAL,
            (bool) $this->option('v8-5-v5-bilingual-64') => self::V8_5_V5_BILINGUAL_64_OPERATOR_APPROVAL,
            $this->agentBatchRequested() => self::AGENT_BATCH_OPERATOR_APPROVAL,
            default => self::OPERATOR_APPROVAL,
        };

        if ((string) $this->option('operator-approved') !== $expectedApproval) {
            throw new RuntimeException('--operator-approved='.$expectedApproval.' is required with --write.');
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
            'fresh_query_backed_3' => (bool) $this->option('fresh-query-backed-3'),
            'fresh_query_backed_5' => (bool) $this->option('fresh-query-backed-5'),
            'next_batch_6' => (bool) $this->option('next-batch-6'),
            'remaining_58' => (bool) $this->option('remaining-58'),
            'rewrite_existing_v2_modules' => (bool) $this->option('rewrite-existing-v2-modules'),
            'v8_5_v5_bilingual_64' => (bool) $this->option('v8-5-v5-bilingual-64'),
            'agent_batch_size' => trim((string) $this->option('agent-batch-size')),
            'agent_batch_offset' => trim((string) $this->option('agent-batch-offset')),
            'draft_only' => (bool) $this->option('draft-only'),
            'no_publish' => (bool) $this->option('no-publish'),
            'no_index' => (bool) $this->option('no-index'),
            'no_sitemap' => (bool) $this->option('no-sitemap'),
            'no_llms' => (bool) $this->option('no-llms'),
            'no_search_release' => (bool) $this->option('no-search-release'),
            'operator_approved' => (string) $this->option('operator-approved'),
        ];
    }

    private function agentBatchRequested(): bool
    {
        return trim((string) $this->option('agent-batch-size')) !== ''
            || trim((string) $this->option('agent-batch-offset')) !== '';
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
