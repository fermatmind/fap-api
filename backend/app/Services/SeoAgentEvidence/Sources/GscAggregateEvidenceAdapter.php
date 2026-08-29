<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Sources;

use App\Services\SeoAgentEvidence\Privacy\SeoPrivateDataScanner;

final class GscAggregateEvidenceAdapter
{
    private const SAFE_FIELDS = ['query_hmac', 'query_hmac_key_version', 'normalization_version', 'window', 'clicks', 'impressions', 'ctr_ppm', 'average_position_milli', 'freshness'];

    public function __construct(private readonly SeoPrivateDataScanner $scanner) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function adapt(array $input): array
    {
        if (($input['source_origin'] ?? null) !== 'live_gsc_api') {
            return $this->unavailable('GSC_SOURCE_NOT_LIVE');
        }
        foreach (['query', 'raw_query', 'query_hash', 'query_display_masked', 'canonical_url', 'user_id', 'tenant_id'] as $forbidden) {
            if (array_key_exists($forbidden, $input)) {
                return $this->unavailable('GSC_PRIVATE_OR_LEGACY_FIELD');
            }
        }
        if (($input['query_level'] ?? false) === true
            && (preg_match('/^[a-f0-9]{64}$/', (string) ($input['query_hmac'] ?? '')) !== 1
                || preg_match('/^[a-z0-9][a-z0-9._-]{0,31}$/', (string) ($input['query_hmac_key_version'] ?? '')) !== 1)) {
            return $this->unavailable('GSC_QUERY_HMAC_UNAVAILABLE');
        }
        $payload = array_intersect_key($input, array_flip(self::SAFE_FIELDS));
        if ($this->scanner->scan($payload)['private_data_present']) {
            return $this->unavailable('GSC_PRIVATE_DATA_BLOCKED');
        }

        return ['source_capability_state' => 'available', 'safe_error_code' => null, 'payload' => $payload];
    }

    /** @return array<string, mixed> */
    private function unavailable(string $code): array
    {
        return ['source_capability_state' => 'unavailable', 'safe_error_code' => $code, 'payload' => []];
    }
}
