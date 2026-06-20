<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

use Illuminate\Support\Carbon;

final class GscReadModelArtifactDryRunImporter
{
    private const SCHEMA_VERSION = 'gsc-readmodel-importer-dryrun.v1';

    /**
     * @var list<string>
     */
    private const FORBIDDEN_FIELDS = [
        'raw_query',
        'raw_url',
        'query',
        'page',
        'canonical_url',
        'url',
        'credential_path',
        'token',
        'access_token',
        'api_key',
        'client_email',
        'service_account_json',
        'private_key',
        'cookie',
        'session',
        'raw_payload',
    ];

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    public function preview(array $artifact, int $limit = 250): array
    {
        $limit = max(1, min($limit, 250));
        $errors = $this->validateArtifact($artifact);

        $safeRows = data_get($artifact, 'payload.metadata.safe_row_preview', []);
        $previewRows = [];
        if (is_array($safeRows)) {
            foreach (array_slice(array_values($safeRows), 0, $limit) as $index => $row) {
                if (! is_array($row)) {
                    $errors[] = 'safe_row_preview.'.$index.'_must_be_object';

                    continue;
                }

                $rowErrors = $this->validateSafeRow($row, $index);
                if ($rowErrors !== []) {
                    $errors = [...$errors, ...$rowErrors];

                    continue;
                }

                $previewRows[] = $this->previewRow($row, $artifact);
            }
        }

        $errors = array_values(array_unique($errors));
        $ok = $errors === [] && $previewRows !== [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task' => 'SEO-GSC-READMODEL-IMPORTER-DRYRUN-01',
            'ok' => $ok,
            'dry_run' => true,
            'would_write' => false,
            'target_table' => 'seo_gsc_daily',
            'rows_previewed' => count($previewRows),
            'rows_would_insert' => $ok ? count($previewRows) : 0,
            'data_origin' => data_get($artifact, 'payload.metadata.data_origin'),
            'data_quality_gate' => data_get($artifact, 'payload.metadata.data_quality_gate.status'),
            'source_engine' => data_get($artifact, 'payload.metadata.safe_row_preview.0.source_engine'),
            'date_window' => data_get($artifact, 'payload.metadata.date_window'),
            'forbidden_fields_checked' => self::FORBIDDEN_FIELDS,
            'forbidden_fields_found' => $this->forbiddenFieldHits($artifact),
            'negative_guarantees' => [
                'database_write' => false,
                'seo_gsc_daily_write' => false,
                'scheduler_activation' => false,
                'opportunity_queue_enqueue' => false,
                'cms_write' => false,
                'search_channel_enqueue' => false,
                'search_provider_submission' => false,
                'indexing_request' => false,
                'live_gsc_api_call' => false,
            ],
            'preview_rows' => $ok ? $previewRows : [],
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return list<string>
     */
    private function validateArtifact(array $artifact): array
    {
        $errors = [];

        if ($this->forbiddenFieldHits($artifact) !== []) {
            $errors[] = 'forbidden_field_present';
        }

        if ((string) data_get($artifact, 'payload.metadata.data_origin') !== 'live_gsc_api') {
            $errors[] = 'data_origin_must_be_live_gsc_api';
        }

        if ((string) data_get($artifact, 'payload.metadata.data_quality_gate.status') !== 'pass') {
            $errors[] = 'data_quality_gate_must_pass';
        }

        if ((string) data_get($artifact, 'payload.status') !== 'success') {
            $errors[] = 'artifact_payload_status_must_be_success';
        }

        foreach ([
            'payload.writes_attempted',
            'payload.writes_committed',
            'payload.metadata.writes_attempted',
            'payload.metadata.writes_committed',
            'payload.metadata.cms_write_allowed',
            'payload.metadata.search_channel_enqueue_allowed',
            'payload.metadata.indexing_request_allowed',
        ] as $flag) {
            if ($this->boolValue(data_get($artifact, $flag))) {
                $errors[] = str_replace('.', '_', $flag).'_must_be_false';
            }
        }

        $safeRows = data_get($artifact, 'payload.metadata.safe_row_preview');
        if (! is_array($safeRows) || $safeRows === []) {
            $errors[] = 'safe_row_preview_required';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function validateSafeRow(array $row, int $index): array
    {
        $errors = [];

        foreach (['report_date', 'canonical_url_hash', 'query_hash', 'source_engine', 'clicks', 'impressions'] as $field) {
            if (! array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
                $errors[] = 'safe_row_preview.'.$index.'.'.$field.'_required';
            }
        }

        if ((string) ($row['source_engine'] ?? '') !== 'google') {
            $errors[] = 'safe_row_preview.'.$index.'.source_engine_must_be_google';
        }

        foreach (['canonical_url_hash', 'query_hash'] as $hashField) {
            $value = (string) ($row[$hashField] ?? '');
            if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
                $errors[] = 'safe_row_preview.'.$index.'.'.$hashField.'_must_be_sha256';
            }
        }

        foreach (['clicks', 'impressions'] as $metricField) {
            if (! is_numeric($row[$metricField] ?? null) || (int) $row[$metricField] < 0) {
                $errors[] = 'safe_row_preview.'.$index.'.'.$metricField.'_must_be_non_negative';
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    private function previewRow(array $row, array $artifact): array
    {
        return [
            'report_date' => substr((string) $row['report_date'], 0, 10),
            'canonical_url_hash' => (string) $row['canonical_url_hash'],
            'canonical_url' => null,
            'query_hash' => (string) $row['query_hash'],
            'query_display_masked' => $this->nullableString($row['query_display_masked'] ?? null),
            'locale' => $this->nullableString($row['locale'] ?? null),
            'source_engine' => 'google',
            'device' => $this->nullableString($row['device'] ?? null),
            'country' => $this->nullableString($row['country'] ?? null),
            'search_type' => $this->nullableString($row['search_type'] ?? null),
            'clicks' => max(0, (int) $row['clicks']),
            'impressions' => max(0, (int) $row['impressions']),
            'ctr_ppm' => $this->nullableNonNegativeInt($row['ctr_ppm'] ?? null),
            'average_position_milli' => $this->nullableNonNegativeInt($row['average_position_milli'] ?? null),
            'is_brand_query' => $this->boolValue($row['is_brand_query'] ?? false),
            'query_type' => $this->nullableString($row['query_type'] ?? null) ?? 'unknown',
            'data_state' => $this->nullableString($row['data_state'] ?? null) ?? 'final',
            'collected_at' => Carbon::now('UTC')->toIso8601String(),
            'metadata_json' => [
                'data_origin' => 'live_gsc_api',
                'row_source' => 'live_gsc_api',
                'source_artifact_schema' => (string) ($artifact['schema_version'] ?? ''),
                'source_artifact_mode' => (string) ($artifact['mode'] ?? ''),
                'purchase_attribution_allowed' => false,
                'dry_run_import_preview' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function forbiddenFieldHits(array $payload): array
    {
        $hits = [];
        $this->collectForbiddenFieldHits($payload, '', $hits);

        return array_values(array_unique($hits));
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $value
     * @param  list<string>  $hits
     */
    private function collectForbiddenFieldHits(array $value, string $path, array &$hits): void
    {
        foreach ($value as $key => $child) {
            $keyString = (string) $key;
            $normalized = mb_strtolower(trim($keyString), 'UTF-8');
            $childPath = $path === '' ? $keyString : $path.'.'.$keyString;

            if (in_array($normalized, self::FORBIDDEN_FIELDS, true)) {
                $hits[] = $childPath;
            }

            if (is_array($child)) {
                $this->collectForbiddenFieldHits($child, $childPath, $hits);
            }
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableNonNegativeInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int) $value);
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(mb_strtolower(trim((string) $value), 'UTF-8'), ['1', 'true', 'yes', 'on'], true);
    }
}
