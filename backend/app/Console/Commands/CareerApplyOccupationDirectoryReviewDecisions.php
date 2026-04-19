<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CareerImportRun;
use App\Models\Occupation;
use App\Models\OccupationAlias;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class CareerApplyOccupationDirectoryReviewDecisions extends Command
{
    private const TRANSLATION_DECISIONS = ['approve', 'edit', 'reject', 'merge_alias'];

    private const ALIAS_DECISIONS = ['alias_existing', 'create_separate', 'reject', 'needs_research'];

    private const CHILD_ROLE_DECISIONS = ['alias_parent', 'child_role', 'create_top_level', 'reject', 'needs_research'];

    protected $signature = 'career:apply-occupation-directory-review-decisions
        {--queue-dir= : Directory containing reviewed queue CSV files}
        {--import-run= : Staged CareerImportRun id}
        {--apply : Write approved review decisions}
        {--allow-pending : Allow blank review_decision rows and apply only completed decisions}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Apply reviewed occupation-directory queue decisions to staged backend draft authority rows.';

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

            $run = $this->resolveImportRun();
            $apply = (bool) $this->option('apply');
            $allowPending = (bool) $this->option('allow-pending');
            $summary = [
                'import_run_id' => $run->id,
                'queue_dir' => $queueDir,
                'apply' => $apply,
                'allow_pending' => $allowPending,
                'writes_database' => $apply,
                'translation' => $this->planQueue(
                    $queueDir.'/translation_review_queue.csv',
                    self::TRANSLATION_DECISIONS,
                    fn (array $row): array => $this->translationRequiredFields($row),
                ),
                'alias' => $this->planQueue(
                    $queueDir.'/alias_review_decisions.csv',
                    self::ALIAS_DECISIONS,
                    fn (array $row): array => $this->aliasRequiredFields($row),
                ),
                'child_role' => $this->planQueue(
                    $queueDir.'/child_role_review_decisions.csv',
                    self::CHILD_ROLE_DECISIONS,
                    fn (array $row): array => $this->childRequiredFields($row),
                ),
            ];
            $summary['pending_total'] = $summary['translation']['pending'] + $summary['alias']['pending'] + $summary['child_role']['pending'];
            $summary['invalid_total'] = $summary['translation']['invalid'] + $summary['alias']['invalid'] + $summary['child_role']['invalid'];
            $summary['missing_required_total'] = $summary['translation']['missing_required'] + $summary['alias']['missing_required'] + $summary['child_role']['missing_required'];

            if ($summary['invalid_total'] > 0 || $summary['missing_required_total'] > 0 || ($summary['pending_total'] > 0 && ! $allowPending)) {
                return $this->finish($summary, false, 'review decision apply blocked');
            }

            if (! $apply) {
                return $this->finish($summary, true, 'review decision dry-run complete');
            }

            $created = DB::transaction(function () use ($queueDir, $run): array {
                return [
                    ...$this->applyTranslationRows($queueDir.'/translation_review_queue.csv', $run),
                    ...$this->applyAliasRows($queueDir.'/alias_review_decisions.csv', $run),
                    ...$this->applyChildRows($queueDir.'/child_role_review_decisions.csv', $run),
                ];
            });
            $summary = array_merge($summary, $created);

            return $this->finish($summary, true, 'review decisions applied');
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }

    private function resolveImportRun(): CareerImportRun
    {
        $id = trim((string) $this->option('import-run'));
        if ($id === '') {
            throw new \RuntimeException('--import-run is required.');
        }

        $run = CareerImportRun::query()->find($id);
        if (! $run instanceof CareerImportRun) {
            throw new \RuntimeException('--import-run not found: '.$id);
        }
        if ((string) $run->scope_mode !== 'occupation_directory_draft') {
            throw new \RuntimeException('--import-run is not an occupation directory draft run.');
        }

        return $run;
    }

    /**
     * @param  list<string>  $allowed
     * @param  callable(array<string, string>): list<string>  $required
     * @return array<string, mixed>
     */
    private function planQueue(string $path, array $allowed, callable $required): array
    {
        $rows = $this->readCsv($path);
        $decisionCounts = array_fill_keys($allowed, 0);
        $pending = 0;
        $invalid = [];
        $missingRequired = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $decision = trim((string) ($row['review_decision'] ?? ''));
            if ($decision === '') {
                $pending++;

                continue;
            }
            if (! in_array($decision, $allowed, true)) {
                $invalid[] = ['line' => $line, 'review_decision' => $decision];

                continue;
            }
            $decisionCounts[$decision]++;
            $missing = $required($row);
            if ($missing !== []) {
                $missingRequired[] = ['line' => $line, 'review_decision' => $decision, 'missing' => $missing];
            }
        }

        return [
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
     * @return array<string, int>
     */
    private function applyTranslationRows(string $path, CareerImportRun $run): array
    {
        $counts = [
            'translation_titles_updated' => 0,
            'translation_approved_without_change' => 0,
            'translation_rejected_or_deferred' => 0,
        ];

        foreach ($this->readCsv($path) as $row) {
            $decision = trim((string) ($row['review_decision'] ?? ''));
            if ($decision === '') {
                continue;
            }

            if ($decision === 'approve') {
                $counts['translation_approved_without_change']++;

                continue;
            }
            if ($decision !== 'edit') {
                $counts['translation_rejected_or_deferred']++;

                continue;
            }

            $occupation = $this->stagedOccupation((string) ($row['proposed_slug'] ?? ''), $run);
            $occupation->forceFill([
                'canonical_title_zh' => trim((string) $row['approved_title_zh']),
                'canonical_title_en' => trim((string) $row['approved_title_en']),
                'search_h1_zh' => trim((string) $row['approved_title_zh']),
            ])->save();
            $counts['translation_titles_updated']++;
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function applyAliasRows(string $path, CareerImportRun $run): array
    {
        $counts = [
            'alias_existing_created' => 0,
            'alias_create_separate_deferred' => 0,
            'alias_rejected_or_research' => 0,
        ];

        foreach ($this->readCsv($path) as $row) {
            $decision = trim((string) ($row['review_decision'] ?? ''));
            if ($decision === '') {
                continue;
            }
            if ($decision === 'create_separate') {
                $counts['alias_create_separate_deferred']++;

                continue;
            }
            if ($decision !== 'alias_existing') {
                $counts['alias_rejected_or_research']++;

                continue;
            }

            $target = $this->occupationBySlug((string) $row['approved_target_slug']);
            $created = $this->createAliasIfNeeded(
                $target,
                $run,
                (string) ($row['approved_alias_zh'] ?: $row['suggested_title_zh'] ?: $row['source_title_zh'] ?? ''),
                'zh-CN',
                ['alias_existing', $target->canonical_slug, $row['authority_source'] ?? '', $row['authority_code'] ?? '', 'zh-CN'],
            );
            $counts['alias_existing_created'] += $created ? 1 : 0;

            $created = $this->createAliasIfNeeded(
                $target,
                $run,
                (string) ($row['approved_alias_en'] ?: $row['suggested_title_en'] ?: $row['source_title_en'] ?? ''),
                'en',
                ['alias_existing', $target->canonical_slug, $row['authority_source'] ?? '', $row['authority_code'] ?? '', 'en'],
            );
            $counts['alias_existing_created'] += $created ? 1 : 0;
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function applyChildRows(string $path, CareerImportRun $run): array
    {
        $counts = [
            'child_role_aliases_created' => 0,
            'child_role_top_level_deferred' => 0,
            'child_role_rejected_or_research' => 0,
        ];

        foreach ($this->readCsv($path) as $row) {
            $decision = trim((string) ($row['review_decision'] ?? ''));
            if ($decision === '') {
                continue;
            }
            if ($decision === 'create_top_level') {
                $counts['child_role_top_level_deferred']++;

                continue;
            }
            if (! in_array($decision, ['alias_parent', 'child_role'], true)) {
                $counts['child_role_rejected_or_research']++;

                continue;
            }

            $parent = $this->occupationBySlug((string) $row['approved_parent_slug']);
            $created = $this->createAliasIfNeeded(
                $parent,
                $run,
                (string) ($row['approved_child_title_zh'] ?: $row['suggested_title_zh'] ?: $row['source_title_zh'] ?? ''),
                'zh-CN',
                ['child_role', $parent->canonical_slug, $row['authority_source'] ?? '', $row['authority_code'] ?? '', 'zh-CN'],
            );
            $counts['child_role_aliases_created'] += $created ? 1 : 0;

            $created = $this->createAliasIfNeeded(
                $parent,
                $run,
                (string) ($row['approved_child_title_en'] ?: $row['suggested_title_en'] ?: $row['source_title_en'] ?? ''),
                'en',
                ['child_role', $parent->canonical_slug, $row['authority_source'] ?? '', $row['authority_code'] ?? '', 'en'],
            );
            $counts['child_role_aliases_created'] += $created ? 1 : 0;
        }

        return $counts;
    }

    private function stagedOccupation(string $slug, CareerImportRun $run): Occupation
    {
        $occupation = Occupation::query()
            ->where('canonical_slug', trim($slug))
            ->whereHas('crosswalks', fn ($query) => $query->where('import_run_id', $run->id))
            ->first();
        if (! $occupation instanceof Occupation) {
            throw new \RuntimeException('staged occupation not found: '.$slug);
        }

        return $occupation;
    }

    private function occupationBySlug(string $slug): Occupation
    {
        $occupation = Occupation::query()
            ->where('canonical_slug', trim($slug))
            ->first();
        if (! $occupation instanceof Occupation) {
            throw new \RuntimeException('occupation not found: '.$slug);
        }

        return $occupation;
    }

    /**
     * @param  list<mixed>  $fingerprintParts
     */
    private function createAliasIfNeeded(
        Occupation $occupation,
        CareerImportRun $run,
        string $alias,
        string $lang,
        array $fingerprintParts,
    ): bool {
        $alias = trim($alias);
        if ($alias === '') {
            return false;
        }

        $created = OccupationAlias::query()->firstOrCreate(
            [
                'import_run_id' => $run->id,
                'row_fingerprint' => hash('sha256', json_encode($fingerprintParts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: serialize($fingerprintParts)),
            ],
            [
                'occupation_id' => $occupation->id,
                'family_id' => null,
                'alias' => $alias,
                'normalized' => Str::of($alias)->lower()->squish()->toString(),
                'lang' => $lang,
                'register' => 'review_decision',
                'intent_scope' => 'search',
                'target_kind' => 'occupation',
                'precision_score' => 0.8,
                'confidence_score' => 0.8,
                'seniority_hint' => null,
                'function_hint' => null,
            ],
        );

        return $created->wasRecentlyCreated;
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function translationRequiredFields(array $row): array
    {
        if (($row['review_decision'] ?? '') !== 'edit') {
            return [];
        }

        return $this->missing($row, ['approved_title_zh', 'approved_title_en']);
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function aliasRequiredFields(array $row): array
    {
        if (($row['review_decision'] ?? '') !== 'alias_existing') {
            return [];
        }

        return $this->missing($row, ['approved_target_slug']);
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function childRequiredFields(array $row): array
    {
        if (! in_array(($row['review_decision'] ?? ''), ['alias_parent', 'child_role'], true)) {
            return [];
        }

        return $this->missing($row, ['approved_parent_slug']);
    }

    /**
     * @param  array<string, string>  $row
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function missing(array $row, array $keys): array
    {
        return array_values(array_filter(
            $keys,
            static fn (string $key): bool => trim((string) ($row[$key] ?? '')) === '',
        ));
    }

    /**
     * @return list<array<string, string>>
     */
    private function readCsv(string $path): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException('Review queue file does not exist: '.$path);
        }

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
     * @param  array<string, mixed>  $summary
     */
    private function finish(array $summary, bool $success, string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach (['pending_total', 'invalid_total', 'missing_required_total'] as $key) {
                $this->line($key.'='.(int) ($summary[$key] ?? 0));
            }
            foreach ([
                'translation_titles_updated',
                'alias_existing_created',
                'child_role_aliases_created',
            ] as $key) {
                if (isset($summary[$key])) {
                    $this->line($key.'='.(int) $summary[$key]);
                }
            }
        }

        $success ? $this->info($message) : $this->error($message);

        return $success ? self::SUCCESS : self::FAILURE;
    }
}
