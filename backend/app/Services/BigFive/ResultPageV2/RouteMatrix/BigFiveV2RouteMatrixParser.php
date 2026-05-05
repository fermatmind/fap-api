<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\RouteMatrix;

use App\Services\BigFive\ResultPageV2\ContentAssets\BigFiveV2AssetManifestValidator;

final class BigFiveV2RouteMatrixParser
{
    public const RELATIVE_PATH = 'content_assets/big5/result_page_v2/route_matrix/v0_1_1';

    public const O59_COMBINATION_KEY = 'O3_C2_E2_A3_N4';

    private const EXPECTED_SHARDS = ['O1', 'O2', 'O3', 'O4', 'O5'];

    private const EXPECTED_SECTIONS = [
        'hero_summary',
        'domains_overview',
        'domain_deep_dive',
        'facet_details',
        'core_portrait',
        'norms_comparison',
        'action_plan',
        'methodology_and_access',
    ];

    public function __construct(
        private readonly BigFiveV2AssetManifestValidator $manifestValidator = new BigFiveV2AssetManifestValidator(),
    ) {}

    public function parse(?string $matrixPath = null): BigFiveV2RouteMatrixParseResult
    {
        $matrixPath = $matrixPath ?? base_path(self::RELATIVE_PATH);
        if (! is_dir($matrixPath)) {
            return new BigFiveV2RouteMatrixParseResult($matrixPath, [], [], ["route matrix directory missing: {$matrixPath}"]);
        }

        $errors = [];
        $rowsByCombinationKey = [];
        $rowCountsByShard = [];
        $seenShardKeys = [];

        foreach (self::EXPECTED_SHARDS as $shardKey) {
            $file = $matrixPath.DIRECTORY_SEPARATOR."big5_3125_route_matrix_{$shardKey}_v0_1_1.jsonl";
            if (! is_file($file)) {
                $errors[] = "missing route matrix shard {$shardKey}";
                continue;
            }

            $seenShardKeys[] = $shardKey;
            $rows = $this->parseShard($file, $shardKey, $errors);
            $rowCountsByShard[$shardKey] = count($rows);

            foreach ($rows as $row) {
                if (isset($rowsByCombinationKey[$row->combinationKey])) {
                    $errors[] = "duplicate combination_key {$row->combinationKey}";
                    continue;
                }

                $rowsByCombinationKey[$row->combinationKey] = $row;
            }
        }

        if (count($seenShardKeys) !== 5) {
            $errors[] = 'route matrix shard_count must be 5';
        }

        foreach ($rowCountsByShard as $shardKey => $count) {
            if ($count !== 625) {
                $errors[] = "route matrix shard {$shardKey} row_count must be 625";
            }
        }

        if (count($rowsByCombinationKey) !== 3125) {
            $errors[] = 'route matrix total unique row count must be 3125';
        }

        $errors = array_merge($errors, $this->validateFullCombinationCoverage($rowsByCombinationKey));
        $errors = array_merge($errors, $this->validateO59Row($rowsByCombinationKey));

        return new BigFiveV2RouteMatrixParseResult($matrixPath, $rowsByCombinationKey, $rowCountsByShard, array_values(array_unique($errors)));
    }

