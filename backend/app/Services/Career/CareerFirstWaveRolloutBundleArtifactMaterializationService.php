<?php

declare(strict_types=1);

namespace App\Services\Career;

use App\Domain\Career\Publish\CareerFirstWaveRolloutBundleProjectionService;
use Illuminate\Support\Facades\File;

class CareerFirstWaveRolloutBundleArtifactMaterializationService
{
    public const OUTPUT_ROOT = 'app/private/career_rollout_bundle_artifacts';

    public function __construct(
        private readonly CareerFirstWaveRolloutBundleProjectionService $projectionService,
    ) {}

    /**
     * @return array{
     *   status:string,
     *   output_dir:string,
     *   artifacts:array{
     *     career-rollout-bundle.json:string,
     *     career-stable-whitelist.json:string,
     *     career-candidate-whitelist.json:string,
     *     career-hold-list.json:string,
     *     career-blocked-list.json:string
     *   }
     * }
     */
    public function materialize(?string $timestamp = null): array
    {
        $normalizedTimestamp = $this->normalizeTimestamp($timestamp);
        $rootDir = storage_path(self::OUTPUT_ROOT);
        $finalDir = $rootDir.DIRECTORY_SEPARATOR.$normalizedTimestamp;
        $tmpDir = $finalDir.'.tmp';

        if (is_dir($finalDir)) {
            throw new \RuntimeException('rollout bundle artifact output dir already exists: '.$finalDir);
        }
        if (is_dir($tmpDir)) {
            throw new \RuntimeException('rollout bundle artifact temp dir already exists: '.$tmpDir);
        }

        $projected = $this->projectedArtifacts();
        $this->validateProjectedFilenameSet($projected);

        $payloads = [];
        foreach ($this->expectedFilenames() as $filename) {
            $payloads[$filename] = $this->extractArtifactPayload($projected, $filename);
        }

        $this->validateArtifactPayloads($payloads);

        File::ensureDirectoryExists($tmpDir);

        try {
            foreach ($payloads as $filename => $payload) {
                $this->writeJsonFile($tmpDir.DIRECTORY_SEPARATOR.$filename, $payload);
            }

            if (! @rename($tmpDir, $finalDir)) {
                throw new \RuntimeException('failed to finalize rollout bundle artifact directory: '.$finalDir);
            }
        } catch (\Throwable $e) {
            if (is_dir($tmpDir)) {
                File::deleteDirectory($tmpDir);
            }

            throw $e;
        }

        return [
            'status' => 'materialized',
            'output_dir' => $finalDir,
            'artifacts' => collect($this->expectedFilenames())
                ->mapWithKeys(fn (string $filename): array => [$filename => $finalDir.DIRECTORY_SEPARATOR.$filename])
                ->all(),
        ];
    }

    /**
     * @return array<string, object>
     */
    protected function projectedArtifacts(): array
    {
        return $this->projectionService->build();
    }

    /**
     * @param  array<string, object>  $projected
     */
    private function validateProjectedFilenameSet(array $projected): void
    {
        $actual = array_values(array_keys($projected));
        sort($actual);

        $expected = $this->expectedFilenames();
        sort($expected);

        if ($actual !== $expected) {
            throw new \RuntimeException('projected rollout bundle artifacts filename set is invalid');
        }
    }

    /**
     * @param  array<string, object>  $projected
     * @return array<string, mixed>
     */
    private function extractArtifactPayload(array $projected, string $filename): array
    {
        $artifact = $projected[$filename] ?? null;
        if (! is_object($artifact) || ! method_exists($artifact, 'toArray')) {
            throw new \RuntimeException('invalid projected artifact for '.$filename);
        }

        /** @var mixed $payload */
        $payload = $artifact->toArray();
        if (! is_array($payload)) {
            throw new \RuntimeException('projected artifact payload is not an array for '.$filename);
        }

        return $payload;
    }

