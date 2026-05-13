<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerIndexStateRemediationPlan
{
    public const SCHEMA_VERSION = 'career_index_state_remediation_plan.v1';

    /**
     * @param  list<CareerIndexStateRemediationPlanRow>  $rows
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     * @param  list<CareerCanonicalEligibilityAuditRunContextApprovalGate>  $approvalGates
     */
    public function __construct(
        public readonly string $schemaVersion,
        public readonly array $rows,
        public readonly array $sidecars = [],
        public readonly array $approvalGates = [],
    ) {
        self::assertNonEmptyString($this->schemaVersion, 'schema_version');
        self::assertRows($this->rows);
        self::assertSidecars($this->sidecars);
        self::assertApprovalGates($this->approvalGates);
    }

    /**
     * @param  list<CareerIndexStateRemediationPlanRow>  $rows
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    public static function build(array $rows, array $sidecars = []): self
    {
        return new self(
            schemaVersion: self::SCHEMA_VERSION,
            rows: $rows,
            sidecars: $sidecars,
            approvalGates: self::approvalGatesFor($rows),
        );
    }

    public function createIndexStateCount(): int
    {
        return count(array_filter(
            $this->rows,
            static fn (CareerIndexStateRemediationPlanRow $row): bool => $row->action === CareerIndexStateRemediationPlanRow::ACTION_CREATE_INDEX_STATE
        ));
    }

    public function reviewExistingIndexStateCount(): int
    {
        return count(array_filter(
            $this->rows,
            static fn (CareerIndexStateRemediationPlanRow $row): bool => $row->action === CareerIndexStateRemediationPlanRow::ACTION_REVIEW_EXISTING_INDEX_STATE
        ));
    }

    public function deferredCount(): int
    {
        return count(array_filter(
            $this->rows,
            static fn (CareerIndexStateRemediationPlanRow $row): bool => in_array($row->action, [
                CareerIndexStateRemediationPlanRow::ACTION_DEFER_GOVERNED_NON_PUBLIC,
                CareerIndexStateRemediationPlanRow::ACTION_DEFER_UNTIL_RUNTIME_PROMOTION,
            ], true)
        ));
    }

    /**
     * @return array<string, int>
     */
    public function byAction(): array
    {
        $counts = [];
        foreach ($this->rows as $row) {
            $counts[$row->action] = ($counts[$row->action] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    public function byExpectation(): array
    {
        $counts = [];
        foreach ($this->rows as $row) {
            $counts[$row->expectation] = ($counts[$row->expectation] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @return array{schema_version: string, summary: array<string, mixed>, rows: list<array<string, mixed>>, sidecars: list<array<string, mixed>>, approval_gates: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'summary' => [
                'expected_count' => count($this->rows),
                'create_index_state_count' => $this->createIndexStateCount(),
                'review_existing_index_state_count' => $this->reviewExistingIndexStateCount(),
                'deferred_count' => $this->deferredCount(),
                'approval_required_count' => count(array_filter(
                    $this->rows,
                    static fn (CareerIndexStateRemediationPlanRow $row): bool => $row->approvalRequired
                )),
                'by_action' => $this->byAction(),
                'by_expectation' => $this->byExpectation(),
            ],
            'rows' => array_map(
                static fn (CareerIndexStateRemediationPlanRow $row): array => $row->toArray(),
                $this->rows
            ),
            'sidecars' => array_map(
                static fn (CareerCanonicalEligibilitySidecar $sidecar): array => $sidecar->toArray(),
                $this->sidecars
            ),
            'approval_gates' => array_map(
                static fn (CareerCanonicalEligibilityAuditRunContextApprovalGate $gate): array => $gate->toArray(),
                $this->approvalGates
            ),
        ];
    }

    /**
     * @param  list<CareerIndexStateRemediationPlanRow>  $rows
     * @return list<CareerCanonicalEligibilityAuditRunContextApprovalGate>
     */
    private static function approvalGatesFor(array $rows): array
    {
        foreach ($rows as $row) {
            if ($row->approvalRequired) {
                return [
                    new CareerCanonicalEligibilityAuditRunContextApprovalGate(
                        gateId: 'production_index_state_remediation_apply',
                        title: 'Production index_state remediation apply requires explicit approval.',
                        required: true,
                        reason: 'The remediation plan identifies index_state rows that may require production DB writes; this PR only plans the work.',
                        approvalPhraseTemplate: 'I explicitly approve production index_state remediation apply for Career 2786 using reviewed plan <PLAN_PATH>.',
                        allowedAction: 'Apply reviewed index_state remediation plan only.',
                        forbiddenActions: [
                            'deploy',
                            'rollout',
                            'backfill outside reviewed plan',
                            'quarantine',
                            'rollback',
                            'publish occupations',
                        ],
                        preconditions: [
                            'Reviewed remediation plan artifact exists.',
                            'Plan was generated from approved read-only Career 2786 context.',
                            'Production DB mutation approval is explicit and current.',
                        ]
                    ),
                ];
            }
        }

        return [];
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career index-state remediation plan requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  list<CareerIndexStateRemediationPlanRow>  $rows
     */
    private static function assertRows(array $rows): void
    {
        if (! array_is_list($rows)) {
            throw new InvalidArgumentException('Career index-state remediation rows must be a list.');
        }

        foreach ($rows as $row) {
            if (! $row instanceof CareerIndexStateRemediationPlanRow) {
                throw new InvalidArgumentException('Career index-state remediation rows must contain row DTOs.');
            }
        }
    }

    /**
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    private static function assertSidecars(array $sidecars): void
    {
        if (! array_is_list($sidecars)) {
            throw new InvalidArgumentException('Career index-state remediation sidecars must be a list.');
        }

        foreach ($sidecars as $sidecar) {
            if (! $sidecar instanceof CareerCanonicalEligibilitySidecar) {
                throw new InvalidArgumentException('Career index-state remediation sidecars must contain sidecar DTOs.');
            }
        }
    }

    /**
     * @param  list<CareerCanonicalEligibilityAuditRunContextApprovalGate>  $approvalGates
     */
    private static function assertApprovalGates(array $approvalGates): void
    {
        if (! array_is_list($approvalGates)) {
            throw new InvalidArgumentException('Career index-state remediation approval gates must be a list.');
        }

        foreach ($approvalGates as $approvalGate) {
            if (! $approvalGate instanceof CareerCanonicalEligibilityAuditRunContextApprovalGate) {
                throw new InvalidArgumentException('Career index-state remediation approval gates must contain gate DTOs.');
            }
        }
    }
}
