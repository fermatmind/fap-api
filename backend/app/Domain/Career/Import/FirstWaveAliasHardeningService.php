<?php

declare(strict_types=1);

namespace App\Domain\Career\Import;

use App\Models\CareerImportRun;
use App\Models\Occupation;
use App\Models\OccupationFamily;

final class FirstWaveAliasHardeningService
{
    /**
     * @var array<string, array{canonical_slug:string,approved_alias_rows:list<array<string,mixed>>,blocked_aliases:list<string>}>|null
     */
    private ?array $catalog = null;

    public function __construct(
        private readonly FirstWaveAliasCatalogReader $catalogReader,
        private readonly FirstWaveFamilyAliasPolicy $familyAliasPolicy,
    ) {}

    /**
     * @return array{
     *   in_scope:bool,
     *   blocked_aliases:list<string>,
     *   alias_payloads:list<array<string,mixed>>,
     *   family_alias_payloads:list<array<string,mixed>>
     * }
     */
    public function resolveAliasPayloads(
        string $canonicalSlug,
        Occupation $occupation,
        OccupationFamily $family,
        CareerImportRun $importRun,
    ): array {
        $entry = $this->catalog()[$canonicalSlug] ?? null;
        if (! is_array($entry)) {
            return [
                'in_scope' => false,
                'blocked_aliases' => [],
                'alias_payloads' => [],
                'family_alias_payloads' => [],
            ];
        }

        $blockedAliases = array_values(array_filter(array_map(
            fn (mixed $alias): string => $this->normalizeText($alias),
            $entry['blocked_aliases'] ?? [],
        )));
        $blockedSet = array_fill_keys($blockedAliases, true);

        $payloads = [];
        $seen = [];

        foreach ($entry['approved_alias_rows'] as $row) {
            $alias = trim((string) ($row['alias'] ?? ''));
            $normalized = trim((string) ($row['normalized'] ?? ''));
            $lang = trim((string) ($row['lang'] ?? ''));

            if ($alias === '' || $normalized === '' || $lang === '') {
                continue;
            }

            $aliasKey = $this->normalizeText($alias);
            if ($aliasKey === '' || isset($blockedSet[$aliasKey])) {
                continue;
            }

            $dedupeKey = sprintf('%s|%s', strtolower($lang), $this->normalizeText($normalized));
            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $payloads[] = [
                'occupation_id' => $occupation->id,
                'family_id' => $family->id,
                'alias' => $alias,
                'normalized' => $this->normalizeText($normalized),
                'lang' => $lang,
                'register' => (string) ($row['register'] ?? 'alias'),
                'intent_scope' => 'exact',
                'target_kind' => 'occupation',
                'precision_score' => (float) ($row['precision'] ?? 1.0),
                'confidence_score' => (float) ($row['confidence'] ?? 1.0),
                'seniority_hint' => null,
                'function_hint' => null,
                'import_run_id' => $importRun->id,
                'row_fingerprint' => null,
            ];

            $seen[$dedupeKey] = true;
        }

        $familyAliases = $this->familyAliasPolicy->resolveFamilyAliasPayloads($family, $importRun);

        return [
            'in_scope' => true,
            'blocked_aliases' => $blockedAliases,
            'alias_payloads' => $payloads,
            'family_alias_payloads' => $familyAliases['alias_payloads'],
        ];
    }

    /**
     * @return array<string, array{canonical_slug:string,approved_alias_rows:list<array<string,mixed>>,blocked_aliases:list<string>}>
     */
    private function catalog(): array
    {
        if ($this->catalog === null) {
            $this->catalog = $this->catalogReader->bySlug();
        }

        return $this->catalog;
    }

    private function normalizeText(mixed $value): string
    {
        return mb_strtolower(trim((string) $value), 'UTF-8');
    }
}
