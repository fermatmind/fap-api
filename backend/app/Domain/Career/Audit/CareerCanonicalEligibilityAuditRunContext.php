<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerCanonicalEligibilityAuditRunContext
{
    /**
     * @param  array<string, mixed>  $planner
     * @param  array<string, mixed>  $entity
     * @param  array<string, mixed>  $index
     * @param  array<string, mixed>  $runtime
     * @param  array<string, mixed>  $surface
     * @param  array<string, mixed>  $staticSources
     * @param  list<CareerCanonicalEligibilityAuditRunContextRequirement>  $requirements
     * @param  list<CareerCanonicalEligibilityAuditRunContextApprovalGate>  $approvalGates
     * @param  list<string>  $suggestedRerunModes
     */
    public function __construct(
        public readonly array $planner,
        public readonly array $entity,
        public readonly array $index,
        public readonly array $runtime,
        public readonly array $surface,
        public readonly array $staticSources,
        public readonly array $requirements,
        public readonly array $approvalGates,
        public readonly array $suggestedRerunModes,
    ) {
        self::assertMap($this->planner, 'planner');
        self::assertMap($this->entity, 'entity');
        self::assertMap($this->index, 'index');
        self::assertMap($this->runtime, 'runtime');
        self::assertMap($this->surface, 'surface');
        self::assertMap($this->staticSources, 'static_sources');
        self::assertRequirements($this->requirements);
        self::assertApprovalGates($this->approvalGates);
        self::assertListOfStrings($this->suggestedRerunModes, 'suggested_rerun_modes');
    }

    /**
     * @return array{planner: array<string, mixed>, entity: array<string, mixed>, index: array<string, mixed>, runtime: array<string, mixed>, surface: array<string, mixed>, static_sources: array<string, mixed>, missing_contexts: list<array<string, mixed>>, unverified_contexts: list<array<string, mixed>>, approval_gates: list<array<string, mixed>>, next_required_inputs: list<array<string, mixed>>, suggested_rerun_modes: list<string>}
     */
    public function toArray(): array
    {
        $requirements = array_map(
            static fn (CareerCanonicalEligibilityAuditRunContextRequirement $requirement): array => $requirement->toArray(),
            $this->requirements
        );

        return [
            'planner' => $this->planner,
            'entity' => $this->entity,
            'index' => $this->index,
            'runtime' => $this->runtime,
            'surface' => $this->surface,
            'static_sources' => $this->staticSources,
            'missing_contexts' => array_values(array_filter(
                $requirements,
                static fn (array $requirement): bool => in_array($requirement['status'], [
                    CareerCanonicalEligibilityAuditRunContextStatus::MISSING,
                    CareerCanonicalEligibilityAuditRunContextStatus::REQUIRES_APPROVAL,
                ], true)
            )),
            'unverified_contexts' => array_values(array_filter(
                $requirements,
                static fn (array $requirement): bool => $requirement['status'] !== CareerCanonicalEligibilityAuditRunContextStatus::SUPPLIED
            )),
            'approval_gates' => array_map(
                static fn (CareerCanonicalEligibilityAuditRunContextApprovalGate $gate): array => $gate->toArray(),
                $this->approvalGates
            ),
            'next_required_inputs' => array_values(array_filter(
                $requirements,
                static fn (array $requirement): bool => $requirement['required_for_meaningful_rerun'] === true
                    && $requirement['status'] !== CareerCanonicalEligibilityAuditRunContextStatus::SUPPLIED
            )),
            'suggested_rerun_modes' => $this->suggestedRerunModes,
        ];
    }

    /**
     * @return array{planner_supplied: bool, entity_db_context: string, index_state_context: string, runtime_projection_context: string, runtime_truth_context: string, surface_context: string, live_html_context: string, required_next_action: string}
     */
    public function summary(): array
    {
        $requirements = $this->requirementsById();

        return [
            'planner_supplied' => ($this->planner['status'] ?? null) === CareerCanonicalEligibilityAuditRunContextStatus::SUPPLIED,
            'entity_db_context' => $requirements['entity_db_context']->status ?? CareerCanonicalEligibilityAuditRunContextStatus::MISSING,
            'index_state_context' => $requirements['index_state_context']->status ?? CareerCanonicalEligibilityAuditRunContextStatus::MISSING,
            'runtime_projection_context' => $requirements['runtime_projection_context']->status ?? CareerCanonicalEligibilityAuditRunContextStatus::MISSING,
            'runtime_truth_context' => $requirements['runtime_truth_context']->status ?? CareerCanonicalEligibilityAuditRunContextStatus::MISSING,
            'surface_context' => $requirements['surface_context']->status ?? CareerCanonicalEligibilityAuditRunContextStatus::MISSING,
            'live_html_context' => $requirements['live_html_context']->status ?? CareerCanonicalEligibilityAuditRunContextStatus::NOT_REQUESTED,
            'required_next_action' => $this->requiredNextAction(),
        ];
    }

    private function requiredNextAction(): string
    {
        foreach ($this->requirements as $requirement) {
            if ($requirement->requiredForMeaningfulRerun && $requirement->status !== CareerCanonicalEligibilityAuditRunContextStatus::SUPPLIED) {
                return 'provide_read_only_context_bundle';
            }
        }

        return 'review_layer_blockers';
    }

    /**
     * @return array<string, CareerCanonicalEligibilityAuditRunContextRequirement>
     */
    private function requirementsById(): array
    {
        $requirements = [];
        foreach ($this->requirements as $requirement) {
            $requirements[$requirement->contextId] = $requirement;
        }

        return $requirements;
    }

    private static function assertMap(array $value, string $key): void
    {
        if (array_is_list($value) && $value !== []) {
            throw new InvalidArgumentException(sprintf('Career audit run context [%s] must be an object map.', $key));
        }
    }

    /**
     * @param  list<CareerCanonicalEligibilityAuditRunContextRequirement>  $requirements
     */
    private static function assertRequirements(array $requirements): void
    {
        if (! array_is_list($requirements)) {
            throw new InvalidArgumentException('Career audit run context requirements must be a list.');
        }

        foreach ($requirements as $requirement) {
            if (! $requirement instanceof CareerCanonicalEligibilityAuditRunContextRequirement) {
                throw new InvalidArgumentException('Career audit run context requirements must contain requirement DTOs.');
            }
        }
    }

    /**
     * @param  list<CareerCanonicalEligibilityAuditRunContextApprovalGate>  $approvalGates
     */
    private static function assertApprovalGates(array $approvalGates): void
    {
        if (! array_is_list($approvalGates)) {
            throw new InvalidArgumentException('Career audit run context approval gates must be a list.');
        }

        foreach ($approvalGates as $gate) {
            if (! $gate instanceof CareerCanonicalEligibilityAuditRunContextApprovalGate) {
                throw new InvalidArgumentException('Career audit run context approval gates must contain approval gate DTOs.');
            }
        }
    }

    /**
     * @param  list<string>  $values
     */
    private static function assertListOfStrings(array $values, string $key): void
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException(sprintf('Career audit run context [%s] must be a list.', $key));
        }

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException(sprintf('Career audit run context [%s] must contain non-empty strings.', $key));
            }
        }
    }
}
