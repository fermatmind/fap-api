<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use App\Models\Occupation;
use Illuminate\Database\Eloquent\Model;
use Throwable;

final class CareerOccupationEntityInventoryAuditor
{
    /**
     * Entity-level fields required to form future canonical eligibility rows.
     *
     * @var list<string>
     */
    private const REQUIRED_ENTITY_FIELDS = [
        'id',
        'canonical_slug',
        'family_id',
        'entity_level',
        'truth_market',
        'display_market',
        'crosswalk_mode',
        'canonical_title_en',
        'canonical_title_zh',
        'search_h1_zh',
    ];

    /**
     * @param  class-string<Occupation>  $occupationModel
     */
    public function __construct(
        private readonly string $occupationModel = Occupation::class,
    ) {}

    public function auditPlan(CareerPublicResolutionPlan $plan): CareerOccupationEntityInventoryResult
    {
        return $this->auditSlugs(
            array_map(
                static fn (CareerPublicResolutionPlanRow $row): ?string => $row->canonicalSlug,
                $plan->rows
            ),
            'plan'
        );
    }

    /**
     * @param  list<string|null>  $slugs
     */
    public function auditSlugs(array $slugs, string $sourceScope = 'slugs'): CareerOccupationEntityInventoryResult
    {
        [$uniqueSlugs, $duplicateInputSlugs, $issues] = $this->normalizeInputSlugs($slugs);

        if ($uniqueSlugs === []) {
            return CareerOccupationEntityInventoryResult::build([], $issues, count($duplicateInputSlugs));
        }

        try {
            $occupationsBySlug = $this->occupationsBySlug($uniqueSlugs);
        } catch (Throwable $exception) {
            $issue = new CareerOccupationEntityInventoryIssue(
                reason: CareerOccupationEntityInventoryIssue::OCCUPATION_QUERY_FAILED,
                message: 'Occupation entity inventory query failed.',
                severity: CareerCanonicalEligibilitySeverity::HIGH,
                evidence: [[
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]]
            );

            return CareerOccupationEntityInventoryResult::build(
                rows: array_map(
                    fn (string $slug): CareerOccupationEntityInventoryRow => $this->rowForQueryFailure($slug, $sourceScope, $issue),
                    $uniqueSlugs
                ),
                issues: [...$issues, $issue],
                duplicateInputCount: count($duplicateInputSlugs)
            );
        }

        $rows = [];
        foreach ($uniqueSlugs as $slug) {
            $rows[] = $this->buildRow(
                canonicalSlug: $slug,
                sourceScope: $sourceScope,
                duplicateInputSlug: array_key_exists($slug, $duplicateInputSlugs),
                occupations: $occupationsBySlug[$slug] ?? []
            );
        }

        return CareerOccupationEntityInventoryResult::build(
            rows: $rows,
            issues: [
                ...$issues,
                ...array_values(array_merge(...array_map(
                    static fn (CareerOccupationEntityInventoryRow $row): array => $row->issues,
                    $rows
                ))),
            ],
            duplicateInputCount: count($duplicateInputSlugs)
        );
    }

    /**
     * @param  list<string|null>  $slugs
     * @return array{0: list<string>, 1: array<string, true>, 2: list<CareerOccupationEntityInventoryIssue>}
     */
    private function normalizeInputSlugs(array $slugs): array
    {
        $issues = [];
        $uniqueSlugs = [];
        $seen = [];
        $duplicateInputSlugs = [];

        if ($slugs === []) {
            $issues[] = new CareerOccupationEntityInventoryIssue(
                reason: CareerOccupationEntityInventoryIssue::INPUT_SLUG_MISSING,
                message: 'At least one canonical slug is required for occupation entity inventory.',
                severity: CareerCanonicalEligibilitySeverity::HIGH,
                evidence: [['input_count' => 0]]
            );
        }

        foreach ($slugs as $index => $slug) {
            $canonicalSlug = $this->normalizeSlug($slug);

            if ($canonicalSlug === null) {
                $issues[] = new CareerOccupationEntityInventoryIssue(
                    reason: CareerOccupationEntityInventoryIssue::INPUT_SLUG_MISSING,
                    message: 'Input canonical slug is missing.',
                    severity: CareerCanonicalEligibilitySeverity::HIGH,
                    evidence: [['input_index' => $index]]
                );

                continue;
            }

            if (isset($seen[$canonicalSlug])) {
                $duplicateInputSlugs[$canonicalSlug] = true;

                continue;
            }

            $seen[$canonicalSlug] = true;
            $uniqueSlugs[] = $canonicalSlug;
        }

        return [$uniqueSlugs, $duplicateInputSlugs, $issues];
    }

