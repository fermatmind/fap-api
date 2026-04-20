<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Import\RunStatus;
use App\Models\CareerImportRun;
use App\Models\Occupation;
use App\Models\OccupationAlias;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use App\Models\SourceTrace;
use App\Services\Career\Directory\OccupationDirectoryDisplayNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SplFileObject;
use Throwable;

final class CareerImportOccupationDirectoryDrafts extends Command
{
    protected $signature = 'career:import-occupation-directory-drafts
        {--input= : Path to career_create_import.jsonl}
        {--alias-review= : Optional career_alias_review.csv path}
        {--child-role-review= : Optional career_child_role_review.csv path}
        {--manifest= : Optional import_manifest.json path}
        {--apply : Write draft dataset-only authority rows}
        {--allow-pending-review : Allow staging rows that still require translation/alias/child-role review}
        {--status=draft : Only draft is supported for staged occupation-directory imports}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Stage Career occupation-directory candidates as draft dataset-only authority rows.';

    public function handle(): int
    {
        $run = null;

        try {
            $inputPath = $this->requiredPath('input');
            $records = $this->readJsonl($inputPath);
            $aliasRows = $this->optionalCsvRows('alias-review');
            $childRows = $this->optionalCsvRows('child-role-review');
            $manifest = $this->optionalJson('manifest');
            $apply = (bool) $this->option('apply');
            $allowPendingReview = (bool) $this->option('allow-pending-review');
            $status = trim((string) $this->option('status'));

            if ($status !== 'draft') {
                throw new \RuntimeException('Only --status=draft is supported.');
            }

            $summary = $this->summarize($records, $aliasRows, $childRows, $manifest);
            if ($summary['gate_failure_count'] > 0 || $summary['authority_duplicate_count'] > 0 || $summary['proposed_slug_duplicate_count'] > 0) {
                return $this->finish($summary, false, 'draft import validation failed');
            }

            $summary = $this->addDatabasePlan($summary, $records);
            $blockedApplyReasons = $this->blockedApplyReasons($summary, $allowPendingReview);
            $summary['apply'] = $apply;
            $summary['allow_pending_review'] = $allowPendingReview;
            $summary['status_mode'] = $status;
            $summary['blocked_apply_reasons'] = $blockedApplyReasons;
            $summary['writes_database'] = $apply;

            if (! $apply) {
                return $this->finish($summary, true, 'draft import dry-run complete');
            }

            if ($blockedApplyReasons !== []) {
                return $this->finish($summary, false, 'draft import apply blocked');
            }

            $run = CareerImportRun::query()->create([
                'dataset_name' => (string) ($manifest['source_package'] ?? 'china_us_occupation_directories_2026'),
                'dataset_version' => (string) ($manifest['package_version'] ?? 'occupation_directory_draft.v1'),
                'dataset_checksum' => hash_file('sha256', $inputPath) ?: hash('sha256', $inputPath),
                'source_path' => $inputPath,
                'scope_mode' => 'occupation_directory_draft',
                'dry_run' => false,
                'status' => RunStatus::RUNNING,
                'started_at' => now(),
                'meta' => [
                    'package_kind' => $manifest['package_kind'] ?? null,
                    'status_mode' => $status,
                    'allow_pending_review' => $allowPendingReview,
                    'alias_review_total' => count($aliasRows),
                    'child_role_review_total' => count($childRows),
                ],
            ]);

            $created = $this->stageDrafts($records, $run);
            $summary = array_merge($summary, $created);
            $summary['import_run_id'] = $run->id;

            $run->forceFill([
                'status' => RunStatus::COMPLETED,
                'finished_at' => now(),
                'rows_seen' => $summary['records_seen'],
                'rows_accepted' => $summary['occupations_created'],
                'rows_skipped' => $summary['existing_occupation_total'],
                'rows_failed' => 0,
                'output_counts' => $created,
                'error_summary' => [],
                'meta' => array_merge((array) ($run->meta ?? []), [
                    'market_counts' => $summary['market_counts'],
                    'translation_status_counts' => $summary['translation_status_counts'],
                ]),
            ])->save();

            return $this->finish($summary, true, 'draft import complete');
        } catch (Throwable $throwable) {
            if ($run instanceof CareerImportRun) {
                $run->forceFill([
                    'status' => RunStatus::FAILED,
                    'finished_at' => now(),
                    'error_summary' => [[
                        'message' => $this->jsonSafeString($throwable->getMessage()),
                        'type' => 'fatal',
                        'exception' => $throwable::class,
                    ]],
                ])->save();
            }

            $this->error($this->jsonSafeString($throwable->getMessage()));

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function finish(array $summary, bool $success, string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ([
                'records_seen',
                'create_total',
                'alias_review_total',
                'child_role_review_total',
                'pending_translation_review_total',
                'family_total',
                'existing_occupation_total',
                'will_create_occupations',
                'will_create_aliases',
                'will_create_crosswalks',
                'will_create_source_traces',
                'gate_failure_count',
                'authority_duplicate_count',
                'proposed_slug_duplicate_count',
            ] as $key) {
                if (array_key_exists($key, $summary)) {
                    $this->line($key.'='.$summary[$key]);
                }
            }

            $this->line('market_counts='.json_encode($summary['market_counts'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->line('translation_status_counts='.json_encode($summary['translation_status_counts'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->line('blocked_apply_reasons='.json_encode($summary['blocked_apply_reasons'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (isset($summary['import_run_id'])) {
                $this->line('import_run_id='.(string) $summary['import_run_id']);
            }
        }

        $success ? $this->info($message) : $this->error($message);

        return $success ? self::SUCCESS : self::FAILURE;
    }

    private function requiredPath(string $option): string
    {
        $path = trim((string) $this->option($option));
        if ($path === '') {
            throw new \RuntimeException('--'.$option.' is required.');
        }
        if (! is_file($path)) {
            throw new \RuntimeException('--'.$option.' file does not exist: '.$path);
        }

        return $path;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readJsonl(string $path): array
    {
        $file = new SplFileObject($path, 'r');
        $records = [];
        $lineNumber = 0;

        while (! $file->eof()) {
            $lineNumber++;
            $line = trim((string) $file->fgets());
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                throw new \RuntimeException('Invalid JSONL record at line '.$lineNumber.'.');
            }

            $records[] = $decoded;
        }

        return $records;
    }

    /**
     * @return list<array<string, string>>
     */
    private function optionalCsvRows(string $option): array
    {
        $path = trim((string) ($this->option($option) ?? ''));
        if ($path === '') {
            return [];
        }
        if (! is_file($path)) {
            throw new \RuntimeException('--'.$option.' file does not exist: '.$path);
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
     * @return array<string, mixed>
     */
    private function optionalJson(string $option): array
    {
        $path = trim((string) ($this->option($option) ?? ''));
        if ($path === '') {
            return [];
        }
        if (! is_file($path)) {
            throw new \RuntimeException('--'.$option.' file does not exist: '.$path);
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON manifest: '.$path);
        }

        return $decoded;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  list<array<string, string>>  $aliasRows
     * @param  list<array<string, string>>  $childRows
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function summarize(array $records, array $aliasRows, array $childRows, array $manifest): array
    {
        $authorityKeys = [];
        $proposedSlugs = [];
        $marketCounts = [];
        $translationStatusCounts = [];
        $gateFailures = [];
        $pendingTranslationReview = 0;

        foreach ($records as $index => $record) {
            $recordNumber = $index + 1;
            $authority = is_array($record['authority'] ?? null) ? $record['authority'] : [];
            $identity = is_array($record['identity'] ?? null) ? $record['identity'] : [];
            $localization = is_array($record['localization'] ?? null) ? $record['localization'] : [];
            $governance = is_array($record['governance'] ?? null) ? $record['governance'] : [];

            $market = trim((string) ($record['market'] ?? ''));
            $authoritySource = trim((string) ($authority['source'] ?? ''));
            $authorityCode = trim((string) ($authority['code'] ?? ''));
            $proposedSlug = trim((string) ($identity['proposed_slug'] ?? ''));
            $translationStatus = trim((string) ($localization['translation_status'] ?? 'unknown'));

            $marketCounts[$market] = ($marketCounts[$market] ?? 0) + 1;
            $translationStatusCounts[$translationStatus] = ($translationStatusCounts[$translationStatus] ?? 0) + 1;
            $authorityKeys[] = $market.'|'.$authoritySource.'|'.$authorityCode;
            $proposedSlugs[] = $proposedSlug;

            if ($translationStatus !== 'from_existing_match') {
                $pendingTranslationReview++;
            }
            if (($record['import_action'] ?? null) !== 'create') {
                $gateFailures[] = ['record' => $recordNumber, 'reason' => 'import_action_not_create'];
            }
            if (($record['dry_run_only'] ?? null) !== true) {
                $gateFailures[] = ['record' => $recordNumber, 'reason' => 'dry_run_only_not_true'];
            }
            if (($governance['publish_state'] ?? null) !== 'draft') {
                $gateFailures[] = ['record' => $recordNumber, 'reason' => 'publish_state_not_draft'];
            }
            if (($governance['requires_backend_truth_compute'] ?? null) !== true) {
                $gateFailures[] = ['record' => $recordNumber, 'reason' => 'missing_backend_truth_compute_gate'];
            }
            if ($market === '' || $authoritySource === '' || $authorityCode === '' || $proposedSlug === '') {
                $gateFailures[] = ['record' => $recordNumber, 'reason' => 'missing_identity_or_authority_field'];
            }
            if ($translationStatus !== 'from_existing_match' && ($localization['translation_review_required'] ?? null) !== true) {
                $gateFailures[] = ['record' => $recordNumber, 'reason' => 'translation_review_gate_missing'];
            }
        }

        $manifestCounts = is_array($manifest['counts'] ?? null) ? $manifest['counts'] : [];
        if (isset($manifestCounts['create_total_top_level']) && (int) $manifestCounts['create_total_top_level'] !== count($records)) {
            $gateFailures[] = ['record' => 0, 'reason' => 'manifest_create_total_mismatch'];
        }

        $authorityDuplicates = $this->duplicates($authorityKeys);
        $slugDuplicates = $this->duplicates($proposedSlugs);

        return [
            'package_kind' => 'career_occupation_directory_draft_import',
            'records_seen' => count($records),
            'create_total' => count($records),
            'alias_review_total' => count($aliasRows),
            'child_role_review_total' => count($childRows),
            'pending_translation_review_total' => $pendingTranslationReview,
            'market_counts' => $marketCounts,
            'translation_status_counts' => $translationStatusCounts,
            'authority_duplicate_count' => count($authorityDuplicates),
            'authority_duplicates' => array_slice($authorityDuplicates, 0, 25),
            'proposed_slug_duplicate_count' => count($slugDuplicates),
            'proposed_slug_duplicates' => array_slice($slugDuplicates, 0, 25),
            'gate_failure_count' => count($gateFailures),
            'gate_failures' => array_slice($gateFailures, 0, 50),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  list<array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    private function addDatabasePlan(array $summary, array $records): array
    {
        $slugs = array_values(array_filter(array_map(
            static fn (array $record): string => trim((string) data_get($record, 'identity.proposed_slug')),
            $records
        )));
        $existing = Occupation::query()
            ->whereIn('canonical_slug', $slugs)
            ->pluck('canonical_slug')
            ->all();
        $existingLookup = array_flip($existing);
        $pendingRecords = array_values(array_filter(
            $records,
            static fn (array $record): bool => ! isset($existingLookup[trim((string) data_get($record, 'identity.proposed_slug'))])
        ));

        $families = [];
        $aliasTotal = 0;
        foreach ($pendingRecords as $record) {
            $families[$this->familySlug($record)] = true;
            $aliasTotal += count($this->aliasPayloads($record, ''));
        }

        return array_merge($summary, [
            'family_total' => count($families),
            'existing_occupation_total' => count($existing),
            'will_create_occupations' => count($pendingRecords),
            'will_create_aliases' => $aliasTotal,
            'will_create_crosswalks' => count($pendingRecords),
            'will_create_source_traces' => count($pendingRecords),
        ]);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return list<string>
     */
    private function blockedApplyReasons(array $summary, bool $allowPendingReview): array
    {
        $reasons = [];
        if (! $allowPendingReview && (int) $summary['pending_translation_review_total'] > 0) {
            $reasons[] = 'pending_translation_review';
        }
        if (! $allowPendingReview && (int) $summary['alias_review_total'] > 0) {
            $reasons[] = 'pending_alias_review';
        }
        if (! $allowPendingReview && (int) $summary['child_role_review_total'] > 0) {
            $reasons[] = 'pending_child_role_review';
        }

        return $reasons;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, int>
     */
    private function stageDrafts(array $records, CareerImportRun $run): array
    {
        $counts = [
            'families_created' => 0,
            'occupations_created' => 0,
            'occupations_skipped_existing' => 0,
            'aliases_created' => 0,
            'crosswalks_created' => 0,
            'source_traces_created' => 0,
        ];

        DB::transaction(function () use ($records, $run, &$counts): void {
            foreach ($records as $record) {
                $slug = trim((string) data_get($record, 'identity.proposed_slug'));
                $existing = Occupation::query()->where('canonical_slug', $slug)->first();
                if ($existing instanceof Occupation) {
                    $counts['occupations_skipped_existing']++;

                    continue;
                }

                $familyPayload = $this->familyPayload($record);
                $family = OccupationFamily::query()
                    ->where('canonical_slug', $familyPayload['canonical_slug'])
                    ->first();
                if (! $family instanceof OccupationFamily) {
                    $family = OccupationFamily::query()->create($familyPayload);
                    $counts['families_created']++;
                }

                $occupation = Occupation::query()->create([
                    'family_id' => $family->id,
                    'parent_id' => null,
                    'canonical_slug' => $slug,
                    'entity_level' => 'dataset_candidate',
                    'truth_market' => (string) $record['market'],
                    'display_market' => (string) $record['market'],
                    'crosswalk_mode' => 'directory_draft',
                    'canonical_title_en' => $this->titleEn($record),
                    'canonical_title_zh' => $this->titleZh($record),
                    'search_h1_zh' => $this->titleZh($record),
                    'structural_stability' => null,
                    'task_prototype_signature' => null,
                    'market_semantics_gap' => null,
                    'regulatory_divergence' => null,
                    'toolchain_divergence' => null,
                    'skill_gap_threshold' => null,
                    'trust_inheritance_scope' => [
                        'status' => 'draft_dataset_only',
                        'requires_backend_truth_compute' => true,
                        'requires_editorial_review' => true,
                        'source_package' => data_get($record, 'governance.source_package'),
                    ],
                ]);
                $counts['occupations_created']++;

                foreach ($this->aliasPayloads($record, $occupation->id) as $payload) {
                    $created = OccupationAlias::query()->firstOrCreate(
                        [
                            'import_run_id' => $run->id,
                            'row_fingerprint' => $payload['row_fingerprint'],
                        ],
                        $payload,
                    );
                    $counts['aliases_created'] += $created->wasRecentlyCreated ? 1 : 0;
                }

                $crosswalkPayload = [
                    'occupation_id' => $occupation->id,
                    'source_system' => (string) data_get($record, 'authority.source'),
                    'source_code' => (string) data_get($record, 'authority.code'),
                    'source_title' => $this->sourceTitle($record),
                    'mapping_type' => 'directory_candidate',
                    'confidence_score' => 0.5,
                    'notes' => 'occupation_directory_draft_import',
                    'import_run_id' => $run->id,
                    'row_fingerprint' => $this->fingerprint([
                        'crosswalk',
                        $slug,
                        data_get($record, 'authority.source'),
                        data_get($record, 'authority.code'),
                    ]),
                ];
                $crosswalk = OccupationCrosswalk::query()->firstOrCreate(
                    [
                        'import_run_id' => $run->id,
                        'row_fingerprint' => $crosswalkPayload['row_fingerprint'],
                    ],
                    $crosswalkPayload,
                );
                $counts['crosswalks_created'] += $crosswalk->wasRecentlyCreated ? 1 : 0;

                $sourceTracePayload = [
                    'source_id' => (string) data_get($record, 'authority.source').':'.(string) data_get($record, 'authority.code'),
                    'source_type' => 'career_occupation_directory',
                    'title' => $this->sourceTitle($record),
                    'url' => data_get($record, 'authority.source_url'),
                    'fields_used' => [
                        'authority',
                        'identity',
                        'taxonomy',
                        'content_seed.definition',
                    ],
                    'retrieved_at' => $run->started_at,
                    'evidence_strength' => 0.65,
                    'import_run_id' => $run->id,
                    'row_fingerprint' => $this->fingerprint([
                        'source_trace',
                        data_get($record, 'authority.source'),
                        data_get($record, 'authority.code'),
                        $slug,
                    ]),
                ];
                $sourceTrace = SourceTrace::query()->firstOrCreate(
                    [
                        'import_run_id' => $run->id,
                        'row_fingerprint' => $sourceTracePayload['row_fingerprint'],
                    ],
                    $sourceTracePayload,
                );
                $counts['source_traces_created'] += $sourceTrace->wasRecentlyCreated ? 1 : 0;
            }
        });

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{canonical_slug:string,title_en:string,title_zh:string}
     */
    private function familyPayload(array $record): array
    {
        return app(OccupationDirectoryDisplayNormalizer::class)->familyPayload($record);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function familySlug(array $record): string
    {
        return app(OccupationDirectoryDisplayNormalizer::class)->familySlug($record);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<array<string, mixed>>
     */
    private function aliasPayloads(array $record, string $occupationId): array
    {
        $aliases = [];
        foreach ([
            ['text' => $this->titleEn($record), 'lang' => 'en'],
            ['text' => $this->titleZh($record), 'lang' => 'zh-CN'],
            ['text' => trim((string) data_get($record, 'identity.source_title_en')), 'lang' => 'en'],
            ['text' => trim((string) data_get($record, 'identity.source_title_zh')), 'lang' => 'zh-CN'],
        ] as $candidate) {
            $text = trim((string) $candidate['text']);
            if ($text === '') {
                continue;
            }
            $normalized = $this->normalizeAlias($text);
            if ($normalized === '') {
                continue;
            }
            $key = (string) $candidate['lang'].'|'.$normalized;
            if (isset($aliases[$key])) {
                continue;
            }

            $aliases[$key] = [
                'occupation_id' => $occupationId !== '' ? $occupationId : null,
                'family_id' => null,
                'alias' => $text,
                'normalized' => $normalized,
                'lang' => (string) $candidate['lang'],
                'register' => 'directory_title',
                'intent_scope' => 'search',
                'target_kind' => 'occupation',
                'precision_score' => 0.6,
                'confidence_score' => 0.6,
                'seniority_hint' => null,
                'function_hint' => null,
                'row_fingerprint' => $this->fingerprint([
                    'alias',
                    data_get($record, 'authority.source'),
                    data_get($record, 'authority.code'),
                    $candidate['lang'],
                    $normalized,
                ]),
            ];
        }

        return array_values($aliases);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function titleEn(array $record): string
    {
        return app(OccupationDirectoryDisplayNormalizer::class)->titleEn($record);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function titleZh(array $record): string
    {
        return app(OccupationDirectoryDisplayNormalizer::class)->titleZh($record);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function sourceTitle(array $record): string
    {
        $sourceTitle = trim((string) data_get($record, 'identity.source_title_en'));
        if ($sourceTitle !== '') {
            return $sourceTitle;
        }
        $sourceTitle = trim((string) data_get($record, 'identity.source_title_zh'));

        return $sourceTitle !== '' ? $sourceTitle : $this->titleEn($record);
    }

    private function normalizeAlias(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->squish()
            ->toString();
    }

    private function jsonSafeString(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        if (is_string($converted) && $converted !== '') {
            return $converted;
        }

        return 'Non-UTF-8 exception message: '.bin2hex($value);
    }

    /**
     * @param  list<mixed>  $parts
     */
    private function fingerprint(array $parts): string
    {
        return hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: serialize($parts));
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function duplicates(array $values): array
    {
        $counts = array_count_values(array_filter($values, static fn (string $value): bool => trim($value) !== ''));

        return array_values(array_keys(array_filter($counts, static fn (int $count): bool => $count > 1)));
    }
}