    /**
     * @param  array<string, array<string, mixed>>  $payloads
     */
    private function validateArtifactPayloads(array $payloads): void
    {
        foreach ($this->expectedFilenames() as $filename) {
            $encoded = json_encode($payloads[$filename], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (! is_string($encoded)) {
                throw new \RuntimeException('failed to encode json for '.$filename);
            }

            $decoded = json_decode($encoded, true);
            if (! is_array($decoded)) {
                throw new \RuntimeException('json round-trip decode failed for '.$filename);
            }
        }

        $bundle = $payloads[CareerFirstWaveRolloutBundleProjectionService::BUNDLE_FILENAME] ?? [];
        $stable = $payloads[CareerFirstWaveRolloutBundleProjectionService::STABLE_LIST_FILENAME] ?? [];
        $candidate = $payloads[CareerFirstWaveRolloutBundleProjectionService::CANDIDATE_LIST_FILENAME] ?? [];
        $hold = $payloads[CareerFirstWaveRolloutBundleProjectionService::HOLD_LIST_FILENAME] ?? [];
        $blocked = $payloads[CareerFirstWaveRolloutBundleProjectionService::BLOCKED_LIST_FILENAME] ?? [];

        $bundleStable = $this->normalizedSlugList((array) data_get($bundle, 'cohorts.stable', []), 'bundle.cohorts.stable');
        $bundleCandidate = $this->normalizedSlugList((array) data_get($bundle, 'cohorts.candidate', []), 'bundle.cohorts.candidate');
        $bundleHold = $this->normalizedSlugList((array) data_get($bundle, 'cohorts.hold', []), 'bundle.cohorts.hold');
        $bundleBlocked = $this->normalizedSlugList((array) data_get($bundle, 'cohorts.blocked', []), 'bundle.cohorts.blocked');

        $stableMembers = $this->normalizedSlugList((array) ($stable['members'] ?? []), 'stable.members');
        $candidateMembers = $this->normalizedSlugList((array) ($candidate['members'] ?? []), 'candidate.members');
        $holdMembers = $this->normalizedSlugList((array) ($hold['members'] ?? []), 'hold.members');
        $blockedMembers = $this->normalizedSlugList((array) ($blocked['members'] ?? []), 'blocked.members');

        if ($bundleStable !== $stableMembers) {
            throw new \RuntimeException('bundle/list slug mismatch for stable cohort');
        }
        if ($bundleCandidate !== $candidateMembers) {
            throw new \RuntimeException('bundle/list slug mismatch for candidate cohort');
        }
        if ($bundleHold !== $holdMembers) {
            throw new \RuntimeException('bundle/list slug mismatch for hold cohort');
        }
        if ($bundleBlocked !== $blockedMembers) {
            throw new \RuntimeException('bundle/list slug mismatch for blocked cohort');
        }

        $membersByCohort = collect((array) ($bundle['members'] ?? []))
            ->groupBy(static fn (array $member): string => (string) ($member['rollout_cohort'] ?? ''))
            ->map(static function ($rows): array {
                return collect($rows)
                    ->map(static fn (array $row): string => trim((string) ($row['canonical_slug'] ?? '')))
                    ->all();
            });

        $this->assertSameSlugSet(
            $this->normalizedSlugList((array) ($membersByCohort->get('stable', [])), 'members.stable'),
            $bundleStable,
            'stable'
        );
        $this->assertSameSlugSet(
            $this->normalizedSlugList((array) ($membersByCohort->get('candidate', [])), 'members.candidate'),
            $bundleCandidate,
            'candidate'
        );
        $this->assertSameSlugSet(
            $this->normalizedSlugList((array) ($membersByCohort->get('hold', [])), 'members.hold'),
            $bundleHold,
            'hold'
        );
        $this->assertSameSlugSet(
            $this->normalizedSlugList((array) ($membersByCohort->get('blocked', [])), 'members.blocked'),
            $bundleBlocked,
            'blocked'
        );

        if (array_key_exists('career-manual-review-needed.json', $payloads)) {
            throw new \RuntimeException('unexpected advisory standalone artifact detected');
        }
    }

    /**
     * @param  list<mixed>  $slugs
     * @return list<string>
     */
    private function normalizedSlugList(array $slugs, string $label): array
    {
        $normalized = array_map(
            static fn (mixed $slug): string => trim((string) $slug),
            array_values($slugs),
        );

        if (in_array('', $normalized, true)) {
            throw new \RuntimeException('empty canonical_slug found in '.$label);
        }

        if (count(array_unique($normalized)) !== count($normalized)) {
            throw new \RuntimeException('duplicate canonical_slug found in '.$label);
        }

        return $normalized;
    }

    /**
     * @param  list<string>  $fromMembers
     * @param  list<string>  $fromBundle
     */
    private function assertSameSlugSet(array $fromMembers, array $fromBundle, string $cohort): void
    {
        $left = $fromMembers;
        $right = $fromBundle;
        sort($left);
        sort($right);

        if ($left !== $right) {
            throw new \RuntimeException('bundle members/cohort slug mismatch for '.$cohort);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJsonFile(string $path, array $payload): void
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            throw new \RuntimeException('failed to encode json: '.$path);
        }

        File::put($path, $encoded.PHP_EOL);
    }

    /**
     * @return array{
     *   0:string,
     *   1:string,
     *   2:string,
     *   3:string,
     *   4:string
     * }
     */
    private function expectedFilenames(): array
    {
        return [
            CareerFirstWaveRolloutBundleProjectionService::BUNDLE_FILENAME,
            CareerFirstWaveRolloutBundleProjectionService::STABLE_LIST_FILENAME,
            CareerFirstWaveRolloutBundleProjectionService::CANDIDATE_LIST_FILENAME,
            CareerFirstWaveRolloutBundleProjectionService::HOLD_LIST_FILENAME,
            CareerFirstWaveRolloutBundleProjectionService::BLOCKED_LIST_FILENAME,
        ];
    }

    private function normalizeTimestamp(?string $timestamp): string
    {
        $value = trim((string) $timestamp);
        if ($value === '') {
            $value = now('UTC')->format('Ymd\THis\Z');
        }

        if (! preg_match('/^[A-Za-z0-9._-]+$/', $value)) {
            throw new \RuntimeException('invalid timestamp segment for rollout bundle artifact export');
        }

        return $value;
    }
}