    /**
     * @param  list<string>  $canonicalSlugs
     * @return array<string, list<Model>>
     */
    private function occupationsBySlug(array $canonicalSlugs): array
    {
        $model = $this->occupationModel;
        $fields = self::REQUIRED_ENTITY_FIELDS;

        /** @var iterable<Model> $occupations */
        $occupations = $model::query()
            ->whereIn('canonical_slug', $canonicalSlugs)
            ->get($fields);

        $bySlug = [];
        foreach ($occupations as $occupation) {
            $canonicalSlug = $this->normalizeSlug($occupation->getAttribute('canonical_slug'));
            if ($canonicalSlug === null) {
                continue;
            }

            $bySlug[$canonicalSlug] ??= [];
            $bySlug[$canonicalSlug][] = $occupation;
        }

        return $bySlug;
    }

    /**
     * @param  list<Model>  $occupations
     */
    private function buildRow(
        string $canonicalSlug,
        string $sourceScope,
        bool $duplicateInputSlug,
        array $occupations,
    ): CareerOccupationEntityInventoryRow {
        $issues = [];

        if ($duplicateInputSlug) {
            $issues[] = new CareerOccupationEntityInventoryIssue(
                reason: CareerOccupationEntityInventoryIssue::CANONICAL_SLUG_DUPLICATE_IN_INPUT,
                message: 'Input canonical slug appears more than once.',
                severity: CareerCanonicalEligibilitySeverity::HIGH,
                canonicalSlug: $canonicalSlug,
                evidence: [['canonical_slug' => $canonicalSlug]]
            );
        }

        if ($occupations === []) {
            $issues[] = new CareerOccupationEntityInventoryIssue(
                reason: CareerOccupationEntityInventoryIssue::OCCUPATION_MISSING,
                message: 'Occupation entity was not found for canonical slug.',
                severity: CareerCanonicalEligibilitySeverity::BLOCKER_FOR_FULL_2786_CLAIM,
                canonicalSlug: $canonicalSlug,
                evidence: [['canonical_slug' => $canonicalSlug]]
            );

            return $this->inventoryRow(
                canonicalSlug: $canonicalSlug,
                occupation: null,
                sourceScope: $sourceScope,
                duplicateInputSlug: $duplicateInputSlug,
                duplicateEntitySlug: false,
                missingEntityFields: [],
                issues: $issues
            );
        }

        $duplicateEntitySlug = count($occupations) > 1;
        if ($duplicateEntitySlug) {
            $issues[] = new CareerOccupationEntityInventoryIssue(
                reason: CareerOccupationEntityInventoryIssue::CANONICAL_SLUG_DUPLICATE_IN_ENTITIES,
                message: 'Occupation canonical slug matched more than one entity row.',
                severity: CareerCanonicalEligibilitySeverity::BLOCKER_FOR_FULL_2786_CLAIM,
                canonicalSlug: $canonicalSlug,
                evidence: [['entity_rows' => count($occupations)]]
            );
        }

        $occupation = $occupations[0];
        $missingEntityFields = $this->missingEntityFields($occupation);
        foreach ($missingEntityFields as $field) {
            $issues[] = new CareerOccupationEntityInventoryIssue(
                reason: CareerOccupationEntityInventoryIssue::ENTITY_FIELD_MISSING,
                message: 'Occupation entity is missing a required entity-level field.',
                severity: CareerCanonicalEligibilitySeverity::HIGH,
                canonicalSlug: $canonicalSlug,
                field: $field,
                evidence: [['field' => $field]]
            );
        }

        return $this->inventoryRow(
            canonicalSlug: $canonicalSlug,
            occupation: $occupation,
            sourceScope: $sourceScope,
            duplicateInputSlug: $duplicateInputSlug,
            duplicateEntitySlug: $duplicateEntitySlug,
            missingEntityFields: $missingEntityFields,
            issues: $issues
        );
    }

