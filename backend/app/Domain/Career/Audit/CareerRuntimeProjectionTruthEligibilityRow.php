<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerRuntimeProjectionTruthEligibilityRow
{
    /**
     * @param  list<mixed>  $evidence
     * @param  list<CareerRuntimeProjectionTruthEligibilityIssue>  $issues
     */
    public function __construct(
        public readonly string $canonicalSlug,
        public readonly string $locale,
        public readonly ?bool $ledgerMemberExists,
        public readonly bool $projectionExists,
        public readonly bool $truthExists,
        public readonly ?string $projectionState,
        public readonly ?string $runtimePublishState,
        public readonly ?string $truthState,
        public readonly ?string $canonicalPublicType,
        public readonly CareerCanonicalEligibilityLayerStatus $runtimeStatus,
        public readonly array $evidence = [],
        public readonly array $issues = [],
    ) {
        self::assertNonEmptyString($this->canonicalSlug, 'canonical_slug');
        self::assertNonEmptyString($this->locale, 'locale');
        self::assertList($this->evidence, 'evidence');

        foreach ([
            'projection_state' => $this->projectionState,
            'runtime_publish_state' => $this->runtimePublishState,
            'truth_state' => $this->truthState,
            'canonical_public_type' => $this->canonicalPublicType,
        ] as $key => $value) {
            if ($value !== null) {
                self::assertNonEmptyString($value, $key);
            }
        }

        if (! array_is_list($this->issues)) {
            throw new InvalidArgumentException('Career runtime projection/truth row issues must be a list.');
        }

        foreach ($this->issues as $issue) {
            if (! $issue instanceof CareerRuntimeProjectionTruthEligibilityIssue) {
                throw new InvalidArgumentException('Career runtime projection/truth row issues must contain issue DTOs.');
            }
        }
    }

    /**
     * @return array{canonical_slug: string, locale: string, ledger_member_exists: bool|null, projection_exists: bool, truth_exists: bool, projection_state: string|null, runtime_publish_state: string|null, truth_state: string|null, canonical_public_type: string|null, runtime_status: array<string, mixed>, evidence: list<mixed>, issues: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'canonical_slug' => $this->canonicalSlug,
            'locale' => $this->locale,
            'ledger_member_exists' => $this->ledgerMemberExists,
            'projection_exists' => $this->projectionExists,
            'truth_exists' => $this->truthExists,
            'projection_state' => $this->projectionState,
            'runtime_publish_state' => $this->runtimePublishState,
            'truth_state' => $this->truthState,
            'canonical_public_type' => $this->canonicalPublicType,
            'runtime_status' => $this->runtimeStatus->toArray(),
            'evidence' => $this->evidence,
            'issues' => array_map(
                static fn (CareerRuntimeProjectionTruthEligibilityIssue $issue): array => $issue->toArray(),
                $this->issues
            ),
        ];
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career runtime projection/truth row requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  list<mixed>  $value
     */
    private static function assertList(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career runtime projection/truth row [%s] must be a list.', $key));
        }
    }
}
