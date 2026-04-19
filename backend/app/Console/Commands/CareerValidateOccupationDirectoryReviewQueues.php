<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Throwable;

final class CareerValidateOccupationDirectoryReviewQueues extends Command
{
    private const TRANSLATION_DECISIONS = ['approve', 'edit', 'reject', 'merge_alias'];

    private const ALIAS_DECISIONS = ['alias_existing', 'create_separate', 'reject', 'needs_research'];

    private const CHILD_ROLE_DECISIONS = ['alias_parent', 'child_role', 'create_top_level', 'reject', 'needs_research'];

    protected $signature = 'career:validate-occupation-directory-review-queues
        {--queue-dir= : Directory containing exported review queue CSV files}
        {--allow-pending : Return success even when review_decision cells are still blank}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Validate occupation-directory review queue decisions before continuation.';

    public function handle(): int
    {
        try {
            $queueDir = trim((string) $this->option('queue-dir'));
            if ($queueDir === '') {
                throw new \RuntimeException('--queue-dir is required.');
            }
            if (! is_dir($queueDir)) {
                throw new \RuntimeException('--queue-dir does not exist: '.$queueDir);
            }

            $summary = [
                'queue_dir' => $queueDir,
                'allow_pending' => (bool) $this->option('allow-pending'),
                'files' => [
                    'translation_review_queue' => $this->validateQueue(
                        $queueDir.'/translation_review_queue.csv',
                        self::TRANSLATION_DECISIONS,
                        fn (array $row): array => $this->validateTranslationDecision($row),
                    ),
                    'alias_review_decisions' => $this->validateQueue(
                        $queueDir.'/alias_review_decisions.csv',
                        self::ALIAS_DECISIONS,
                        fn (array $row): array => $this->validateAliasDecision($row),
                    ),
                    'child_role_review_decisions' => $this->validateQueue(
                        $queueDir.'/child_role_review_decisions.csv',
                        self::CHILD_ROLE_DECISIONS,
                        fn (array $row): array => $this->validateChildRoleDecision($row),
                    ),
                ],
            ];

            $summary['total_rows'] = array_sum(array_column($summary['files'], 'rows'));
            $summary['pending_total'] = array_sum(array_column($summary['files'], 'pending'));
            $summary['invalid_total'] = array_sum(array_column($summary['files'], 'invalid'));
            $summary['missing_required_total'] = array_sum(array_column($summary['files'], 'missing_required'));
            $summary['ready_for_continuation'] = $summary['invalid_total'] === 0
                && $summary['missing_required_total'] === 0
                && ($summary['pending_total'] === 0 || (bool) $this->option('allow-pending'));

            if ((bool) $this->option('json')) {
                $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->line('total_rows='.$summary['total_rows']);
                $this->line('pending_total='.$summary['pending_total']);
                $this->line('invalid_total='.$summary['invalid_total']);
                $this->line('missing_required_total='.$summary['missing_required_total']);
                $this->line('ready_for_continuation='.($summary['ready_for_continuation'] ? '1' : '0'));
            }

            if (! $summary['ready_for_continuation']) {
                $this->error('review queue validation failed');

                return self::FAILURE;
            }

            $this->info('review queue validation complete');

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  list<string>  $allowedDecisions
     * @param  callable(array<string, string>): list<string>  $requiredValidator
     * @return array<string, mixed>
     */
    private function validateQueue(string $path, array $allowedDecisions, callable $requiredValidator): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException('Review queue file does not exist: '.$path);
        }

        $rows = $this->readCsv($path);
        $decisionCounts = array_fill_keys($allowedDecisions, 0);
        $pending = 0;
        $invalid = [];
        $missingRequired = [];

        foreach ($rows as $index => $row) {
            $lineNumber = $index + 2;
            $decision = trim((string) ($row['review_decision'] ?? ''));
            if ($decision === '') {
                $pending++;

                continue;
            }

            if (! in_array($decision, $allowedDecisions, true)) {
                $invalid[] = [
                    'line' => $lineNumber,
                    'review_decision' => $decision,
                ];

                continue;
            }

            $decisionCounts[$decision]++;
            $missing = $requiredValidator($row);
            if ($missing !== []) {
                $missingRequired[] = [
                    'line' => $lineNumber,
                    'review_decision' => $decision,
                    'missing' => $missing,
                ];
            }
        }

        return [
            'path' => $path,
            'rows' => count($rows),
            'pending' => $pending,
            'invalid' => count($invalid),
            'missing_required' => count($missingRequired),
            'decision_counts' => $decisionCounts,
            'invalid_examples' => array_slice($invalid, 0, 20),
            'missing_required_examples' => array_slice($missingRequired, 0, 20),
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open CSV file: '.$path);
        }

        $header = null;
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if ($header === null) {
                if (isset($row[0])) {
                    $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
                }
                $header = array_map(static fn ($value): string => trim((string) $value), $row);

                continue;
            }

            $assoc = [];
            foreach ($header as $index => $key) {
                if ($key === '') {
                    continue;
                }
                $assoc[$key] = (string) ($row[$index] ?? '');
            }
            if ($assoc !== []) {
                $rows[] = $assoc;
            }
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function validateTranslationDecision(array $row): array
    {
        if (($row['review_decision'] ?? '') !== 'edit') {
            return [];
        }

        $missing = [];
        if (trim((string) ($row['approved_title_zh'] ?? '')) === '') {
            $missing[] = 'approved_title_zh';
        }
        if (trim((string) ($row['approved_title_en'] ?? '')) === '') {
            $missing[] = 'approved_title_en';
        }

        return $missing;
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function validateAliasDecision(array $row): array
    {
        if (($row['review_decision'] ?? '') !== 'alias_existing') {
            return [];
        }

        return trim((string) ($row['approved_target_slug'] ?? '')) === ''
            ? ['approved_target_slug']
            : [];
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function validateChildRoleDecision(array $row): array
    {
        if (! in_array(($row['review_decision'] ?? ''), ['alias_parent', 'child_role'], true)) {
            return [];
        }

        return trim((string) ($row['approved_parent_slug'] ?? '')) === ''
            ? ['approved_parent_slug']
            : [];
    }
}
