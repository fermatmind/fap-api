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
    private const APPROVE_OPERATOR_APPROVAL = 'PERSONALITY-AGENT-APPROVAL-QUEUE-APPROVE-CONTRACT-01';

    protected $signature = 'personality:agent-approval-queue
        {--package= : Path to a personality agent recommendation package JSON artifact}
        {--qa= : Path to a personality agent QA JSON artifact}
        {--dry-run : Validate and plan approval queue rows without database writes}
        {--write : Create pending human approval queue rows}
        {--approve : Approve existing pending human approval queue item IDs without CMS writes}
        {--item-ids= : Comma-separated explicit personality_agent_approval_items IDs for --approve}
        {--framework= : Required framework lock for --approve}
        {--source-package-sha256= : Required source package SHA256 lock for --approve}
        {--qa-sha256= : Required QA artifact SHA256 lock for --approve}
        {--json : Emit the full JSON summary}
        {--output= : Optional path to write the JSON summary}
        {--operator-approved= : Required exact approval token for --write or --approve}';

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
        $approve = (bool) $this->option('approve');

        if (($write ? 1 : 0) + ($dryRun ? 1 : 0) + ($approve ? 1 : 0) !== 1) {
            throw new RuntimeException('Exactly one of --dry-run, --write, or --approve is required.');
        }

        if ($approve) {
            if ((string) $this->option('operator-approved') !== self::APPROVE_OPERATOR_APPROVAL) {
                throw new RuntimeException('--operator-approved='.self::APPROVE_OPERATOR_APPROVAL.' is required with --approve.');
            }

            return array_merge($writer->approveItems(
                $this->parseItemIds((string) $this->option('item-ids')),
                trim((string) $this->option('framework')),
                trim((string) $this->option('source-package-sha256')),
                trim((string) $this->option('qa-sha256')),
                [
                    'item_ids' => (string) $this->option('item-ids'),
                ],
            ), [
                'command' => 'personality:agent-approval-queue',
            ]);
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
        $this->line('approved_item_count='.(string) ($summary['approved_item_count'] ?? 0));
        $this->line('skipped_existing_approved_item_count='.(string) ($summary['skipped_existing_approved_item_count'] ?? 0));
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
            'approve' => (bool) $this->option('approve'),
            'writes_attempted' => false,
            'writes_committed' => false,
            'approval_state_mutation_attempted' => false,
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

    /**
     * @return list<int>
     */
    private function parseItemIds(string $value): array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $part): bool => $part !== ''));
        if ($parts === []) {
            throw new RuntimeException('--item-ids is required with --approve.');
        }

        $ids = [];
        foreach ($parts as $part) {
            if (! ctype_digit($part) || (int) $part <= 0) {
                throw new RuntimeException('--item-ids must contain positive integer IDs only.');
            }
            $ids[] = (int) $part;
        }

        $unique = array_values(array_unique($ids));
        sort($unique);

        if (count($unique) !== count($ids)) {
            throw new RuntimeException('--item-ids must not contain duplicate IDs.');
        }

        return $unique;
    }
}
