<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerCanonicalEligibilityAuditRunContextApprovalGate
{
    /**
     * @param  list<string>  $forbiddenActions
     * @param  list<string>  $preconditions
     */
    public function __construct(
        public readonly string $gateId,
        public readonly string $title,
        public readonly bool $required,
        public readonly string $reason,
        public readonly string $approvalPhraseTemplate,
        public readonly string $allowedAction,
        public readonly array $forbiddenActions,
        public readonly array $preconditions,
    ) {
        self::assertNonEmptyString($this->gateId, 'gate_id');
        self::assertNonEmptyString($this->title, 'title');
        self::assertNonEmptyString($this->reason, 'reason');
        self::assertNonEmptyString($this->approvalPhraseTemplate, 'approval_phrase_template');
        self::assertNonEmptyString($this->allowedAction, 'allowed_action');
        self::assertListOfStrings($this->forbiddenActions, 'forbidden_actions');
        self::assertListOfStrings($this->preconditions, 'preconditions');
    }

    /**
     * @return array{gate_id: string, title: string, required: bool, reason: string, approval_phrase_template: string, allowed_action: string, forbidden_actions: list<string>, preconditions: list<string>}
     */
    public function toArray(): array
    {
        return [
            'gate_id' => $this->gateId,
            'title' => $this->title,
            'required' => $this->required,
            'reason' => $this->reason,
            'approval_phrase_template' => $this->approvalPhraseTemplate,
            'allowed_action' => $this->allowedAction,
            'forbidden_actions' => $this->forbiddenActions,
            'preconditions' => $this->preconditions,
        ];
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career audit run context approval gate requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  list<string>  $values
     */
    private static function assertListOfStrings(array $values, string $key): void
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException(sprintf('Career audit run context approval gate [%s] must be a list.', $key));
        }

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException(sprintf('Career audit run context approval gate [%s] must contain non-empty strings.', $key));
            }
        }
    }
}