    /**
     * @return list<BigFiveV2RouteMatrixRow>
     */
    private function parseShard(string $file, string $shardKey, array &$errors): array
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! is_array($lines)) {
            $errors[] = "route matrix shard {$shardKey} is unreadable";

            return [];
        }

        $rows = [];
        foreach ($lines as $lineNumber => $line) {
            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                $errors[] = "route matrix shard {$shardKey} line ".($lineNumber + 1).' is not valid JSON';
                continue;
            }

            $combinationKey = (string) ($decoded['combination_key'] ?? '');
            if (! $this->isValidCombinationKey($combinationKey)) {
                $errors[] = "route matrix shard {$shardKey} line ".($lineNumber + 1)." has invalid combination_key {$combinationKey}";
                continue;
            }

            if (! str_starts_with($combinationKey, $shardKey.'_')) {
                $errors[] = "route matrix shard {$shardKey} line ".($lineNumber + 1)." combination_key is in wrong shard: {$combinationKey}";
            }

            $errors = array_merge($errors, $this->validateRowDocument($decoded, $combinationKey));
            $rows[] = new BigFiveV2RouteMatrixRow(
                combinationKey: $combinationKey,
                profileFamily: (string) ($decoded['profile_family'] ?? ''),
                profileKey: (string) ($decoded['nearest_canonical_profile_key'] ?? ''),
                interpretationScope: (string) ($decoded['interpretation_scope'] ?? ''),
                data: $decoded,
            );
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function validateRowDocument(array $row, string $combinationKey): array
    {
        $errors = [];
        $errors = array_merge($errors, $this->manifestValidator->validateDocument($row, $combinationKey));

        if (($row['runtime_use'] ?? null) !== 'staging_only') {
            $errors[] = "{$combinationKey}.runtime_use must be staging_only";
        }

        if (($row['production_use_allowed'] ?? null) !== false) {
            $errors[] = "{$combinationKey}.production_use_allowed must be false";
        }

        if (($row['ready_for_pilot'] ?? null) !== false) {
            $errors[] = "{$combinationKey}.ready_for_pilot must be false";
        }

        $sectionRoute = $row['section_route'] ?? null;
        if (! is_array($sectionRoute)) {
            $errors[] = "{$combinationKey}.section_route must be an object";
        } else {
            $missing = array_values(array_diff(self::EXPECTED_SECTIONS, array_keys($sectionRoute)));
            if ($missing !== []) {
                $errors[] = "{$combinationKey}.section_route missing sections: ".implode(',', $missing);
            }
        }

        if ($this->containsKey($row, 'body_zh')) {
            $errors[] = "{$combinationKey} must not contain body_zh";
        }

        return $errors;
    }

    /**
     * @param  array<string,BigFiveV2RouteMatrixRow>  $rowsByCombinationKey
     * @return list<string>
     */
    private function validateFullCombinationCoverage(array $rowsByCombinationKey): array
    {
        $errors = [];
        foreach (range(1, 5) as $o) {
            foreach (range(1, 5) as $c) {
                foreach (range(1, 5) as $e) {
                    foreach (range(1, 5) as $a) {
                        foreach (range(1, 5) as $n) {
                            $key = "O{$o}_C{$c}_E{$e}_A{$a}_N{$n}";
                            if (! isset($rowsByCombinationKey[$key])) {
                                $errors[] = "missing combination_key {$key}";
                            }
                        }
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,BigFiveV2RouteMatrixRow>  $rowsByCombinationKey
     * @return list<string>
     */
    private function validateO59Row(array $rowsByCombinationKey): array
    {
        $row = $rowsByCombinationKey[self::O59_COMBINATION_KEY] ?? null;
        if ($row === null) {
            return ['O59 route row missing: '.self::O59_COMBINATION_KEY];
        }

        $errors = [];
        if ($row->profileKey !== 'sensitive_independent_thinker') {
            $errors[] = 'O59 route row nearest_canonical_profile_key must be sensitive_independent_thinker';
        }

        if (($row->data['nearest_canonical_profile_label_zh'] ?? null) !== '敏锐的独立思考者') {
            $errors[] = 'O59 route row label must be 敏锐的独立思考者';
        }

        if (($row->data['profile_label_public_allowed'] ?? null) !== true) {
            $errors[] = 'O59 route row profile_label_public_allowed must be true';
        }

        return $errors;
    }

    private function isValidCombinationKey(string $combinationKey): bool
    {
        return preg_match('/^O[1-5]_C[1-5]_E[1-5]_A[1-5]_N[1-5]$/', $combinationKey) === 1;
    }

    private function containsKey(mixed $value, string $needle): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $key => $child) {
            if ($key === $needle || $this->containsKey($child, $needle)) {
                return true;
            }
        }

        return false;
    }
}
