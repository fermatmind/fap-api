<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Audit\CareerPublicResolutionPlanIssue;
use App\Domain\Career\Audit\CareerPublicResolutionPlanResolver;
use App\Domain\Career\IndexStateValue;
use App\Models\IndexState;
use App\Models\Occupation;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

final class CareerExportCanonicalEligibilityDbContext extends Command
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

    protected $signature = 'career:export-canonical-eligibility-db-context
        {--public-resolution-plan= : Required public-resolution planner JSON artifact}
        {--entity-output= : Optional output path for career_entity_context.v1 JSON}
        {--index-state-output= : Optional output path for career_index_state_context.v1 JSON}
        {--locales=en,zh : Optional locale list for summary context}
        {--json : Emit JSON summary output}';

    protected $description = 'Export read-only Career entity and index-state context artifacts for canonical eligibility audit reruns.';

    public function handle(): int
    {
        $planPath = $this->normalizedOption('public-resolution-plan');
        $entityOutput = $this->normalizedOption('entity-output');
        $indexOutput = $this->normalizedOption('index-state-output');

        if ($planPath === null) {
            return $this->finish([
                'status' => 'blocked',
                'read_only' => true,
                'writes_database' => false,
                'by_reason' => ['public_resolution_plan_missing' => 1],
                'issues' => [[
                    'reason' => 'public_resolution_plan_missing',
                    'message' => 'A --public-resolution-plan JSON artifact is required.',
                ]],
            ], self::FAILURE);
        }

        if ($entityOutput === null && $indexOutput === null) {
            return $this->finish([
                'status' => 'blocked',
                'read_only' => true,
                'writes_database' => false,
                'by_reason' => ['output_path_missing' => 1],
                'issues' => [[
                    'reason' => 'output_path_missing',
                    'message' => 'At least one of --entity-output or --index-state-output is required.',
                ]],
            ], self::FAILURE);
        }

        $planValidation = CareerPublicResolutionPlanResolver::fromPath($planPath);
        if ($planValidation->plan === null) {
            return $this->finish([
                'status' => 'blocked',
                'read_only' => true,
                'writes_database' => false,
                'by_reason' => $planValidation->byReason(),
                'plan_validation' => $planValidation->toArray(),
                'issues' => array_map(
                    static fn (CareerPublicResolutionPlanIssue $issue): array => $issue->toArray(),
                    $planValidation->issues
                ),
            ], self::FAILURE);
        }

        $slugs = $this->canonicalSlugs($planValidation->rows());
        $duplicateInputSlugs = $this->duplicateInputSlugs($planValidation->rows());
        $occupationsBySlug = $this->occupationsBySlug($slugs);

        $source = [
            'type' => 'read_only_db_export',
            'generated_at' => now('UTC')->toISOString(),
            'environment' => app()->environment(),
            'planner_path' => $planValidation->sourcePath,
            'planner_checksum' => $planValidation->checksum(),
            'locales' => $this->locales(),
        ];

        $summary = [
            'status' => 'materialized',
            'read_only' => true,
            'writes_database' => false,
            'public_resolution_plan' => $planValidation->sourcePath,
            'expected_slugs' => count($slugs),
            'duplicate_input_slugs' => count($duplicateInputSlugs),
            'duplicate_input_slug_values' => array_keys($duplicateInputSlugs),
            'artifacts' => [],
            'entity' => null,
            'index_state' => null,
        ];

        if ($entityOutput !== null) {
            [$artifact, $entitySummary] = $this->entityArtifact($source, $slugs, $duplicateInputSlugs, $occupationsBySlug);
            $this->writeJson($entityOutput, $artifact);
            $summary['artifacts']['entity_context'] = $entityOutput;
            $summary['entity'] = [...$entitySummary, 'output_path' => $entityOutput];
        }

        if ($indexOutput !== null) {
            [$artifact, $indexSummary] = $this->indexStateArtifact($source, $slugs, $occupationsBySlug);
            $this->writeJson($indexOutput, $artifact);
            $summary['artifacts']['index_state_context'] = $indexOutput;
            $summary['index_state'] = [...$indexSummary, 'output_path' => $indexOutput];
        }

        return $this->finish($summary, self::SUCCESS);
    }

    private function normalizedOption(string $name): ?string
    {
        $value = $this->option($name);
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param  list<\App\Domain\Career\Audit\CareerPublicResolutionPlanRow>  $rows
     * @return list<string>
     */
    private function canonicalSlugs(array $rows): array
    {
        $slugs = [];
        foreach ($rows as $row) {
            if ($row->canonicalSlug !== null && ! in_array($row->canonicalSlug, $slugs, true)) {
                $slugs[] = $row->canonicalSlug;
            }
        }

        return $slugs;
    }

    /**
     * @param  list<\App\Domain\Career\Audit\CareerPublicResolutionPlanRow>  $rows
     * @return array<string, true>
     */
    private function duplicateInputSlugs(array $rows): array
    {
        $seen = [];
        $duplicates = [];
        foreach ($rows as $row) {
            if ($row->canonicalSlug === null) {
                continue;
            }

            if (isset($seen[$row->canonicalSlug])) {
                $duplicates[$row->canonicalSlug] = true;

                continue;
            }

            $seen[$row->canonicalSlug] = true;
        }

        return $duplicates;
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, list<Occupation>>
     */
    private function occupationsBySlug(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        /** @var EloquentCollection<int, Occupation> $occupations */
        $occupations = Occupation::query()
            ->whereIn('canonical_slug', $slugs)
            ->with([
                'family',
                'crosswalks',
                'indexStates' => fn ($query) => $query
                    ->orderByDesc('changed_at')
                    ->orderByDesc('created_at')
                    ->orderByDesc('updated_at'),
            ])
            ->get();

        $bySlug = [];
        foreach ($occupations as $occupation) {
            $slug = $this->normalizeString($occupation->getAttribute('canonical_slug'));
            if ($slug === null) {
                continue;
            }

            $bySlug[$slug] ??= [];
            $bySlug[$slug][] = $occupation;
        }

        return $bySlug;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $slugs
     * @param  array<string, true>  $duplicateInputSlugs
     * @param  array<string, list<Occupation>>  $occupationsBySlug
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function entityArtifact(array $source, array $slugs, array $duplicateInputSlugs, array $occupationsBySlug): array
    {
        $rows = [];
        $occupationsFound = 0;
        $duplicateEntitySlugs = [];

        foreach ($slugs as $slug) {
            $occupations = $occupationsBySlug[$slug] ?? [];
            $occupation = $occupations[0] ?? null;
            if ($occupation !== null) {
                $occupationsFound++;
            }
            if (count($occupations) > 1) {
                $duplicateEntitySlugs[] = $slug;
            }

            $rows[] = $this->entityRow($slug, $occupation, count($occupations), isset($duplicateInputSlugs[$slug]));
        }

        return [
            [
                'schema_version' => 'career_entity_context.v1',
                'source' => $source,
                'rows' => $rows,
            ],
            [
                'expected_slugs' => count($slugs),
                'entity_rows_written' => count($rows),
                'occupations_found' => $occupationsFound,
                'occupations_missing' => count($slugs) - $occupationsFound,
                'duplicate_input_slugs' => count($duplicateInputSlugs),
                'duplicate_entity_slugs' => count($duplicateEntitySlugs),
                'duplicate_entity_slug_values' => $duplicateEntitySlugs,
            ],
        ];
    }

    private function entityRow(string $slug, ?Occupation $occupation, int $entityRowCount, bool $duplicateInputSlug): array
    {
        if ($occupation === null) {
            return [
                'canonical_slug' => $slug,
                'occupation_exists' => false,
                'occupation_id' => null,
                'title_en' => null,
                'title_zh' => null,
                'family' => null,
                'crosswalks' => [],
                'missing_entity_fields' => [],
                'evidence' => [
                    'canonical_slug' => $slug,
                    'occupation_missing' => true,
                    'duplicate_input_slug' => $duplicateInputSlug,
                ],
            ];
        }

        $missingFields = $this->missingEntityFields($occupation);

        return [
            'canonical_slug' => $slug,
            'occupation_exists' => true,
            'occupation_id' => $this->normalizeString($occupation->getAttribute('id')),
            'title_en' => $this->normalizeString($occupation->getAttribute('canonical_title_en')),
            'title_zh' => $this->normalizeString($occupation->getAttribute('canonical_title_zh')),
            'family' => $this->familyValue($occupation),
            'crosswalks' => $this->crosswalkRows($occupation),
            'missing_entity_fields' => $missingFields,
            'evidence' => [
                'occupation_id' => $this->normalizeString($occupation->getAttribute('id')),
                'entity_row_count' => $entityRowCount,
                'duplicate_entity_slug' => $entityRowCount > 1,
                'duplicate_input_slug' => $duplicateInputSlug,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function missingEntityFields(Model $occupation): array
    {
        $missing = [];
        foreach (self::REQUIRED_ENTITY_FIELDS as $field) {
            if ($this->normalizeString($occupation->getAttribute($field)) === null) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private function familyValue(Occupation $occupation): ?string
    {
        $family = $occupation->getRelationValue('family');
        if ($family instanceof Model) {
            return $this->normalizeString($family->getAttribute('canonical_slug'))
                ?? $this->normalizeString($family->getAttribute('title_en'))
                ?? $this->normalizeString($family->getAttribute('id'));
        }

        return $this->normalizeString($occupation->getAttribute('family_id'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function crosswalkRows(Occupation $occupation): array
    {
        $crosswalks = $occupation->getRelationValue('crosswalks');
        if (! $crosswalks instanceof EloquentCollection) {
            return [];
        }

        return $crosswalks->map(fn (Model $crosswalk): array => [
            'source_system' => $this->normalizeString($crosswalk->getAttribute('source_system')),
            'source_code' => $this->normalizeString($crosswalk->getAttribute('source_code')),
            'source_title' => $this->normalizeString($crosswalk->getAttribute('source_title')),
            'mapping_type' => $this->normalizeString($crosswalk->getAttribute('mapping_type')),
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $slugs
     * @param  array<string, list<Occupation>>  $occupationsBySlug
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function indexStateArtifact(array $source, array $slugs, array $occupationsBySlug): array
    {
        $rows = [];
        $latestFound = 0;
        $observedStates = [];

        foreach ($slugs as $slug) {
            $occupation = ($occupationsBySlug[$slug] ?? [])[0] ?? null;
            $row = $this->indexStateRow($slug, $occupation);
            if ($row['latest_index_state'] !== null) {
                $latestFound++;
                $observedStates[$row['latest_index_state']] = ($observedStates[$row['latest_index_state']] ?? 0) + 1;
            }
            $rows[] = $row;
        }

        ksort($observedStates);

        return [
            [
                'schema_version' => 'career_index_state_context.v1',
                'source' => $source,
                'rows' => $rows,
            ],
            [
                'expected_slugs' => count($slugs),
                'index_rows_written' => count($rows),
                'latest_index_state_found' => $latestFound,
                'latest_index_state_missing' => count($slugs) - $latestFound,
                'observed_states' => $observedStates,
            ],
        ];
    }

    private function indexStateRow(string $slug, ?Occupation $occupation): array
    {
        if ($occupation === null) {
            return [
                'canonical_slug' => $slug,
                'latest_index_state' => null,
                'public_facing_state' => null,
                'index_eligible' => false,
                'changed_at' => null,
                'reason_codes' => [],
                'evidence' => [
                    'canonical_slug' => $slug,
                    'occupation_missing' => true,
                ],
            ];
        }

        $indexStates = $occupation->getRelationValue('indexStates');
        $indexState = $indexStates instanceof EloquentCollection ? $indexStates->first() : null;
        if (! $indexState instanceof IndexState) {
            return [
                'canonical_slug' => $slug,
                'latest_index_state' => null,
                'public_facing_state' => null,
                'index_eligible' => false,
                'changed_at' => null,
                'reason_codes' => [],
                'evidence' => [
                    'canonical_slug' => $slug,
                    'occupation_id' => $this->normalizeString($occupation->getAttribute('id')),
                    'index_state_missing' => true,
                ],
            ];
        }

        $rawState = $this->normalizeString($indexState->getAttribute('index_state')) ?? '';
        $indexEligible = (bool) $indexState->getAttribute('index_eligible');

        return [
            'canonical_slug' => $slug,
            'latest_index_state' => $rawState === '' ? null : $rawState,
            'public_facing_state' => IndexStateValue::publicFacing($rawState, $indexEligible),
            'index_eligible' => $indexEligible,
            'changed_at' => $this->dateString($indexState->getAttribute('changed_at')),
            'reason_codes' => $this->reasonCodes($indexState),
            'evidence' => [
                'occupation_id' => $this->normalizeString($occupation->getAttribute('id')),
                'index_state_id' => $this->normalizeString($indexState->getAttribute('id')),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function reasonCodes(IndexState $indexState): array
    {
        $reasonCodes = $indexState->getAttribute('reason_codes');
        if (! is_array($reasonCodes) || ! array_is_list($reasonCodes)) {
            return [];
        }

        $normalized = [];
        foreach ($reasonCodes as $reasonCode) {
            $value = $this->normalizeString($reasonCode);
            if ($value !== null) {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return list<string>
     */
    private function locales(): array
    {
        $raw = $this->normalizedOption('locales') ?? 'en,zh';
        $locales = [];
        foreach (explode(',', $raw) as $locale) {
            $normalized = $this->normalizeString($locale);
            if ($normalized !== null) {
                $locales[] = $normalized;
            }
        }

        return $locales === [] ? ['en', 'zh'] : array_values(array_unique($locales));
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        if ($normalized === '' || strtolower($normalized) === 'null') {
            return null;
        }

        return $normalized;
    }

    private function dateString(mixed $value): ?string
    {
        if (is_object($value) && method_exists($value, 'toISOString')) {
            return $value->toISOString();
        }

        return $this->normalizeString($value);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        $directory = dirname($path);
        if ($directory !== '' && $directory !== '.') {
            File::ensureDirectoryExists($directory);
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) {
            throw new \RuntimeException('Failed to encode career context artifact JSON.');
        }

        File::put($path, $encoded.PHP_EOL);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finish(array $payload, int $exitCode): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $exitCode;
        }

        $this->line('status='.(string) ($payload['status'] ?? 'unknown'));
        $this->line('read_only=true');
        $this->line('writes_database=false');

        foreach ((array) ($payload['artifacts'] ?? []) as $name => $path) {
            $this->line($name.'='.(string) $path);
        }

        return $exitCode;
    }
}
