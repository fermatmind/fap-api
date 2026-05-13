<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerCanonicalEligibilityAuditRunContextRequirement
{
    /**
     * @param  list<mixed>  $evidence
     */
    public function __construct(
        public readonly string $contextId,
        public readonly string $label,
        public readonly string $status,
        public readonly bool $requiredForMeaningfulRerun,
        public readonly bool $blocks80Readiness,
        public readonly bool $requiresApproval,
        public readonly ?string $approvalGateId,
        public readonly ?string $suppliedInput,
        public readonly ?string $requiredInput,
        public readonly string $reason,
        public readonly array $evidence = [],
    ) {
        self::assertNonEmptyString($this->contextId, 'context_id');
        self::assertNonEmptyString($this->label, 'label');
        CareerCanonicalEligibilityAuditRunContextStatus::assertValid($this->status);
        self::assertNonEmptyString($this->reason, 'reason');
        self::assertOptionalNonEmptyString($this->approvalGateId, 'approval_gate_id');
        self::assertOptionalNonEmptyString($this->suppliedInput, 'supplied_input');
        self::assertOptionalNonEmptyString($this->requiredInput, 'required_input');

        if ($this->requiresApproval && $this->approvalGateId === null) {
            throw new InvalidArgumentException('Career audit run context requirement that requires approval must include approval_gate_id.');
        }

        if (! array_is_list($this->evidence)) {
            throw new InvalidArgumentException('Career audit run context requirement evidence must be a list.');
        }
    }

    /**
     * @return array{context_id: string, label: string, status: string, required_for_meaningful_rerun: bool, blocks_80_readiness: bool, requires_approval: bool, approval_gate_id: string|null, supplied_input: string|null, required_input: string|null, reason: string, evidence: list<mixed>}
     */
    public function toArray(): array
    {
        return [
            'context_id' => $this->contextId,
            'label' => $this->label,
            'status' => $this->status,
            'required_for_meaningful_rerun' => $this->requiredForMeaningfulRerun,
            'blocks_80_readiness' => $this->blocks80Readiness,
            'requires_approval' => $this->requiresApproval,
            'approval_gate_id' => $this->approvalGateId,
            'supplied_input' => $this->suppliedInput,
            'required_input' => $this->requiredInput,
            'reason' => $this->reason,
            'evidence' => $this->evidence,
        ];
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career audit run context requirement requires non-empty [%s].', $key));
        }
    }

    private static function assertOptionalNonEmptyString(?string $value, string $key): void
    {
        if ($value !== null && trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career audit run context requirement optional [%s] cannot be empty.', $key));
        }
    }
}
