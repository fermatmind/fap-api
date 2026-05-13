<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class Career2786ReadinessPolicyIssue
{
    public const HARD_BLOCKER = 'hard_blocker';

    public const REMEDIATION_REQUIRED = 'remediation_required';

    public const EXPECTED_NOT_READY = 'expected_not_ready';

    public const DEFERRED_UNTIL_CANDIDATE = 'deferred_until_candidate';

    public const APPROVAL_GATED = 'approval_gated';

    public const NEAR_ELIGIBLE = 'near_eligible';

    public const ELIGIBLE_CANDIDATE = 'eligible_candidate';

    public function __construct(
        public readonly string $reason,
        public readonly string $classification,
        public readonly string $layer,
        public readonly bool $requiresApproval,
        public readonly bool $blocks80Readiness,
        public readonly string $message,
    ) {
        self::assertNonEmptyString($this->reason, 'reason');
        self::assertValidClassification($this->classification);
        self::assertNonEmptyString($this->layer, 'layer');
        self::assertNonEmptyString($this->message, 'message');
    }

    /**
     * @return array{reason: string, classification: string, layer: string, requires_approval: bool, blocks_80_readiness: bool, message: string}
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason,
            'classification' => $this->classification,
            'layer' => $this->layer,
            'requires_approval' => $this->requiresApproval,
            'blocks_80_readiness' => $this->blocks80Readiness,
            'message' => $this->message,
        ];
    }

    /**
     * @return list<string>
     */
    public static function classifications(): array
    {
        return [
            self::HARD_BLOCKER,
            self::REMEDIATION_REQUIRED,
            self::EXPECTED_NOT_READY,
            self::DEFERRED_UNTIL_CANDIDATE,
            self::APPROVAL_GATED,
            self::NEAR_ELIGIBLE,
            self::ELIGIBLE_CANDIDATE,
        ];
    }

    public static function assertValidClassification(string $value): void
    {
        if (! in_array($value, self::classifications(), true)) {
            throw new InvalidArgumentException(sprintf('Invalid Career 2786 readiness policy classification [%s].', $value));
        }
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career 2786 readiness policy issue requires non-empty [%s].', $key));
        }
    }
}
