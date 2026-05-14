<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class CareerGenerateCanonicalExpansionManifestTrain extends Command
{
    private const SCHEMA_VERSION = 'career_canonical_expansion_manifest_train.v1';

    protected $signature = 'career:generate-canonical-expansion-manifest-train
        {--readiness= : Required Career cohort readiness JSON artifact}
        {--target=80 : Target cohort size}
        {--batch-id= : Optional batch id; defaults to career_80_canonical_001 for target 80}
        {--locales=en,zh : Comma-separated locale list}
        {--json : Emit JSON output}
        {--output= : Optional output path for manifest train JSON}
        {--strict : Fail on ambiguous selected rows}';

    protected $description = 'Generate a read-only Career canonical expansion manifest train from a readiness artifact.';

    public function handle(): int
    {
        try {
            $readinessPath = $this->requiredOption('readiness');
            $target = $this->positiveIntOption('target', 80);
            $locales = $this->locales();
            $batchId = $this->batchId($target);
            $readiness = $this->readiness($readinessPath);
            $payload = $this->manifestTrain($readinessPath, $readiness, $target, $batchId, $locales);

            return $this->finish($payload, $payload['status'] === 'pass' ? self::SUCCESS : self::FAILURE);
        } catch (Throwable $exception) {
            return $this->finish($this->blockedPayload($this->reasonKey($exception->getMessage()), $exception->getMessage()), self::FAILURE);
        }
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

    /**
     * @return list<string>
     */
    private function locales(): array
    {
        $raw = trim((string) ($this->option('locales') ?? 'en,zh'));
        $locales = [];
        foreach (explode(',', $raw) as $locale) {
            $normalized = strtolower(trim($locale));
            if ($normalized !== '' && ! in_array($normalized, $locales, true)) {
                $locales[] = $normalized;
            }
        }

        if ($locales === []) {
            throw new RuntimeException('locales_empty');
        }

        return $locales;
    }

    private function batchId(int $target): string
    {
        $batchId = trim((string) ($this->option('batch-id') ?? ''));
        if ($batchId !== '') {
            return $batchId;
        }

        return $target === 80 ? 'career_80_canonical_001' : 'career_'.$target.'_canonical_001';
    }

    /**
     * @return array<string, mixed>
     */
    private function readiness(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('readiness_artifact_missing');
        }

        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException('readiness_artifact_unreadable');
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('readiness_artifact_json_invalid');
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('readiness_artifact_shape_invalid');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @param  list<string>  $locales
     * @return array<string, mixed>
     */
    private function manifestTrain(string $readinessPath, array $readiness, int $target, string $batchId, array $locales): array
    {
        $blockers = $this->readinessBlockers($readiness, $target);
        if ($blockers !== []) {
            return $this->blockedManifest($readinessPath, $readiness, $target, $blockers);
        }

        $slugs = $this->selectedSlugs($readiness, $target);
        $duplicate = $this->firstDuplicate($slugs);
        if ($duplicate !== null) {
            return $this->blockedManifest($readinessPath, $readiness, $target, [
                $this->blocker('selected_slugs_duplicate', 'Readiness artifact selected the same slug more than once.', [
                    'slug' => $duplicate,
                ]),
            ]);
        }

        if (count($slugs) < $target) {
            return $this->blockedManifest($readinessPath, $readiness, $target, [
                $this->blocker('selected_slug_count_below_target', 'Readiness artifact does not contain enough selected slugs.', [
                    'target' => $target,
                    'selected_slug_count' => count($slugs),
                ]),
            ]);
        }

        $selectedSlugs = array_slice($slugs, 0, $target);
        $members = $this->members($readiness, $selectedSlugs, $locales);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'pass',
            'target' => $target,
            'source_readiness' => $this->sourceReadiness($readinessPath, $readiness),
            'manifest_count' => 1,
            'selected_count' => count($selectedSlugs),
            'batch_id' => $batchId,
            'rollback_group' => $selectedSlugs,
            'rollout_allowed' => false,
            'dry_run_allowed' => true,
            'apply_allowed' => false,
            'read_only' => true,
            'writes_database' => false,
            'rollout_dry_run_executed' => false,
            'rollout_apply_executed' => false,
            'batches' => [[
                'batch_id' => $batchId,
                'target' => $target,
                'slugs' => $selectedSlugs,
                'locales' => $locales,
                'expected_locale_rows' => count($selectedSlugs) * count($locales),
                'rollback_group' => $selectedSlugs,
                'rollout_allowed' => false,
                'dry_run_allowed' => true,
                'apply_allowed' => false,
                'members' => $members,
            ]],
            'blockers' => [],
            'sidecars' => isset($readiness['sidecars']) && is_array($readiness['sidecars']) ? $readiness['sidecars'] : [],
            'next_required_action' => '80_ROLLOUT_DRY_RUN_READ_ONLY',
        ];
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @return list<array<string, mixed>>
     */
    private function readinessBlockers(array $readiness, int $target): array
    {
        $blockers = [];
        if (($readiness['status'] ?? null) !== 'pass' || ($readiness['readiness_pass'] ?? null) !== true) {
            $blockers[] = $this->blocker('readiness_not_passed', 'Readiness artifact must have status=pass and readiness_pass=true.');
        }

        $manifestAllowed = data_get($readiness, 'rollout.manifest_generation_allowed');
        if ($manifestAllowed !== true) {
            $blockers[] = $this->blocker('manifest_generation_not_allowed', 'Readiness artifact must explicitly allow read-only manifest generation.');
        }

        $selectedCount = $readiness['selected_count'] ?? null;
        if (! is_int($selectedCount) || $selectedCount < $target) {
            $blockers[] = $this->blocker('selected_count_below_target', 'Readiness artifact selected_count is below target.', [
                'target' => $target,
                'selected_count' => $selectedCount,
            ]);
        }

        if (! is_array(data_get($readiness, 'selection.slugs'))) {
            $blockers[] = $this->blocker('selected_slugs_missing', 'Readiness artifact must include selection.slugs.');
        }

        $rolloutGate = data_get($readiness, 'rollout_candidate_gate');
        if (is_array($rolloutGate) && ($rolloutGate['required'] ?? null) === true) {
            $eligibleCount = $rolloutGate['eligible_count'] ?? null;
            if (! is_int($eligibleCount) || $eligibleCount < $target) {
                $blockers[] = $this->blocker('rollout_candidate_gate_below_target', 'Readiness artifact does not contain enough rollout-candidate eligible slugs.', [
                    'target' => $target,
                    'eligible_count' => $eligibleCount,
                ]);
            }
        }

        foreach ($this->selectedRows($readiness, $target) as $row) {
            if (($row['rollout_candidate_eligible'] ?? true) !== true) {
                $blockers[] = $this->blocker('selected_slug_not_rollout_candidate_eligible', 'Readiness artifact selected a slug excluded by the rollout candidate gate.', [
                    'slug' => $row['slug'] ?? null,
                    'exclusion_reasons' => $row['rollout_candidate_exclusions'] ?? [],
                ]);
            }
        }

        return $blockers;
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @return list<string>
     */
    private function selectedSlugs(array $readiness, int $target): array
    {
        $rawSlugs = data_get($readiness, 'selection.slugs');
        if (! is_array($rawSlugs)) {
            return [];
        }

        $slugs = [];
        foreach ($rawSlugs as $slug) {
            if (! is_string($slug) || trim($slug) === '') {
                if ((bool) $this->option('strict')) {
                    throw new RuntimeException('selected_slug_invalid');
                }

                continue;
            }

            $slugs[] = trim($slug);
        }

        return array_slice($slugs, 0, max($target, count($slugs)));
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @return list<array<string, mixed>>
     */
    private function selectedRows(array $readiness, int $target): array
    {
        $selected = $this->selectedSlugs($readiness, $target);
        $selectedSet = array_fill_keys(array_slice($selected, 0, $target), true);
        $rows = data_get($readiness, 'selection.rows');
        if (! is_array($rows)) {
            return [];
        }

        $selectedRows = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['slug']) || ! is_string($row['slug'])) {
                continue;
            }

            if (isset($selectedSet[$row['slug']])) {
                $selectedRows[] = $row;
            }
        }

        return $selectedRows;
    }

    /**
     * @param  list<string>  $slugs
     */
    private function firstDuplicate(array $slugs): ?string
    {
        $seen = [];
        foreach ($slugs as $slug) {
            if (isset($seen[$slug])) {
                return $slug;
            }

            $seen[$slug] = true;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @param  list<string>  $selectedSlugs
     * @param  list<string>  $locales
     * @return list<array<string, mixed>>
     */
    private function members(array $readiness, array $selectedSlugs, array $locales): array
    {
        $rowsBySlug = [];
        $rows = data_get($readiness, 'selection.rows');
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row) && isset($row['slug']) && is_string($row['slug'])) {
                    $rowsBySlug[$row['slug']] = $row;
                }
            }
        }

        $members = [];
        foreach ($selectedSlugs as $slug) {
            $row = $rowsBySlug[$slug] ?? [];
            $rowLocales = isset($row['locales']) && is_array($row['locales'])
                ? array_values(array_filter($row['locales'], static fn (mixed $locale): bool => is_string($locale) && trim($locale) !== ''))
                : $locales;

            $members[] = [
                'slug' => $slug,
                'locales' => $rowLocales === [] ? $locales : array_values(array_unique($rowLocales)),
                'source' => '80_readiness',
                'reasons' => isset($row['reasons']) && is_array($row['reasons']) ? array_values($row['reasons']) : [],
                'sidecars' => isset($row['sidecars']) && is_array($row['sidecars']) ? array_values($row['sidecars']) : [],
            ];
        }

        return $members;
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @return array<string, mixed>
     */
    private function sourceReadiness(string $readinessPath, array $readiness): array
    {
        return [
            'path' => $readinessPath,
            'status' => $readiness['status'] ?? null,
            'readiness_pass' => $readiness['readiness_pass'] ?? null,
            'selected_count' => $readiness['selected_count'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $blockers
     * @return array<string, mixed>
     */
    private function blockedManifest(string $readinessPath, array $readiness, int $target, array $blockers): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'target' => $target,
            'source_readiness' => $this->sourceReadiness($readinessPath, $readiness),
            'manifest_count' => 0,
            'selected_count' => 0,
            'batch_id' => null,
            'rollback_group' => [],
            'rollout_allowed' => false,
            'dry_run_allowed' => false,
            'apply_allowed' => false,
            'read_only' => true,
            'writes_database' => false,
            'rollout_dry_run_executed' => false,
            'rollout_apply_executed' => false,
            'batches' => [],
            'blockers' => $blockers,
            'sidecars' => isset($readiness['sidecars']) && is_array($readiness['sidecars']) ? $readiness['sidecars'] : [],
            'next_required_action' => 'FIX_BLOCKERS',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blockedPayload(string $reason, string $message): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'target' => 80,
            'source_readiness' => null,
            'manifest_count' => 0,
            'selected_count' => 0,
            'batch_id' => null,
            'rollback_group' => [],
            'rollout_allowed' => false,
            'dry_run_allowed' => false,
            'apply_allowed' => false,
            'read_only' => true,
            'writes_database' => false,
            'rollout_dry_run_executed' => false,
            'rollout_apply_executed' => false,
            'batches' => [],
            'blockers' => [$this->blocker($reason, $message)],
            'sidecars' => [],
            'next_required_action' => 'FIX_BLOCKERS',
        ];
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
            $directory = dirname($outputPath);
            if (! is_dir($directory) || ! is_writable($directory)) {
                $payload = $this->blockedPayload('output_path_unwritable', 'Output path directory is not writable.');
                $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                if (! is_string($encoded)) {
                    $this->error('failed_to_encode_json_payload');

                    return self::FAILURE;
                }
                $exitCode = self::FAILURE;
            } else {
                File::put($outputPath, $encoded.PHP_EOL);
            }
        }

        if ((bool) $this->option('json')) {
            $this->line($encoded);
        } else {
            $this->line('status='.(string) ($payload['status'] ?? 'unknown'));
            $this->line('selected_count='.(string) ($payload['selected_count'] ?? 0));
        }

        return $exitCode;
    }
}
