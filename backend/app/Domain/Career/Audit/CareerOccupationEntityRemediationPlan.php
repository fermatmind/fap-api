<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerOccupationEntityRemediationPlan
{
    public const SCHEMA_VERSION = 'career_occupation_entity_remediation_plan.v1';

    /**
     * @param  list<CareerOccupationEntityRemediationPlanRow>  $rows
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
     * @param  list<CareerOccupationEntityRemediationPlanRow>  $rows
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

    public function createOccupationCount(): int
    {
        return count(array_filter(
            $this->rows,
            static fn (CareerOccupationEntityRemediationPlanRow $row): bool => $row->action === CareerOccupationEntityRemediationPlanRow::ACTION_CREATE_OCCUPATION
        ));
    }

    public function repairEntityFieldsCount(): int
    {
        return count(array_filter(
            $this->rows,
            static fn (CareerOccupationEntityRemediationPlanRow $row): bool => $row->action === CareerOccupationEntityRemediationPlanRow::ACTION_REPAIR_ENTITY_FIELDS
        ));
    }

    public function reviewCount(): int
    {
        return count(array_filter(
            $this->rows,
            static fn (CareerOccupationEntityRemediationPlanRow $row): bool => in_array($row->action, [
                CareerOccupationEntityRemediationPlanRow::ACTION_REVIEW_DUPLICATE_ENTITY,
                CareerOccupationEntityRemediationPlanRow::ACTION_REVIEW_DUPLICATE_INPUT,
                CareerOccupationEntityRemediationPlanRow::ACTION_REVIEW_MISSING_SOURCE,
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
    public function bySourceStatus(): array
    {
        $counts = [];
        foreach ($this->rows as $row) {
            $counts[$row->sourceStatus] = ($counts[$row->sourceStatus] ?? 0) + 1;
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
                'create_occupation_count' => $this->createOccupationCount(),
                'repair_entity_fields_count' => $this->repairEntityFieldsCount(),
                'review_count' => $this->reviewCount(),
                'approval_required_count' => count(array_filter(
                    $this->rows,
                    static fn (CareerOccupationEntityRemediationPlanRow $row): bool => $row->approvalRequired
                )),
                'by_action' => $this->byAction(),
                'by_source_status' => $this->bySourceStatus(),
            ],
            'rows' => array_map(
                static fn (CareerOccupationEntityRemediationPlanRow $row): array => $row->toArray(),
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
     * @param  list<CareerOccupationEntityRemediationPlanRow>  $rows
     * @return list<CareerCanonicalEligibilityAuditRunContextApprovalGate>
     */
    private static function approvalGatesFor(array $rows): array
    {
        foreach ($rows as $row) {
            if ($row->approvalRequired) {
                return [
                    new CareerCanonicalEligibilityAuditRunContextApprovalGate(
                        gateId: 'production_occupation_entity_remediation_apply',
                        title: 'Production occupation entity remediation apply requires explicit approval.',
                        required: true,
                        reason: 'The remediation plan identifies occupation entities or entity fields that may require production DB writes; this PR only plans the work.',
                        approvalPhraseTemplate: 'I explicitly approve production occupation entity remediation apply for Career 2786 using reviewed plan <PLAN_PATH>.',
                        allowedAction: 'Apply reviewed occupation entity remediation plan only.',
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
            throw new InvalidArgumentException(sprintf('Career occupation entity remediation plan requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  list<CareerOccupationEntityRemediationPlanRow>  $rows
     */
    private static function assertRows(array $rows): void
    {
        if (! array_is_list($rows)) {
            throw new InvalidArgumentException('Career occupation entity remediation rows must be a list.');
        }

        foreach ($rows as $row) {
            if (! $row instanceof CareerOccupationEntityRemediationPlanRow) {
                throw new InvalidArgumentException('Career occupation entity remediation rows must contain row DTOs.');
            }
        }
    }

    /**
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    private static function assertSidecars(array $sidecars): void
    {
        if (! array_is_list($sidecars)) {
            throw new InvalidArgumentException('Career occupation entity remediation sidecars must be a list.');
        }

        foreach ($sidecars as $sidecar) {
            if (! $sidecar instanceof CareerCanonicalEligibilitySidecar) {
                throw new InvalidArgumentException('Career occupation entity remediation sidecars must contain sidecar DTOs.');
            }
        }
    }

    /**
     * @param  list<CareerCanonicalEligibilityAuditRunContextApprovalGate>  $approvalGates
     */
    private static function assertApprovalGates(array $approvalGates): void
    {
        if (! array_is_list($approvalGates)) {
            throw new InvalidArgumentException('Career occupation entity remediation approval gates must be a list.');
        }

        foreach ($approvalGates as $approvalGate) {
            if (! $approvalGate instanceof CareerCanonicalEligibilityAuditRunContextApprovalGate) {
                throw new InvalidArgumentException('Career occupation entity remediation approval gates must contain gate DTOs.');
            }
        }
    }
}
