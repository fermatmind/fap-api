<?php

declare(strict_types=1);

namespace App\Domain\Career\Compilation;

final class CareerTenBlockVariantNormalizer
{
    /** @param array<string,array<string,mixed>> $blocks @param list<array<string,mixed>> $links @return array<string,mixed> */
    public function normalize(array $blocks, string $profile, array $links): array
    {
        $generic = (new CareerTenBlockNormalizer)->normalize($blocks, $profile);

        return [
            'contract_version' => 'career.ten_block.variant_ir.v1',
            'input_profile' => $profile,
            'files' => $generic['files'],
            'canonical_tables' => [
                'definition' => $this->definitionTables($blocks['definition.json']),
                'quick_answers' => $this->quickAnswers($blocks['definition.json']),
                'onet_structured_fields' => [
                    'rows' => $this->definitionTables($blocks['definition.json'])['onet_struct'],
                ],
                'ai_personas' => $this->aiPersonas($blocks['ai-impact.json']['ai_s5_persona']),
                'ai_tools' => $this->aiTools($blocks['ai-impact.json']['ai_s6_tools']),
                'salary' => $this->salaryTables($blocks['salary.json']),
                'salary_range' => [
                    'minimum' => $blocks['page-meta.json']['oc_salary_min'],
                    'maximum' => $blocks['page-meta.json']['oc_salary_max'],
                    'display_eligible' => is_string($blocks['page-meta.json']['oc_salary_min'])
                        && is_string($blocks['page-meta.json']['oc_salary_max']),
                    'blocker_codes' => is_string($blocks['page-meta.json']['oc_salary_min'])
                        && is_string($blocks['page-meta.json']['oc_salary_max'])
                        ? [] : ['TEN_BLOCK_SALARY_RANGE_NULL'],
                ],
                'risk_paths' => $this->semanticRows($blocks['risk.json']['risk_path_table']),
                'comparison' => $this->comparison($blocks['compare-links.json']['compare_rows']),
                'page_meta_lists' => [
                    'signal_list' => $this->semanticRows($blocks['page-meta.json']['signal_list']),
                    'oc_skills' => $this->semanticRows($blocks['page-meta.json']['oc_skills']),
                    'jp_skills' => $this->semanticRows($blocks['page-meta.json']['jp_skills']),
                ],
            ],
            'internal_links' => $links,
            'field_coverage' => $this->coverage($blocks),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function quickAnswers(array $definition): array
    {
        $tables = $this->definitionTables($definition);

        return array_map(static fn (string $key): array => [
            'key' => $key,
            'question' => $definition[$key.'_q'],
            'answer' => $definition[$key.'_a'],
            'table' => ['rows' => $tables[$key.'_table']],
        ], ['qa3', 'qa2', 'qa1']);
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function definitionTables(array $definition): array
    {
        $tables = [];
        foreach (['qa1_table', 'qa2_table', 'qa3_table', 'onet_struct'] as $key) {
            $tables[$key] = array_map(static function (array $row): array {
                $standard = array_key_exists('k', $row);
                $alternate = ! $standard && array_key_exists('value', $row) && array_key_exists('v', $row)
                    ? $row['v'] : null;
                $secondary = $row['value2'] ?? null;

                return [
                    'label' => $standard ? $row['k'] : $row['label'],
                    'value' => $standard ? $row['v'] : ($row['value'] ?? $row['v'] ?? null),
                    'alternate_value' => is_string($alternate) && trim($alternate) === '' ? null : $alternate,
                    'secondary_value' => is_string($secondary) && trim($secondary) === '' ? null : $secondary,
                    'column_contract' => array_keys($row),
                ];
            }, $definition[$key]);
        }

        return $tables;
    }

    /** @param list<array<string,string>> $rows @return list<array<string,string>> */
    private function aiPersonas(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'persona' => $row['人群'] ?? $row['persona'],
            'advice' => $row['建议'] ?? $row['advice'],
        ], $rows);
    }

    /** @param list<array<string,string>> $rows @return list<array{name:string,positioning:string,representative_capability:?string,blocker_codes:list<string>}> */
    private function aiTools(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'name' => $row['工具'] ?? $row['name'],
            'positioning' => $row['定位'] ?? $row['desc'],
            'representative_capability' => $row['代表能力'] ?? null,
            'blocker_codes' => isset($row['代表能力']) ? [] : ['TEN_BLOCK_AI_TOOL_CAPABILITY_MISSING'],
        ], $rows);
    }

    /** @return array<string,mixed> */
    private function salaryTables(array $salary): array
    {
        $singleRows = [];
        foreach (['china_name_row', 'china_soc_row', 'china_class_row', 'china_ai_row'] as $key) {
            $value = $salary[$key];
            $singleRows[$key] = is_array($value)
                ? ['label' => $value['label'], 'value' => $value['value']]
                : ['label' => $key, 'value' => $value];
        }

        return [
            'single_rows' => $singleRows,
            'china_salary_table' => $this->semanticRows($salary['china_salary_table']),
            'china_edu_table' => $this->semanticRows($salary['china_edu_table']),
            'china_industry_table' => $this->semanticRows($salary['china_industry_table']),
            'bls_table' => $this->semanticRows($salary['bls_table']),
        ];
    }

    /** @param list<array<string,string>> $rows @return array{column_contract:list<string>,semantic_columns:list<string>,rows:list<array<string,string>>} */
    private function comparison(array $rows): array
    {
        $columns = array_keys($rows[0]);
        $map = [
            '职业' => 'occupation', '区别' => 'difference', 'AI 影响' => 'ai_impact', 'AI影响' => 'ai_impact',
            'occupation' => 'occupation', 'diff' => 'difference', '岗位' => 'role', '重心' => 'focus', '产出' => 'output',
        ];

        return [
            'column_contract' => $columns,
            'semantic_columns' => array_map(static fn (string $column): string => $map[$column], $columns),
            'rows' => array_map(static function (array $row) use ($map): array {
                $normalized = [];
                foreach ($row as $column => $value) {
                    $normalized[$map[$column]] = $value;
                }

                return $normalized;
            }, $rows),
        ];
    }

    /** @param list<string|array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function semanticRows(array $rows): array
    {
        return array_map(static function (mixed $row): array {
            if (is_string($row)) {
                return ['column_contract' => ['value'], 'values' => ['value' => $row]];
            }

            return ['column_contract' => array_keys($row), 'values' => $row];
        }, $rows);
    }

    /** @param array<string,array<string,mixed>> $blocks @return list<array<string,mixed>> */
    private function coverage(array $blocks): array
    {
        $publicDefinitionFields = array_fill_keys([
            'qa1_q', 'qa1_a', 'qa1_table', 'qa2_q', 'qa2_a', 'qa2_table',
            'qa3_q', 'qa3_a', 'qa3_table', 'onet_struct',
        ], true);
        $coverage = [];
        foreach ($blocks as $file => $value) {
            foreach (array_keys($value) as $key) {
                $metadata = ($file === 'geo.json' && $key === 'migrated_from_content_json')
                    || ($file === 'faq.json' && $key === 'intent');
                $public = $file === 'definition.json' && isset($publicDefinitionFields[$key]);
                $coverage[] = [
                    'input_jsonpath' => '$.'.substr($file, 0, -5).'.'.$key,
                    'ir_disposition' => 'mapped_to_ir',
                    'public_disposition' => $metadata
                        ? 'omitted_with_reason'
                        : ($public ? 'mapped_to_public_component' : 'mapped_to_ir'),
                    'disposition' => $metadata
                        ? 'omitted_with_reason'
                        : ($public ? 'mapped_to_public_component' : 'mapped_to_ir'),
                    'reason' => $metadata ? 'preserved in provenance IR and excluded from public projection' : null,
                ];
            }
        }
        usort($coverage, static fn (array $a, array $b): int => strcmp($a['input_jsonpath'], $b['input_jsonpath']));

        return $coverage;
    }
}
