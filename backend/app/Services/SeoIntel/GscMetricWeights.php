<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

/** Preserve source position coverage when URL variants share one canonical row. */
final class GscMetricWeights
{
    public static function fromRow(array|object $row, string $mode = 'standard'): array
    {
        $row = (array) $row;
        $metadata = $row['metadata_json'] ?? [];
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }
        $saved = is_array($metadata) ? ($metadata['_canonical_metric_weights'] ?? null) : null;
        if (is_array($saved) && ($saved['version'] ?? null) === 1
            && isset($saved[$mode.'_numerator'], $saved[$mode.'_denominator'])) {
            return [(int) $saved[$mode.'_numerator'], (int) $saved[$mode.'_denominator']];
        }
        $position = $row['average_position_milli'] ?? null;
        $impressions = max(0, (int) ($row['impressions'] ?? 0));
        $weight = in_array($mode, ['minimum_one', 'minimum_one_all'], true) ? max(1, $impressions) : $impressions;
        if ($position === null || ($mode === 'positive_position' && (int) $position <= 0)) {
            return [0, $mode === 'minimum_one_all' ? $weight : 0];
        }

        return [(int) $position * $weight, $weight];
    }

    public static function sumSql(string $component): string
    {
        if (! in_array($component, ['numerator', 'denominator'], true)) {
            throw new \InvalidArgumentException('Unknown GSC metric component.');
        }
        $legacy = $component === 'numerator' ? 'average_position_milli * impressions' : 'impressions';
        $value = "JSON_EXTRACT(metadata_json, '$._canonical_metric_weights.standard_{$component}')";

        return "COALESCE(SUM(CASE WHEN JSON_VALID(metadata_json) THEN CASE WHEN JSON_EXTRACT(metadata_json, '$._canonical_metric_weights.version') = 1 AND {$value} IS NOT NULL THEN {$value} + 0 ELSE CASE WHEN average_position_milli IS NOT NULL AND impressions > 0 THEN {$legacy} ELSE 0 END END ELSE CASE WHEN average_position_milli IS NOT NULL AND impressions > 0 THEN {$legacy} ELSE 0 END END), 0)";
    }

    public static function projectionSql(): string
    {
        return "CASE WHEN JSON_VALID(metadata_json) THEN CASE WHEN LENGTH(JSON_EXTRACT(metadata_json, '$._canonical_metric_weights')) <= 1024 THEN JSON_OBJECT('_canonical_metric_weights', JSON_EXTRACT(metadata_json, '$._canonical_metric_weights')) ELSE NULL END ELSE NULL END AS metadata_json";
    }
}