    /**
     * @param  list<string>  $missingEntityFields
     * @param  list<CareerOccupationEntityInventoryIssue>  $issues
     */
    private function inventoryRow(
        string $canonicalSlug,
        ?Model $occupation,
        string $sourceScope,
        bool $duplicateInputSlug,
        bool $duplicateEntitySlug,
        array $missingEntityFields,
        array $issues,
    ): CareerOccupationEntityInventoryRow {
        $evidence = $occupation === null
            ? [['canonical_slug' => $canonicalSlug]]
            : [['occupation_id' => (string) $occupation->getAttribute('id')]];

        if ($missingEntityFields !== []) {
            $evidence[] = ['missing_entity_fields' => $missingEntityFields];
        }

        if ($duplicateEntitySlug) {
            $evidence[] = ['duplicate_entity_slug' => true];
        }

        $reasons = array_values(array_unique(array_map(
            static fn (CareerOccupationEntityInventoryIssue $issue): string => $issue->reason,
            $issues
        )));

        return new CareerOccupationEntityInventoryRow(
            canonicalSlug: $canonicalSlug,
            occupationExists: $occupation !== null,
            occupationId: $occupation === null ? null : (string) $occupation->getAttribute('id'),
            duplicateInputSlug: $duplicateInputSlug,
            duplicateEntitySlug: $duplicateEntitySlug,
            entityStatus: new CareerCanonicalEligibilityLayerStatus(
                layer: CareerCanonicalEligibilityLayer::ENTITY,
                status: $this->layerStatusForRow($occupation !== null, $duplicateInputSlug, $duplicateEntitySlug, $missingEntityFields),
                reasons: $reasons,
                evidence: $evidence,
                source: 'occupations'
            ),
            missingEntityFields: $missingEntityFields,
            sourceScope: $sourceScope,
            evidence: $evidence,
            issues: $issues
        );
    }

    private function rowForQueryFailure(
        string $canonicalSlug,
        string $sourceScope,
        CareerOccupationEntityInventoryIssue $issue,
    ): CareerOccupationEntityInventoryRow {
        return $this->inventoryRow(
            canonicalSlug: $canonicalSlug,
            occupation: null,
            sourceScope: $sourceScope,
            duplicateInputSlug: false,
            duplicateEntitySlug: false,
            missingEntityFields: [],
            issues: [$issue]
        );
    }

    /**
     * @return list<string>
     */
    private function missingEntityFields(Model $occupation): array
    {
        $missing = [];
        foreach (self::REQUIRED_ENTITY_FIELDS as $field) {
            $value = $occupation->getAttribute($field);

            if ($value === null || (is_string($value) && trim($value) === '')) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * @param  list<string>  $missingEntityFields
     */
    private function layerStatusForRow(
        bool $occupationExists,
        bool $duplicateInputSlug,
        bool $duplicateEntitySlug,
        array $missingEntityFields,
    ): string {
        if (! $occupationExists || $duplicateEntitySlug || $missingEntityFields !== []) {
            return CareerCanonicalEligibilityStatus::BLOCKED;
        }

        return $duplicateInputSlug
            ? CareerCanonicalEligibilityStatus::WARNING
            : CareerCanonicalEligibilityStatus::PASS;
    }

    private function normalizeSlug(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }
}
