<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\PersonalityAgentApprovalQueueReadModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Throwable;

final class PersonalityAgentApprovalQueueReviewCommand extends Command
{
    protected $signature = 'personality:agent-approval-queue-review
        {--framework= : Optional framework filter: mbti64, big_five, or enneagram}
        {--approval-state=pending : Approval state filter: pending, approved, rejected, or all}
        {--limit=50 : Maximum rows to return, from 1 to 200}
        {--json : Emit the full JSON read model}
        {--output= : Optional path to write the JSON read model}';

    protected $description = 'Read pending personality agent recommendation approvals and QA risk state without writing CMS or approval records.';

    public function handle(PersonalityAgentApprovalQueueReadModel $readModel): int
    {
        try {
            $payload = $readModel->read(
                $this->nullableOption('framework'),
                (string) $this->option('approval-state'),
                (int) $this->option('limit')
            );
        } catch (InvalidArgumentException $exception) {
            $payload = $this->failureSummary('invalid_option', $exception->getMessage());
        } catch (Throwable $exception) {
            $payload = $this->failureSummary('unexpected_error', $exception->getMessage());
        }

        $this->writeOutputFile($payload);
        $this->emitPayload($payload);

        return ($payload['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    private function nullableOption(string $name): ?string
    {
        $value = trim((string) $this->option($name));

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emitPayload(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ));

            return;
        }

        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];

        $this->line('ok='.(($payload['ok'] ?? false) ? '1' : '0'));
        $this->line('status='.(string) ($payload['status'] ?? 'fail'));
        $this->line('read_only='.(($payload['read_only'] ?? false) ? '1' : '0'));
        $this->line('framework='.(string) ($filters['framework'] ?? 'all'));
        $this->line('approval_state='.(string) ($filters['approval_state'] ?? 'pending'));
        $this->line('matched_item_count='.(string) ($summary['matched_item_count'] ?? 0));
        $this->line('returned_item_count='.(string) ($summary['returned_item_count'] ?? 0));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function writeOutputFile(array $payload): void
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
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        )).PHP_EOL);
    }

    /**
     * @return array<string,mixed>
     */
    private function failureSummary(string $code, string $message): array
    {
        return [
            'artifact' => 'PERSONALITY-AGENT-OPS-REVIEW-SURFACE-01',
            'status' => 'fail',
            'ok' => false,
            'read_only' => true,
            'filters' => [
                'framework' => $this->nullableOption('framework'),
                'approval_state' => (string) $this->option('approval-state'),
                'limit' => (int) $this->option('limit'),
            ],
            'summary' => [
                'matched_item_count' => 0,
                'returned_item_count' => 0,
            ],
            'items' => [],
            'safety_boundary' => [
                'read_only' => true,
                'approval_state_mutation_attempted' => false,
                'cms_write_attempted' => false,
                'cms_mutation_attempted' => false,
                'cms_live_promotion_attempted' => false,
                'publish_attempted' => false,
                'index_attempted' => false,
                'sitemap_llms_release_attempted' => false,
                'search_release_attempted' => false,
                'enqueue_attempted' => false,
                'external_calls_attempted' => false,
            ],
            'warnings' => [],
            'errors' => [[
                'field' => 'command',
                'code' => $code,
                'message' => $message,
            ]],
        ];
    }
}
