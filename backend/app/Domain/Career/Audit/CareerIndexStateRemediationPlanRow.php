<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerIndexStateRemediationPlanRow
{
    public const EXPECTATION_EXPECTED_INDEXED = 'expected_indexed';

    public const EXPECTATION_GOVERNED_NON_PUBLIC = 'governed_non_public';

    public const EXPECTATION_NOT_YET_PROMOTED = 'not_yet_promoted';

    public const ACTION_NONE = 'none';

    public const ACTION_CREATE_INDEX_STATE = 'create_index_state';

    public const ACTION_REVIEW_EXISTING_INDEX_STATE = 'review_existing_index_state';

    public const ACTION_DEFER_GOVERNED_NON_PUBLIC = 'defer_governed_non_public';

    public const ACTION_DEFER_UNTIL_RUNTIME_PROMOTION = 'defer_until_runtime_promotion';

    /**
     * @param  list<string>  $reasons
     * @param  list<mixed>  $evidence
     */
    public function __construct(
        public readonly string $canonicalSlug,
        public readonly string $expectation,
        public readonly string $action,
        public readonly bool $approvalRequired,
        public readonly ?string $occupationId,
        public readonly ?string $indexStateId,
        public readonly ?string $rawIndexState,
        public readonly ?string $publicIndexState,
        public readonly bool $indexEligible,
        public readonly array $reasons = [],
        public readonly array $evidence = [],
    ) {
        self::assertNonEmptyString($this->canonicalSlug, 'canonical_slug');
        self::assertValidExpectation($this->expectation);
        self::assertValidAction($this->action);
        self::assertListOfStrings($this->reasons, 'reasons');
        self::assertList($this->evidence, 'evidence');

        foreach ([
            'occupation_id' => $this->occupationId,
            'index_state_id' => $this->indexStateId,
            'raw_index_state' => $this->rawIndexState,
            'public_index_state' => $this->publicIndexState,
        ] as $key => $value) {
            if ($value !== null) {
                self::assertNonEmptyString($value, $key);
            }
        }
    }

    /**
     * @return array{canonical_slug: string, expectation: string, action: string, approval_required: bool, occupation_id: string|null, index_state_id: string|null, raw_index_state: string|null, public_index_state: string|null, index_eligible: bool, reasons: list<string>, evidence: list<mixed>}
     */
    public function toArray(): array
    {
        return [
            'canonical_slug' => $this->canonicalSlug,
            'expectation' => $this->expectation,
            'action' => $this->action,
            'approval_required' => $this->approvalRequired,
            'occupation_id' => $this->occupationId,
            'index_state_id' => $this->indexStateId,
            'raw_index_state' => $this->rawIndexState,
            'public_index_state' => $this->publicIndexState,
            'index_eligible' => $this->indexEligible,
            'reasons' => $this->reasons,
            'evidence' => $this->evidence,
        ];
    }

    /**
     * @return list<string>
     */
    public static function expectations(): array
    {
        return [
            self::EXPECTATION_EXPECTED_INDEXED,
            self::EXPECTATION_GOVERNED_NON_PUBLIC,
            self::EXPECTATION_NOT_YET_PROMOTED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function actions(): array
    {
        return [
            self::ACTION_NONE,
            self::ACTION_CREATE_INDEX_STATE,
            self::ACTION_REVIEW_EXISTING_INDEX_STATE,
            self::ACTION_DEFER_GOVERNED_NON_PUBLIC,
            self::ACTION_DEFER_UNTIL_RUNTIME_PROMOTION,
        ];
    }

    private static function assertValidExpectation(string $value): void
    {
        if (! in_array($value, self::expectations(), true)) {
            throw new InvalidArgumentException(sprintf('Invalid career index-state remediation expectation [%s].', $value));
        }
    }

    private static function assertValidAction(string $value): void
    {
        if (! in_array($value, self::actions(), true)) {
            throw new InvalidArgumentException(sprintf('Invalid career index-state remediation action [%s].', $value));
        }
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career index-state remediation row requires non-empty [%s].', $key));
        }
    }

    private static function assertList(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career index-state remediation row [%s] must be a list.', $key));
        }
    }

    /**
     * @param  list<string>  $value
     */
    private static function assertListOfStrings(array $value, string $key): void
    {
        self::assertList($value, $key);

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException(sprintf('Career index-state remediation row [%s] must contain non-empty strings.', $key));
            }
        }
    }
}
