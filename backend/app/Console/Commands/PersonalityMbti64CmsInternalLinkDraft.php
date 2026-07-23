<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\Mbti64CmsInternalLinkDraftWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityMbti64CmsInternalLinkDraft extends Command
{
    private const OPERATOR_APPROVAL = 'MBTI64-CMS-INTERNAL-LINK-DRAFT-01';

    private const WRITE_SAFETY_FLAGS = [
        'draft-only',
        'no-publish',
        'no-index',
        'no-sitemap',
        'no-llms',
        'no-search-release',
    ];

    protected $signature = 'personality:mbti64-cms-internal-link-draft
        {--graph= : Path to the MBTI64 internal-link graph JSON artifact}
        {--dry-run : Validate and plan without database writes}
        {--write : Create CMS internal-link draft revision rows}
        {--locale= : Bounded cohort locale; --write requires en}
        {--page-type= : Bounded source page type; --write requires variant}
        {--expected-rows= : Bounded source row count; --write requires 32}
        {--expected-edges= : Bounded active edge count; --write requires 64}
        {--json : Emit the full JSON summary}
        {--output= : Optional path to write the JSON summary}
        {--draft-only : Required for --write; confirms revision draft only}
        {--no-publish : Required for --write; confirms no publish action}
        {--no-index : Required for --write; confirms no indexability action}
        {--no-sitemap : Required for --write; confirms no sitemap action}
        {--no-llms : Required for --write; confirms no llms action}
        {--no-search-release : Required for --write; confirms no search release action}
        {--operator-approved= : Required exact approval token for --write}';

    protected $description = 'Create MBTI64 CMS internal-link graph draft revisions with explicit no-publish/no-index guards.';

    public function handle(Mbti64CmsInternalLinkDraftWriter $writer): int
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
    private function buildCommandSummary(Mbti64CmsInternalLinkDraftWriter $writer): array
    {
        $write = (bool) $this->option('write');
        $dryRun = (bool) $this->option('dry-run');

        if ($write && $dryRun) {
            throw new RuntimeException('--write cannot be combined with --dry-run.');
        }

        if (! $write && ! $dryRun) {
            throw new RuntimeException('Either --dry-run or --write is required.');
        }

        $this->assertBoundedOptions($write);

        if ($write) {
            $this->assertWriteGuards();
        }

        $graphPath = trim((string) $this->option('graph'));
        if ($graphPath === '') {
            throw new RuntimeException('--graph is required.');
        }

        $resolved = $this->resolvePath($graphPath);
        $raw = (string) File::get($resolved);
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Graph artifact must be a JSON object.');
        }

        $summary = $write
            ? $writer->write($decoded, hash('sha256', $raw), $this->optionsPayload())
            : $writer->plan($decoded, hash('sha256', $raw), $this->optionsPayload());

        return array_merge($summary, [
            'graph_path' => $resolved,
            'command' => 'personality:mbti64-cms-internal-link-draft',
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

    private function assertBoundedOptions(bool $write): void
    {
        $provided = [
            'locale' => trim((string) $this->option('locale')),
            'page-type' => trim((string) $this->option('page-type')),
            'expected-rows' => trim((string) $this->option('expected-rows')),
            'expected-edges' => trim((string) $this->option('expected-edges')),
        ];
        $anyProvided = array_filter(
            $provided,
            static fn (string $value): bool => $value !== ''
        ) !== [];

        if (! $write && ! $anyProvided) {
            return;
        }

        $expected = [
            'locale' => 'en',
            'page-type' => 'variant',
            'expected-rows' => '32',
            'expected-edges' => '64',
        ];
        foreach ($expected as $name => $value) {
            if ($provided[$name] !== $value) {
                throw new RuntimeException('--'.$name.'='.$value.' is required for the bounded MBTI64 draft cohort.');
            }
        }
    }

    private function resolvePath(string $path): string
    {
        $resolved = str_starts_with($path, '/')
            ? $path
            : base_path($path);

        if (! File::isFile($resolved)) {
            throw new RuntimeException('Graph artifact file not found: '.$resolved);
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
            'locale' => trim((string) $this->option('locale')),
            'page_type' => trim((string) $this->option('page-type')),
            'expected_rows' => (int) $this->option('expected-rows'),
            'expected_edges' => (int) $this->option('expected-edges'),
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
            'artifact' => 'MBTI64-CMS-INTERNAL-LINK-DRAFT-01',
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
