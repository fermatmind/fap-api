<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerCanonicalExpansionManifestTrainBatch
{
    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $locales
     * @param  list<string>  $rollbackGroup
     * @param  list<CareerCanonicalExpansionManifestTrainIssue>  $issues
     */
    public function __construct(
        public readonly string $stage,
        public readonly string $batchId,
        public readonly int $batchSize,
        public readonly array $slugs,
        public readonly array $locales,
        public readonly array $rollbackGroup,
        public readonly string $readinessGate,
        public readonly string $rolloutState,
        public readonly bool $releaseGateRequired,
        public readonly bool $surfaceEqualityRequired,
        public readonly bool $dryRunOnly,
        public readonly array $issues = [],
    ) {
        self::assertNonEmptyString($this->stage, 'stage');
        self::assertNonEmptyString($this->batchId, 'batch_id');
        if ($this->batchSize < 1) {
            throw new InvalidArgumentException('Career manifest train batch_size must be positive.');
        }
        self::assertListOfStrings($this->slugs, 'slugs');
        self::assertListOfStrings($this->locales, 'locales');
        self::assertListOfStrings($this->rollbackGroup, 'rollback_group');
        CareerCanonicalEligibilityStatus::assertValid($this->readinessGate);
        self::assertNonEmptyString($this->rolloutState, 'rollout_state');
        self::assertIssues($this->issues);
    }

    /**
     * @return array{stage: string, readiness_gate: string, dry_run_only: bool, issues: list<array<string, mixed>>, manifest: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'stage' => $this->stage,
            'readiness_gate' => $this->readinessGate,
            'dry_run_only' => $this->dryRunOnly,
            'issues' => array_map(
                static fn (CareerCanonicalExpansionManifestTrainIssue $issue): array => $issue->toArray(),
                $this->issues
            ),
            'manifest' => [
                'batch_id' => $this->batchId,
                'batch_size' => $this->batchSize,
                'slugs' => $this->slugs,
                'locales' => $this->locales,
                'projection_state' => 'published_candidate',
                'release_gate_required' => $this->releaseGateRequired,
                'surface_equality_required' => $this->surfaceEqualityRequired,
                'rollback_group' => $this->rollbackGroup,
                'rollout_state' => $this->rolloutState,
                'candidate_route_semantics' => 'expected_pre_route',
                'candidate_release_gate_applicability' => 'not_applicable_before_promotion',
            ],
        ];
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career manifest train batch requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  list<string>  $value
     */
    private static function assertListOfStrings(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career manifest train batch [%s] must be a list.', $key));
        }

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException(sprintf('Career manifest train batch [%s] must contain non-empty strings.', $key));
            }
        }
    }

    /**
     * @param  list<CareerCanonicalExpansionManifestTrainIssue>  $issues
     */
    private static function assertIssues(array $issues): void
    {
        if (! array_is_list($issues)) {
            throw new InvalidArgumentException('Career manifest train batch issues must be a list.');
        }

        foreach ($issues as $issue) {
            if (! $issue instanceof CareerCanonicalExpansionManifestTrainIssue) {
                throw new InvalidArgumentException('Career manifest train batch issues must contain issue DTOs.');
            }
        }
    }
}
