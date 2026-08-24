<?php

declare(strict_types=1);

namespace App\Domain\Career\Compilation;

use App\Domain\Career\Display\CareerCurrentAuthorityPackage;

final class CareerStructuredComponentProjector
{
    public const QUICK_SCHEMA_VERSION = 'career.quick_answers.v1';

    public const ONET_SCHEMA_VERSION = 'career.onet_structured_fields.v1';

    public const SOURCE_REGISTRY_VERSION = 'career.structured_components.source_registry.v1';

    /** @return array<string,mixed> */
    public function quickAnswers(array $definition): array
    {
        return [
            'availability' => 'published',
            'schema_version' => self::QUICK_SCHEMA_VERSION,
            'heading' => '职业速答',
            'items' => array_map(fn (string $key): array => [
                'key' => $key,
                'question' => $definition[$key.'_q'],
                'answer' => $definition[$key.'_a'],
                'table' => ['rows' => $this->rows($definition[$key.'_table'])],
            ], ['qa3', 'qa2', 'qa1']),
        ];
    }

    /** @return array<string,mixed> */
    public function onetStructuredFields(array $definition): array
    {
        return [
            'availability' => 'published',
            'schema_version' => self::ONET_SCHEMA_VERSION,
            'heading' => 'O*NET 结构化字段',
            'rows' => $this->rows($definition['onet_struct']),
        ];
    }

    /** @return array{availability:string,reason_code:string} */
    public function unavailable(): array
    {
        return [
            'availability' => 'unavailable',
            'reason_code' => 'source_locale_unavailable',
        ];
    }

    /** @return array<string,mixed> */
    public function evidenceBindings(array $definition): array
    {
        $quickValue = [
            'qa3_q' => $definition['qa3_q'],
            'qa3_a' => $definition['qa3_a'],
            'qa3_table' => $definition['qa3_table'],
            'qa2_q' => $definition['qa2_q'],
            'qa2_a' => $definition['qa2_a'],
            'qa2_table' => $definition['qa2_table'],
            'qa1_q' => $definition['qa1_q'],
            'qa1_a' => $definition['qa1_a'],
            'qa1_table' => $definition['qa1_table'],
        ];

        return [
            'contract_version' => 'career.structured_components.claim_bindings.v1',
            'bindings' => [
                [
                    'component_id' => 'career_quick_answers_block',
                    'input_jsonpaths' => [
                        '$.definition.qa3_q', '$.definition.qa3_a', '$.definition.qa3_table',
                        '$.definition.qa2_q', '$.definition.qa2_a', '$.definition.qa2_table',
                        '$.definition.qa1_q', '$.definition.qa1_a', '$.definition.qa1_table',
                    ],
                    'normalized_value_sha256' => CareerCurrentAuthorityPackage::hashValue($quickValue),
                    'source_registry_key' => 'career.ten_block.definition.quick_answers',
                ],
                [
                    'component_id' => 'onet_structured_fields_block',
                    'input_jsonpaths' => ['$.definition.onet_struct'],
                    'normalized_value_sha256' => CareerCurrentAuthorityPackage::hashValue($definition['onet_struct']),
                    'source_registry_key' => 'career.ten_block.definition.onet_struct',
                ],
            ],
        ];
    }

    /** @param list<array<string,string>> $rows @return list<array{label:string,value:string,alternate_value:?string,secondary_value:?string}> */
    public function rows(array $rows): array
    {
        return array_map(static function (array $row): array {
            $standard = array_key_exists('k', $row);
            $label = $standard ? ($row['k'] ?? null) : ($row['label'] ?? null);
            $value = $standard ? ($row['v'] ?? null) : ($row['value'] ?? $row['v'] ?? null);
            $alternate = ! $standard && array_key_exists('value', $row) && array_key_exists('v', $row)
                ? $row['v'] : null;
            $secondary = $row['value2'] ?? null;
            $alternate = is_string($alternate) && trim($alternate) === '' ? null : $alternate;
            $secondary = is_string($secondary) && trim($secondary) === '' ? null : $secondary;
            if (! is_string($label) || trim($label) === '' || ! is_string($value) || trim($value) === ''
                || ($alternate !== null && (! is_string($alternate) || trim($alternate) === ''))
                || ($secondary !== null && (! is_string($secondary) || trim($secondary) === ''))) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_STRUCTURED_ROW_INVALID');
            }

            return [
                'label' => $label,
                'value' => $value,
                'alternate_value' => $alternate,
                'secondary_value' => $secondary,
            ];
        }, $rows);
    }
}
