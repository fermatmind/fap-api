<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\IndexStateValue;
use App\Models\IndexState;
use App\Models\Occupation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class CareerPrepareCanonicalRuntimeCandidates extends Command
{
    private const SOURCE = 'career_80_delta_runtime_candidate_preparation';

    private const TARGET_INDEX_STATE = IndexStateValue::PROMOTION_CANDIDATE;

    private const TARGET_RUNTIME_STATE = 'published_candidate';

    private const DEFAULT_MAX_SLUGS = 100;

    protected $signature = 'career:prepare-canonical-runtime-candidates
        {--plan= : Reviewed runtime candidate preparation plan JSON path}
        {--slug-artifact= : Reviewed explicit slug artifact JSON path}
        {--dry-run : Plan without writing}
        {--apply : Execute guarded published_candidate preparation writes}
        {--batch-id= : Reviewed preparation batch id}
        {--reason= : Explicit preparation reason}
        {--max-slugs=100 : Maximum allowed explicit slugs}
        {--expect-slug-count= : Required for --apply; expected slug count}
        {--confirm-artifact-sha256= : Required for --apply; must match source artifact sha256}
        {--json : Emit JSON output}
        {--output= : Optional output path for JSON payload}';

    protected $description = 'Guarded explicit-slug Career runtime published_candidate preparation dry-run/apply gate.';

    public function handle(): int
    {
        try {
            $mode = $this->mode();
            $sourcePath = $this->sourcePath();
            $batchId = $this->requiredOption('batch-id');
            $reason = $this->requiredOption('reason');
            $maxSlugs = $this->positiveIntOption('max-slugs', self::DEFAULT_MAX_SLUGS);
            $expectedSlugCount = $this->nullablePositiveIntOption('expect-slug-count');

            [$artifact, $artifactSha] = $this->readSourceArtifact($sourcePath);
            $slugs = $artifact['slugs'];
            $slugCount = count($slugs);

            $blockers = [
                ...$this->confirmationBlockers($mode, $artifactSha, $slugCount, $expectedSlugCount),
                ...$this->artifactSafetyBlockers($slugCount, $maxSlugs),
            ];

            $plan = $this->plan($slugs, $artifact['locales'], $batchId, $reason, $sourcePath, $artifactSha);
            $blockers = [
                ...$blockers,
                ...$plan['blockers'],
            ];

            if ($mode === 'dry-run') {
                return $this->finish($this->dryRunPayload(
                    $plan,
                    $artifact,
                    $sourcePath,
                    $artifactSha,
                    $batchId,
                    $reason,
                    $maxSlugs,
                    $expectedSlugCount,
                    $blockers
                ), $blockers === [] ? self::SUCCESS : self::FAILURE);
            }

            if ($blockers !== []) {
                return $this->finish($this->blockedApplyPayload(
                    $plan,
                    $artifact,
                    $sourcePath,
                    $artifactSha,
                    $batchId,
                    $reason,
                    $maxSlugs,
                    $expectedSlugCount,
                    $blockers
                ), self::FAILURE);
            }

            $payload = $this->apply($plan, $artifact, $sourcePath, $artifactSha, $batchId, $reason, $maxSlugs, $expectedSlugCount);

            return $this->finish($payload, ($payload['status'] ?? null) === 'applied' ? self::SUCCESS : self::FAILURE);
        } catch (Throwable $exception) {
            $reason = $this->reasonKey($exception->getMessage());

            return $this->finish([
                'status' => 'blocked',
                'dry_run' => false,
                'writes_database' => false,
                'write_verified' => false,
                'blockers' => [[
                    'reason' => $reason,
                    'message' => $exception->getMessage(),
                    'evidence' => [],
                ]],
                'by_reason' => [$reason => 1],
            ], self::FAILURE);
        }
    }

    private function mode(): string
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');

        if ($dryRun === $apply) {
            throw new RuntimeException('exactly_one_of_dry_run_or_apply_required');
        }

        return $apply ? 'apply' : 'dry-run';
    }

    private function sourcePath(): string
    {
        $plan = trim((string) ($this->option('plan') ?? ''));
        $slugArtifact = trim((string) ($this->option('slug-artifact') ?? ''));

        if (($plan === '') === ($slugArtifact === '')) {
            throw new RuntimeException('exactly_one_of_plan_or_slug_artifact_required');
        }

        return $plan !== '' ? $plan : $slugArtifact;
    }

    private function requiredOption(string $name): string
    {
        $value = trim((string) ($this->option($name) ?? ''));
        if ($value === '') {
            throw new RuntimeException(str_replace('-', '_', $name).'_missing');
        }

        return $value;
    }

    private function positiveIntOption(string $name, int $default): int
    {
        $raw = $this->option($name);
        if ($raw === null || trim((string) $raw) === '') {
            return $default;
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if (! is_int($value) || $value < 1) {
            throw new RuntimeException(str_replace('-', '_', $name).'_invalid');
        }

        return $value;
    }

    private function nullablePositiveIntOption(string $name): ?int
    {
        $raw = $this->option($name);
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if (! is_int($value) || $value < 1) {
            throw new RuntimeException(str_replace('-', '_', $name).'_invalid');
        }

        return $value;
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function readSourceArtifact(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('runtime_candidate_prep_artifact_missing');
        }

        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException('runtime_candidate_prep_artifact_unreadable');
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('runtime_candidate_prep_artifact_json_invalid');
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('runtime_candidate_prep_artifact_shape_invalid');
        }

        [$slugs, $locales] = $this->slugsAndLocales($decoded);
        $declaredCount = $this->declaredCount($decoded);
        if ($declaredCount !== null && $declaredCount !== count($slugs)) {
            throw new RuntimeException('slug_count_declared_mismatch');
        }

        $status = strtolower(trim((string) ($decoded['status'] ?? '')));
        if ($status !== '' && ! in_array($status, ['planned', 'pass'], true)) {
            throw new RuntimeException('runtime_candidate_prep_plan_not_planned');
        }

        return [[
            'schema_version' => is_array($decoded) && ! array_is_list($decoded) ? ($decoded['schema_version'] ?? null) : 'slug_list.v1',
            'source_kind' => $this->option('plan') !== null && trim((string) $this->option('plan')) !== '' ? 'plan' : 'slug_artifact',
            'declared_count' => $declaredCount ?? count($slugs),
            'slugs' => $slugs,
            'locales' => $locales,
            'raw_summary' => is_array($decoded) && ! array_is_list($decoded) ? [
                'status' => $decoded['status'] ?? null,
                'target' => $decoded['target'] ?? null,
                'delta_slug_count' => $decoded['delta_slug_count'] ?? null,
                'expected_locale_rows' => $decoded['expected_locale_rows'] ?? null,
            ] : null,
        ], hash('sha256', $contents)];
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $decoded
     * @return array{0: list<string>, 1: list<string>}
     */
    private function slugsAndLocales(array $decoded): array
    {
        $locales = ['en', 'zh'];

        if (isset($decoded['locales']) && is_array($decoded['locales']) && array_is_list($decoded['locales'])) {
            $locales = $this->normalizedUniqueStrings($decoded['locales'], 'locale');
        }

        if (array_is_list($decoded)) {
            return [$this->normalizedUniqueStrings($decoded, 'slug'), $locales];
        }

        $slugList = $decoded['slugs']
            ?? $decoded['recommended_rollout_delta_slugs']
            ?? $decoded['delta_promotion_slugs']
            ?? null;

        if (is_array($slugList) && array_is_list($slugList)) {
            return [$this->normalizedUniqueStrings($slugList, 'slug'), $locales];
        }

        $slugRows = $decoded['slug_rows'] ?? null;
        if (is_array($slugRows) && array_is_list($slugRows)) {
            return [$this->normalizedRowSlugs($slugRows), $locales];
        }

        $plannedRows = $decoded['planned_candidate_rows'] ?? null;
        if (is_array($plannedRows) && array_is_list($plannedRows)) {
            return [$this->normalizedRowSlugs($plannedRows), $locales];
        }

        throw new RuntimeException('slug_list_missing');
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<string>
     */
    private function normalizedRowSlugs(array $rows): array
    {
        $slugs = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw new RuntimeException('slug_row_invalid_at_'.$index);
            }

            $slug = $row['slug'] ?? null;
            if (! is_string($slug)) {
                throw new RuntimeException('slug_row_missing_at_'.$index);
            }

            $slugs[] = $slug;
        }

        return $this->normalizedUniqueStrings($slugs, 'slug', collapseDuplicates: true);
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function normalizedUniqueStrings(array $values, string $context, bool $collapseDuplicates = false): array
    {
        $normalized = [];
        $seen = [];
        foreach ($values as $index => $value) {
            if (! is_string($value)) {
                throw new RuntimeException($context.'_invalid_at_'.$index);
            }

            $trimmed = strtolower(trim($value));
            if ($trimmed === '' || str_contains($trimmed, '*')) {
                throw new RuntimeException($context.'_invalid_at_'.$index);
            }

            if (isset($seen[$trimmed])) {
                if ($collapseDuplicates) {
                    continue;
                }

                throw new RuntimeException('duplicate_'.$context.'_'.$trimmed);
            }

            $seen[$trimmed] = true;
            $normalized[] = $trimmed;
        }

        if ($normalized === []) {
            throw new RuntimeException($context.'_list_empty');
        }

        sort($normalized);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $decoded
     */
    private function declaredCount(array $decoded): ?int
    {
        if (array_is_list($decoded)) {
            return count($decoded);
        }

        $raw = $decoded['count']
            ?? $decoded['slug_count']
            ?? $decoded['delta_slug_count']
            ?? data_get($decoded, 'target.slug_count')
            ?? data_get($decoded, 'target.needed_additional_count');

        if ($raw === null || $raw === '') {
            return null;
        }

        return (int) $raw;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function confirmationBlockers(string $mode, string $artifactSha, int $slugCount, ?int $expectedSlugCount): array
    {
        $blockers = [];

        if ($mode === 'apply') {
            $confirmedSha = trim((string) ($this->option('confirm-artifact-sha256') ?? ''));
            if ($confirmedSha === '') {
                $blockers[] = $this->blocker('confirm_artifact_sha256_missing', 'Apply requires --confirm-artifact-sha256.');
            } elseif (! hash_equals($artifactSha, $confirmedSha)) {
                $blockers[] = $this->blocker('artifact_sha256_mismatch', 'Artifact sha256 confirmation does not match.');
            }

            if ($expectedSlugCount === null) {
                $blockers[] = $this->blocker('expect_slug_count_missing', 'Apply requires --expect-slug-count.');
            }
        }

        if ($expectedSlugCount !== null && $expectedSlugCount !== $slugCount) {
            $blockers[] = $this->blocker('expect_slug_count_mismatch', 'Expected slug count does not match artifact slug count.');
        }

        return $blockers;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function artifactSafetyBlockers(int $slugCount, int $maxSlugs): array
    {
        if ($slugCount > $maxSlugs) {
            return [$this->blocker('slug_count_exceeds_max_slugs', 'Artifact slug count exceeds --max-slugs guard.')];
        }

        return [];
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @return array{slugs: list<string>, locales: list<string>, missing_occupations: list<string>, existing_latest_states: list<array<string, mixed>>, planned_writes: list<array<string, mixed>>, blockers: list<array<string, mixed>>}
     */
    private function plan(array $slugs, array $locales, string $batchId, string $reason, string $artifactPath, string $artifactSha): array
    {
        $occupations = Occupation::query()
            ->whereIn('canonical_slug', $slugs)
            ->with(['indexStates' => fn ($query) => $query
                ->orderByDesc('changed_at')
                ->orderByDesc('created_at')
                ->orderByDesc('updated_at')])
            ->get()
            ->keyBy(fn (Occupation $occupation): string => strtolower((string) $occupation->getAttribute('canonical_slug')));

        $missing = [];
        $existing = [];
        $writes = [];
        $blockers = [];

        foreach ($slugs as $slug) {
            /** @var Occupation|null $occupation */
            $occupation = $occupations->get($slug);
            if ($occupation === null) {
                $missing[] = $slug;
                $blockers[] = $this->blocker('occupation_missing', 'Occupation is required before runtime candidate preparation apply.', ['canonical_slug' => $slug]);

                continue;
            }

            /** @var IndexState|null $latest */
            $latest = $occupation->indexStates->first();
            $existing[] = $this->latestIndexStatePayload($slug, $latest);

            $writes[] = [
                'canonical_slug' => $slug,
                'occupation_id' => (string) $occupation->getAttribute('id'),
                'target_index_state' => self::TARGET_INDEX_STATE,
                'index_eligible' => true,
                'canonical_path' => '/career/jobs/'.$slug,
                'canonical_target' => null,
                'reason_codes' => $this->reasonCodes($batchId, $reason, $artifactPath, $artifactSha),
                'row_fingerprint' => $this->rowFingerprint($slug, $batchId, $artifactSha),
            ];
        }

        return [
            'slugs' => $slugs,
            'locales' => $locales,
            'missing_occupations' => $missing,
            'existing_latest_states' => $existing,
            'planned_writes' => $writes,
            'blockers' => $blockers,
        ];
    }

    /**
     * @return list<string>
     */
    private function reasonCodes(string $batchId, string $reason, string $artifactPath, string $artifactSha): array
    {
        return [
            self::SOURCE,
            'prepare_published_candidate_runtime_rows',
            'batch_id:'.$batchId,
            'reason:'.Str::slug($reason, '_'),
            'artifact_sha256:'.$artifactSha,
            'artifact_basename:'.basename($artifactPath),
            'target_runtime_state:'.self::TARGET_RUNTIME_STATE,
            'target_index_state:'.self::TARGET_INDEX_STATE,
        ];
    }

    private function rowFingerprint(string $slug, string $batchId, string $artifactSha): string
    {
        return hash('sha256', json_encode([
            'source' => self::SOURCE,
            'canonical_slug' => $slug,
            'target_index_state' => self::TARGET_INDEX_STATE,
            'target_runtime_state' => self::TARGET_RUNTIME_STATE,
            'batch_id' => $batchId,
            'artifact_sha256' => $artifactSha,
        ], JSON_THROW_ON_ERROR));
    }

    private function latestIndexStatePayload(string $slug, ?IndexState $latest): array
    {
        if ($latest === null) {
            return [
                'canonical_slug' => $slug,
                'index_state_id' => null,
                'index_state' => null,
                'index_eligible' => false,
                'public_facing_state' => null,
                'runtime_publish_state' => null,
                'changed_at' => null,
                'reason_codes' => [],
            ];
        }

        $state = (string) $latest->getAttribute('index_state');
        $eligible = (bool) $latest->getAttribute('index_eligible');

        return [
            'canonical_slug' => $slug,
            'index_state_id' => (string) $latest->getAttribute('id'),
            'index_state' => $state,
            'index_eligible' => $eligible,
            'public_facing_state' => IndexStateValue::publicFacing($state, $eligible),
            'runtime_publish_state' => $state === self::TARGET_INDEX_STATE && $eligible ? self::TARGET_RUNTIME_STATE : null,
            'changed_at' => $latest->getAttribute('changed_at')?->toISOString(),
            'reason_codes' => $latest->getAttribute('reason_codes') ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $artifact
     * @param  list<array<string, mixed>>  $blockers
     * @return array<string, mixed>
     */
    private function dryRunPayload(array $plan, array $artifact, string $artifactPath, string $artifactSha, string $batchId, string $reason, int $maxSlugs, ?int $expectedSlugCount, array $blockers): array
    {
        return [
            'status' => $blockers === [] ? 'planned' : 'blocked',
            'mode' => 'dry_run',
            'dry_run' => true,
            'writes_database' => false,
            'write_verified' => false,
            'target_runtime_state' => self::TARGET_RUNTIME_STATE,
            'target_index_state' => self::TARGET_INDEX_STATE,
            'batch_id' => $batchId,
            'reason' => $reason,
            'source_artifact' => $artifactPath,
            'source_kind' => $artifact['source_kind'],
            'artifact_schema_version' => $artifact['schema_version'],
            'artifact_sha256' => $artifactSha,
            'slug_count' => count($plan['slugs']),
            'locales' => $plan['locales'],
            'expected_locale_rows' => count($plan['slugs']) * count($plan['locales']),
            'max_slugs' => $maxSlugs,
            'expect_slug_count' => $expectedSlugCount,
            'slugs' => $plan['slugs'],
            'missing_occupations' => $plan['missing_occupations'],
            'existing_latest_states' => $plan['existing_latest_states'],
            'planned_writes' => $plan['planned_writes'],
            'planned_write_count' => count($plan['planned_writes']),
            'blockers' => $blockers,
            'by_reason' => $this->byReason($blockers),
            'approval_phrase_template' => 'I explicitly approve Career 80 delta runtime candidate preparation apply for reviewed artifact <ARTIFACT> with sha256 <SHA256> and <COUNT> slugs on <ENVIRONMENT>; no deploy, rollout, backfill, rollback, quarantine, publication expansion, or fap-web change is approved.',
            'non_goals' => [
                'no_rollout_apply',
                'no_rollout_dry_run',
                'no_backfill',
                'no_promotion_to_published',
                'no_publication_expansion',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $artifact
     * @param  list<array<string, mixed>>  $blockers
     * @return array<string, mixed>
     */
    private function blockedApplyPayload(array $plan, array $artifact, string $artifactPath, string $artifactSha, string $batchId, string $reason, int $maxSlugs, ?int $expectedSlugCount, array $blockers): array
    {
        return [
            'status' => 'blocked',
            'mode' => 'apply',
            'dry_run' => false,
            'writes_database' => false,
            'write_verified' => false,
            'target_runtime_state' => self::TARGET_RUNTIME_STATE,
            'target_index_state' => self::TARGET_INDEX_STATE,
            'batch_id' => $batchId,
            'reason' => $reason,
            'source_artifact' => $artifactPath,
            'source_kind' => $artifact['source_kind'],
            'artifact_schema_version' => $artifact['schema_version'],
            'artifact_sha256' => $artifactSha,
            'slug_count' => count($plan['slugs']),
            'locales' => $plan['locales'],
            'expected_locale_rows' => count($plan['slugs']) * count($plan['locales']),
            'max_slugs' => $maxSlugs,
            'expect_slug_count' => $expectedSlugCount,
            'slugs' => $plan['slugs'],
            'missing_occupations' => $plan['missing_occupations'],
            'planned_writes' => $plan['planned_writes'],
            'planned_write_count' => count($plan['planned_writes']),
            'created_count' => 0,
            'verified_count' => 0,
            'failures' => [],
            'blockers' => $blockers,
            'by_reason' => $this->byReason($blockers),
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    private function apply(array $plan, array $artifact, string $artifactPath, string $artifactSha, string $batchId, string $reason, int $maxSlugs, ?int $expectedSlugCount): array
    {
        $created = [];
        $failures = [];

        DB::transaction(function () use ($plan, &$created, &$failures): void {
            foreach ($plan['planned_writes'] as $write) {
                try {
                    /** @var IndexState $indexState */
                    $indexState = IndexState::query()->create([
                        'occupation_id' => $write['occupation_id'],
                        'index_state' => $write['target_index_state'],
                        'index_eligible' => $write['index_eligible'],
                        'canonical_path' => $write['canonical_path'],
                        'canonical_target' => $write['canonical_target'],
                        'reason_codes' => $write['reason_codes'],
                        'changed_at' => now(),
                        'row_fingerprint' => $write['row_fingerprint'],
                    ]);

                    $created[] = [
                        'canonical_slug' => $write['canonical_slug'],
                        'index_state_id' => (string) $indexState->getAttribute('id'),
                        'runtime_publish_state' => self::TARGET_RUNTIME_STATE,
                    ];
                } catch (Throwable $exception) {
                    $failures[] = [
                        'canonical_slug' => $write['canonical_slug'],
                        'reason' => 'runtime_candidate_preparation_write_failed',
                        'message' => $exception->getMessage(),
                    ];
                }
            }

            if ($failures !== []) {
                throw new RuntimeException('runtime_candidate_preparation_write_failed');
            }
        });

        $verification = $this->verifyWrites($plan['slugs'], $artifactSha);
        $blockers = $verification['blockers'];

        return [
            'status' => $blockers === [] ? 'applied' : 'blocked',
            'mode' => 'apply',
            'dry_run' => false,
            'writes_database' => true,
            'write_verified' => $blockers === [],
            'target_runtime_state' => self::TARGET_RUNTIME_STATE,
            'target_index_state' => self::TARGET_INDEX_STATE,
            'batch_id' => $batchId,
            'reason' => $reason,
            'source_artifact' => $artifactPath,
            'source_kind' => $artifact['source_kind'],
            'artifact_schema_version' => $artifact['schema_version'],
            'artifact_sha256' => $artifactSha,
            'slug_count' => count($plan['slugs']),
            'locales' => $plan['locales'],
            'expected_locale_rows' => count($plan['slugs']) * count($plan['locales']),
            'max_slugs' => $maxSlugs,
            'expect_slug_count' => $expectedSlugCount,
            'created_count' => count($created),
            'verified_count' => $verification['verified_count'],
            'created' => $created,
            'failures' => $failures,
            'blockers' => $blockers,
            'by_reason' => $this->byReason($blockers),
        ];
    }

    /**
     * @param  list<string>  $slugs
     * @return array{verified_count: int, blockers: list<array<string, mixed>>}
     */
    private function verifyWrites(array $slugs, string $artifactSha): array
    {
        $occupations = Occupation::query()
            ->whereIn('canonical_slug', $slugs)
            ->with(['indexStates' => fn ($query) => $query
                ->orderByDesc('changed_at')
                ->orderByDesc('created_at')
                ->orderByDesc('updated_at')])
            ->get()
            ->keyBy(fn (Occupation $occupation): string => strtolower((string) $occupation->getAttribute('canonical_slug')));

        $verified = 0;
        $blockers = [];

        foreach ($slugs as $slug) {
            /** @var Occupation|null $occupation */
            $occupation = $occupations->get($slug);
            /** @var IndexState|null $latest */
            $latest = $occupation?->indexStates->first();
            $state = (string) ($latest?->getAttribute('index_state') ?? '');
            $eligible = (bool) ($latest?->getAttribute('index_eligible') ?? false);
            $reasonCodes = $latest?->getAttribute('reason_codes') ?? [];

            if ($latest === null || $state !== self::TARGET_INDEX_STATE || ! $eligible) {
                $blockers[] = $this->blocker('write_verification_failed', 'Latest index_state is not a prepared published_candidate runtime row.', ['canonical_slug' => $slug]);

                continue;
            }

            if (! in_array(self::SOURCE, $reasonCodes, true) || ! in_array('artifact_sha256:'.$artifactSha, $reasonCodes, true)) {
                $blockers[] = $this->blocker('write_metadata_verification_failed', 'Latest prepared candidate row is missing source or artifact metadata.', ['canonical_slug' => $slug]);

                continue;
            }

            $verified++;
        }

        return ['verified_count' => $verified, 'blockers' => $blockers];
    }

    /**
     * @param  list<array<string, mixed>>  $blockers
     * @return array<string, int>
     */
    private function byReason(array $blockers): array
    {
        $counts = [];
        foreach ($blockers as $blocker) {
            $reason = (string) ($blocker['reason'] ?? 'unknown');
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function blocker(string $reason, string $message, array $evidence = []): array
    {
        return [
            'reason' => $reason,
            'message' => $message,
            'evidence' => $evidence,
        ];
    }

    private function reasonKey(string $message): string
    {
        $value = strtolower(trim($message));
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?: 'command_failed';

        return trim($value, '_') ?: 'command_failed';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finish(array $payload, int $exitCode): int
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            $this->error('failed_to_encode_json_payload');

            return self::FAILURE;
        }

        $outputPath = trim((string) ($this->option('output') ?? ''));
        if ($outputPath !== '') {
            file_put_contents($outputPath, $encoded.PHP_EOL);
        }

        if ((bool) $this->option('json')) {
            $this->line($encoded);
        } else {
            $this->line('status='.(string) ($payload['status'] ?? 'unknown'));
            $this->line('writes_database='.(($payload['writes_database'] ?? false) ? 'true' : 'false'));
        }

        return $exitCode;
    }
}
