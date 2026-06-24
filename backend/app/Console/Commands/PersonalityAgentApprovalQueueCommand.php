<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\PersonalityAgentApprovalQueueWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityAgentApprovalQueueCommand extends Command
{
    private const OPERATOR_APPROVAL = 'PERSONALITY-AGENT-HUMAN-APPROVAL-QUEUE-01';

    protected $signature = 'personality:agent-approval-queue
        {--package= : Path to a personality agent recommendation package JSON artifact}
        {--qa= : Path to a personality agent QA JSON artifact}
        {--dry-run : Validate and plan approval queue rows without database writes}
        {--write : Create pending human approval queue rows}
        {--json : Emit the full JSON summary}
        {--output= : Optional path to write the JSON summary}
        {--operator-approved= : Required exact approval token for --write}';

    protected $description = 'Queue QA-passed personality agent recommendations for human approval without CMS, publish, index, or search side effects.';

    public function handle(PersonalityAgentApprovalQueueWriter $writer): int
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
    private function buildCommandSummary(PersonalityAgentApprovalQueueWriter $writer): array
    {
        $write = (bool) $this->option('write');
        $dryRun = (bool) $this->option('dry-run');

        if ($write && $dryRun) {
            throw new RuntimeException('--write cannot be combined with --dry-run.');
        }

        if (! $write && ! $dryRun) {
            throw new RuntimeException('Either --dry-run or --write is required.');
        }

        if ($write && (string) $this->option('operator-approved') !== self::OPERATOR_APPROVAL) {
            throw new RuntimeException('--operator-approved='.self::OPERATOR_APPROVAL.' is required with --write.');
        }

        $packagePath = $this->resolvePath(trim((string) $this->option('package')), 'Package');
        $qaPath = $this->resolvePath(trim((string) $this->option('qa')), 'QA artifact');
        $packageRaw = (string) File::get($packagePath);
        $qaRaw = (string) File::get($qaPath);
        $package = json_decode($packageRaw, true);
        $qa = json_decode($qaRaw, true);
        if (! is_array($package)) {
            throw new RuntimeException('Package must be a JSON object.');
        }
        if (! is_array($qa)) {
            throw new RuntimeException('QA artifact must be a JSON object.');
        }

        $metadata = [
            'package_path' => $packagePath,
            'qa_path' => $qaPath,
        ];

        $summary = $write
            ? $writer->write($package, $qa, hash('sha256', $packageRaw), hash('sha256', $qaRaw), $metadata)
            : $writer->plan($package, $qa, hash('sha256', $packageRaw), hash('sha256', $qaRaw), $metadata);

        return array_merge($summary, [
            'command' => 'personality:agent-approval-queue',
        ]);
    }

    private function resolvePath(string $path, string $label): string
    {
        if ($path === '') {
            throw new RuntimeException('--'.strtolower(str_replace(' artifact', '', $label)).' is required.');
        }

        $resolved = str_starts_with($path, '/')
            ? $path
            : base_path($path);

        if (! File::isFile($resolved)) {
            throw new RuntimeException($label.' file not found: '.$resolved);
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
        $this->line('planned_item_count='.(string) ($summary['planned_item_count'] ?? 0));
        $this->line('created_item_count='.(string) ($summary['created_item_count'] ?? 0));
        $this->line('skipped_existing_item_count='.(string) ($summary['skipped_existing_item_count'] ?? 0));
        $this->line('blocked_item_count='.(string) ($summary['blocked_item_count'] ?? 0));
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
            'artifact' => 'PERSONALITY-AGENT-HUMAN-APPROVAL-QUEUE-01',
            'status' => 'fail',
            'ok' => false,
            'dry_run' => (bool) $this->option('dry-run'),
            'write' => (bool) $this->option('write'),
            'writes_attempted' => false,
            'writes_committed' => false,
            'cms_write_attempted' => false,
            'cms_mutation_attempted' => false,
            'publish_attempted' => false,
            'index_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'search_release_attempted' => false,
            'enqueue_attempted' => false,
            'external_calls_attempted' => false,
            'errors' => [[
                'field' => 'command',
                'code' => $code,
                'message' => $message,
            ]],
            'warnings' => [],
        ];
    }
}
