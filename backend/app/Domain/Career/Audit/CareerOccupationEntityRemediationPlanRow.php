<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerOccupationEntityRemediationPlanRow
{
    public const SOURCE_AVAILABLE = 'planner_source_available';

    public const SOURCE_MISSING = 'planner_source_missing';

    public const ACTION_NONE = 'none';

    public const ACTION_CREATE_OCCUPATION = 'create_occupation';

    public const ACTION_REPAIR_ENTITY_FIELDS = 'repair_entity_fields';

    public const ACTION_REVIEW_DUPLICATE_ENTITY = 'review_duplicate_entity';

    public const ACTION_REVIEW_DUPLICATE_INPUT = 'review_duplicate_input';

    public const ACTION_REVIEW_MISSING_SOURCE = 'review_missing_source';

    /**
     * @param  list<string>  $missingEntityFields
     * @param  list<string>  $reasons
     * @param  list<mixed>  $evidence
     */
    public function __construct(
        public readonly string $canonicalSlug,
        public readonly string $sourceStatus,
        public readonly string $action,
        public readonly bool $approvalRequired,
        public readonly bool $occupationExists,
        public readonly ?string $occupationId,
        public readonly array $missingEntityFields = [],
        public readonly ?string $plannerTitleEn = null,
        public readonly ?string $plannerTitleZh = null,
        public readonly ?string $plannerFamily = null,
        public readonly ?string $plannerSourceCode = null,
        public readonly array $reasons = [],
        public readonly array $evidence = [],
    ) {
        self::assertNonEmptyString($this->canonicalSlug, 'canonical_slug');
        self::assertValidSourceStatus($this->sourceStatus);
        self::assertValidAction($this->action);
        self::assertListOfStrings($this->missingEntityFields, 'missing_entity_fields');
        self::assertListOfStrings($this->reasons, 'reasons');
        self::assertList($this->evidence, 'evidence');

        foreach ([
            'occupation_id' => $this->occupationId,
            'planner_title_en' => $this->plannerTitleEn,
            'planner_title_zh' => $this->plannerTitleZh,
            'planner_family' => $this->plannerFamily,
            'planner_source_code' => $this->plannerSourceCode,
        ] as $key => $value) {
            if ($value !== null) {
                self::assertNonEmptyString($value, $key);
            }
        }
    }

    /**
     * @return array{canonical_slug: string, source_status: string, action: string, approval_required: bool, occupation_exists: bool, occupation_id: string|null, missing_entity_fields: list<string>, planner_title_en: string|null, planner_title_zh: string|null, planner_family: string|null, planner_source_code: string|null, reasons: list<string>, evidence: list<mixed>}
     */
    public function toArray(): array
    {
        return [
            'canonical_slug' => $this->canonicalSlug,
            'source_status' => $this->sourceStatus,
            'action' => $this->action,
            'approval_required' => $this->approvalRequired,
            'occupation_exists' => $this->occupationExists,
            'occupation_id' => $this->occupationId,
            'missing_entity_fields' => $this->missingEntityFields,
            'planner_title_en' => $this->plannerTitleEn,
            'planner_title_zh' => $this->plannerTitleZh,
            'planner_family' => $this->plannerFamily,
            'planner_source_code' => $this->plannerSourceCode,
            'reasons' => $this->reasons,
            'evidence' => $this->evidence,
        ];
    }

    /**
     * @return list<string>
     */
    public static function sourceStatuses(): array
    {
        return [
            self::SOURCE_AVAILABLE,
            self::SOURCE_MISSING,
        ];
    }

    /**
     * @return list<string>
     */
    public static function actions(): array
    {
        return [
            self::ACTION_NONE,
            self::ACTION_CREATE_OCCUPATION,
            self::ACTION_REPAIR_ENTITY_FIELDS,
            self::ACTION_REVIEW_DUPLICATE_ENTITY,
            self::ACTION_REVIEW_DUPLICATE_INPUT,
            self::ACTION_REVIEW_MISSING_SOURCE,
        ];
    }

    private static function assertValidSourceStatus(string $value): void
    {
        if (! in_array($value, self::sourceStatuses(), true)) {
            throw new InvalidArgumentException(sprintf('Invalid career occupation entity remediation source status [%s].', $value));
        }
    }

    private static function assertValidAction(string $value): void
    {
        if (! in_array($value, self::actions(), true)) {
            throw new InvalidArgumentException(sprintf('Invalid career occupation entity remediation action [%s].', $value));
        }
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career occupation entity remediation row requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  list<mixed>  $value
     */
    private static function assertList(array $value, string $key): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career occupation entity remediation row [%s] must be a list.', $key));
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
                throw new InvalidArgumentException(sprintf('Career occupation entity remediation row [%s] must contain non-empty strings.', $key));
            }
        }
    }
}
