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

final class CareerRemediateCanonicalIndexState extends Command
{
    private const SOURCE = 'career_2786_minimum_index_state_remediation';

    private const DEFAULT_MAX_SLUGS = 100;

    protected $signature = 'career:remediate-canonical-index-state
        {--slug-artifact= : Reviewed explicit slug artifact JSON path}
        {--target-state=indexed : Target index_state value}
        {--batch-id= : Reviewed remediation batch id}
        {--reason= : Explicit remediation reason}
        {--dry-run : Plan without writing}
        {--apply : Execute guarded index_state writes}
        {--max-slugs=100 : Maximum allowed explicit slugs}
        {--expect-slug-count= : Expected slug count for confirmation}
        {--confirm-artifact-sha256= : Required for --apply; must match slug artifact sha256}
        {--json : Emit JSON output}
        {--output= : Optional output path for JSON payload}';

    protected $description = 'Guarded explicit-slug Career index_state remediation dry-run/apply gate.';

    public function handle(): int
    {
        try {
            $mode = $this->mode();
            $artifactPath = $this->requiredOption('slug-artifact');
            $targetState = $this->targetState();
            $batchId = $this->requiredOption('batch-id');
            $reason = $this->reason($mode);
            $maxSlugs = $this->positiveIntOption('max-slugs', self::DEFAULT_MAX_SLUGS);
            $expectedSlugCount = $this->nullablePositiveIntOption('expect-slug-count');

            [$artifact, $artifactSha] = $this->readSlugArtifact($artifactPath);
            $slugs = $artifact['slugs'];
            $slugCount = count($slugs);

            $blockers = [
                ...$this->confirmationBlockers($mode, $artifactSha, $slugCount, $expectedSlugCount),
                ...$this->artifactSafetyBlockers($slugCount, $maxSlugs),
            ];

            $plan = $this->plan($slugs, $targetState, $batchId, $reason, $artifactPath, $artifactSha);
            $blockers = [
                ...$blockers,
                ...$plan['blockers'],
            ];

            if ($mode === 'dry-run' || $blockers !== []) {
                return $this->finish($this->dryRunPayload(
                    $plan,
                    $artifact,
                    $artifactPath,
                    $artifactSha,
                    $targetState,
                    $batchId,
                    $reason,
                    $maxSlugs,
                    $expectedSlugCount,
                    $blockers
                ), $blockers === [] ? self::SUCCESS : self::FAILURE);
            }

            $payload = $this->apply($plan, $artifact, $artifactPath, $artifactSha, $targetState, $batchId, $reason, $maxSlugs, $expectedSlugCount);

            return $this->finish($payload, ($payload['status'] ?? null) === 'applied' ? self::SUCCESS : self::FAILURE);
        } catch (Throwable $exception) {
            return $this->finish([
                'status' => 'blocked',
                'writes_database' => false,
                'write_verified' => false,
                'by_reason' => [$this->reasonKey($exception->getMessage()) => 1],
                'blockers' => [[
                    'reason' => $this->reasonKey($exception->getMessage()),
                    'message' => $exception->getMessage(),
                ]],
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

    private function requiredOption(string $name): string
    {
        $value = trim((string) ($this->option($name) ?? ''));
        if ($value === '') {
            throw new RuntimeException(str_replace('-', '_', $name).'_missing');
        }

        return $value;
    }

    private function reason(string $mode): string
    {
        $value = trim((string) ($this->option('reason') ?? ''));

        if ($value === '' && $mode === 'apply') {
            throw new RuntimeException('reason_missing');
        }

        return $value !== '' ? $value : 'minimum_80_candidate_unlock';
    }

    private function targetState(): string
    {
        $targetState = strtolower(trim((string) ($this->option('target-state') ?? '')));
        if (! IndexStateValue::isIndexedLike($targetState, true)) {
            throw new RuntimeException('target_state_not_indexed_like');
        }

        return $targetState;
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
    private function readSlugArtifact(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('slug_artifact_missing');
        }

        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException('slug_artifact_unreadable');
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('slug_artifact_json_invalid');
        }

        $slugs = is_array($decoded) && array_is_list($decoded)
            ? $decoded
            : (is_array($decoded) ? ($decoded['slugs'] ?? null) : null);

        if (! is_array($slugs) || ! array_is_list($slugs)) {
            throw new RuntimeException('slug_list_missing');
        }

        $normalized = [];
        $seen = [];
        foreach ($slugs as $index => $slug) {
            if (! is_string($slug)) {
                throw new RuntimeException('slug_invalid_at_'.$index);
            }

            $value = strtolower(trim($slug));
            if ($value === '' || str_contains($value, '*')) {
                throw new RuntimeException('slug_invalid_at_'.$index);
            }

            if (isset($seen[$value])) {
                throw new RuntimeException('duplicate_slug_'.$value);
            }

            $seen[$value] = true;
            $normalized[] = $value;
        }

        if ($normalized === []) {
            throw new RuntimeException('slug_list_empty');
        }

        $declaredCount = null;
        if (is_array($decoded) && ! array_is_list($decoded)) {
            $declaredCount = $decoded['count']
                ?? $decoded['slug_count']
                ?? data_get($decoded, 'target.slug_count')
                ?? data_get($decoded, 'target.needed_additional_count');
        }

        if ($declaredCount !== null && (int) $declaredCount !== count($normalized)) {
            throw new RuntimeException('slug_count_declared_mismatch');
        }

        return [[
            'schema_version' => is_array($decoded) && ! array_is_list($decoded) ? ($decoded['schema_version'] ?? null) : 'slug_list.v1',
            'declared_count' => $declaredCount === null ? count($normalized) : (int) $declaredCount,
            'slugs' => $normalized,
            'raw_summary' => is_array($decoded) && ! array_is_list($decoded) ? [
                'source' => $decoded['source'] ?? null,
                'target' => $decoded['target'] ?? null,
                'count' => $decoded['count'] ?? null,
            ] : null,
        ], hash('sha256', $contents)];
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
     * @return array{slugs: list<string>, missing_occupations: list<string>, existing_latest_index_states: list<array<string, mixed>>, planned_writes: list<array<string, mixed>>, blockers: list<array<string, mixed>>}
     */
    private function plan(array $slugs, string $targetState, string $batchId, string $reason, string $artifactPath, string $artifactSha): array
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
                $blockers[] = $this->blocker('occupation_missing', 'Occupation is required before index_state remediation apply.', ['canonical_slug' => $slug]);

                continue;
            }

            /** @var IndexState|null $latest */
            $latest = $occupation->indexStates->first();
            $existing[] = $this->latestIndexStatePayload($slug, $latest);

            if ($latest !== null && IndexStateValue::isIndexedLike((string) $latest->getAttribute('index_state'), (bool) $latest->getAttribute('index_eligible'))) {
                continue;
            }

            $writes[] = [
                'canonical_slug' => $slug,
                'occupation_id' => (string) $occupation->getAttribute('id'),
                'target_state' => $targetState,
                'index_eligible' => true,
                'canonical_path' => '/career/jobs/'.$slug,
                'canonical_target' => null,
                'reason_codes' => $this->reasonCodes($batchId, $reason, $artifactPath, $artifactSha, $targetState),
                'row_fingerprint' => $this->rowFingerprint($slug, $targetState, $batchId, $artifactSha),
            ];
        }

        return [
            'slugs' => $slugs,
            'missing_occupations' => $missing,
            'existing_latest_index_states' => $existing,
            'planned_writes' => $writes,
            'blockers' => $blockers,
        ];
    }

    /**
     * @return list<string>
     */
    private function reasonCodes(string $batchId, string $reason, string $artifactPath, string $artifactSha, string $targetState): array
    {
        return [
            self::SOURCE,
            'minimum_80_candidate_unlock',
            'batch_id:'.$batchId,
            'reason:'.Str::slug($reason, '_'),
            'artifact_sha256:'.$artifactSha,
            'artifact_basename:'.basename($artifactPath),
            'target_state:'.$targetState,
        ];
    }

    private function rowFingerprint(string $slug, string $targetState, string $batchId, string $artifactSha): string
    {
        return hash('sha256', json_encode([
            'source' => self::SOURCE,
            'canonical_slug' => $slug,
            'target_state' => $targetState,
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
    private function dryRunPayload(array $plan, array $artifact, string $artifactPath, string $artifactSha, string $targetState, string $batchId, string $reason, int $maxSlugs, ?int $expectedSlugCount, array $blockers): array
    {
        return [
            'status' => $blockers === [] ? 'planned' : 'blocked',
            'mode' => 'dry_run',
            'writes_database' => false,
            'write_verified' => false,
            'target_state' => $targetState,
            'batch_id' => $batchId,
            'reason' => $reason,
            'slug_artifact' => $artifactPath,
            'artifact_schema_version' => $artifact['schema_version'],
            'artifact_sha256' => $artifactSha,
            'slug_count' => count($plan['slugs']),
            'max_slugs' => $maxSlugs,
            'expect_slug_count' => $expectedSlugCount,
            'slugs' => $plan['slugs'],
            'missing_occupations' => $plan['missing_occupations'],
            'existing_latest_index_states' => $plan['existing_latest_index_states'],
            'planned_writes' => $plan['planned_writes'],
            'planned_write_count' => count($plan['planned_writes']),
            'blockers' => $blockers,
            'by_reason' => $this->byReason($blockers),
            'approval_phrase_template' => 'I explicitly approve Career 2786 minimum index_state remediation apply for reviewed slug artifact <SLUG_ARTIFACT> with sha256 <SHA256> and <COUNT> slugs on <ENVIRONMENT>; no deploy, rollout, backfill, rollback, quarantine, or publication expansion is approved.',
            'read_only' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    private function apply(array $plan, array $artifact, string $artifactPath, string $artifactSha, string $targetState, string $batchId, string $reason, int $maxSlugs, ?int $expectedSlugCount): array
    {
        $created = [];
        $failures = [];

        DB::transaction(function () use ($plan, &$created, &$failures): void {
            foreach ($plan['planned_writes'] as $write) {
                try {
                    /** @var IndexState $indexState */
                    $indexState = IndexState::query()->create([
                        'occupation_id' => $write['occupation_id'],
                        'index_state' => $write['target_state'],
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
                    ];
                } catch (Throwable $exception) {
                    $failures[] = [
                        'canonical_slug' => $write['canonical_slug'],
                        'reason' => 'index_state_write_failed',
                        'message' => $exception->getMessage(),
                    ];
                }
            }

            if ($failures !== []) {
                throw new RuntimeException('index_state_write_failed');
            }
        });

        $verification = $this->verifyWrites($plan['slugs'], $targetState, $artifactSha);
        $blockers = $verification['blockers'];

        return [
            'status' => $blockers === [] ? 'applied' : 'blocked',
            'mode' => 'apply',
            'writes_database' => $blockers === [],
            'write_verified' => $blockers === [],
            'target_state' => $targetState,
            'batch_id' => $batchId,
            'reason' => $reason,
            'slug_artifact' => $artifactPath,
            'artifact_schema_version' => $artifact['schema_version'],
            'artifact_sha256' => $artifactSha,
            'slug_count' => count($plan['slugs']),
            'max_slugs' => $maxSlugs,
            'expect_slug_count' => $expectedSlugCount,
            'created_count' => count($created),
            'verified_count' => $verification['verified_count'],
            'created' => $created,
            'failures' => $failures,
            'blockers' => $blockers,
            'by_reason' => $this->byReason($blockers),
            'read_only' => false,
        ];
    }

    /**
     * @param  list<string>  $slugs
     * @return array{verified_count: int, blockers: list<array<string, mixed>>}
     */
    private function verifyWrites(array $slugs, string $targetState, string $artifactSha): array
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

            if ($latest === null || ! IndexStateValue::isIndexedLike($state, $eligible)) {
                $blockers[] = $this->blocker('write_verification_failed', 'Latest index_state is not indexed-like after apply.', ['canonical_slug' => $slug]);

                continue;
            }

            if ($state !== $targetState || ! in_array('artifact_sha256:'.$artifactSha, $reasonCodes, true)) {
                $blockers[] = $this->blocker('write_metadata_verification_failed', 'Latest index_state is missing expected target state or artifact metadata.', ['canonical_slug' => $slug]);

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
