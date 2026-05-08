<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\Share;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Validator;
use InvalidArgumentException;

final class BigFiveV2ShareSafeSummaryAdapter
{
    public const PAYLOAD_KEY = 'big5_result_page_v2_share_card';

    public const SCHEMA_VERSION = 'fap.big5.result_page_v2.share_card.v0_1';

    public function __construct(
        private readonly BigFiveResultPageV2Validator $validator = new BigFiveResultPageV2Validator,
    ) {}

    /**
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    public function adapt(array $envelope): array
    {
        $errors = $this->validator->validateEnvelope($envelope);
        if ($errors !== []) {
            throw new InvalidArgumentException('Big Five V2 share adapter requires a valid route-driven payload: '.implode('; ', $errors));
        }

        $payload = $envelope[BigFiveResultPageV2Contract::PAYLOAD_KEY];
        if (! is_array($payload)) {
            throw new InvalidArgumentException('Big Five V2 share adapter requires a payload object.');
        }

        $combinationKey = $this->combinationKeyFromPayload($payload);
        $summary = $this->shareSafeSummaryForCombinationKey($combinationKey);
        if ($summary === '') {
            throw new InvalidArgumentException("Big Five V2 share adapter cannot find share_safe_summary_zh: {$combinationKey}");
        }

        return [
            self::PAYLOAD_KEY => [
                'schema_version' => self::SCHEMA_VERSION,
                'surface_key' => 'share_card',
                'source_payload_key' => BigFiveResultPageV2Contract::PAYLOAD_KEY,
                'scale_code' => BigFiveResultPageV2Contract::SCALE_CODE,
                'content_version' => (string) ($payload['content_version'] ?? ''),
                'package_version' => (string) ($payload['package_version'] ?? ''),
                'summary_zh' => $summary,
                'share_policy' => [
                    'source' => 'route_matrix.share_safe_summary_zh',
                    'score_fields_allowed' => false,
                    'sensitive_emotional_detail_allowed' => false,
                    'frontend_authored_body_allowed' => false,
                    'invalid_payload_behavior' => 'fail_closed',
                    'metadata_filter_required' => true,
                    'production_enablement_allowed' => false,
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function combinationKeyFromPayload(array $payload): string
    {
        $domains = is_array(data_get($payload, 'projection_v2.domains')) ? data_get($payload, 'projection_v2.domains') : [];
        $parts = [];
        foreach (['O', 'C', 'E', 'A', 'N'] as $domain) {
            $score = data_get($domains, "{$domain}.score");
            if (! is_int($score) && ! (is_numeric($score) && (int) $score === (float) $score)) {
                throw new InvalidArgumentException("Big Five V2 share adapter missing route score for {$domain}");
            }

            $band = (int) $score;
            if ($band < 1 || $band > 5) {
                throw new InvalidArgumentException("Big Five V2 share adapter route score is out of range for {$domain}");
            }

            $parts[] = "{$domain}{$band}";
        }

        return implode('_', $parts);
    }

    private function shareSafeSummaryForCombinationKey(string $combinationKey): string
    {
        if (preg_match('/^O([1-5])_C[1-5]_E[1-5]_A[1-5]_N[1-5]$/', $combinationKey, $matches) !== 1) {
            throw new InvalidArgumentException("Big Five V2 share adapter combination key is invalid: {$combinationKey}");
        }

        $path = base_path("content_assets/big5/result_page_v2/route_matrix/v0_1_1/big5_3125_route_matrix_O{$matches[1]}_v0_1_1.jsonl");
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new InvalidArgumentException("Big Five V2 share adapter route shard is unreadable: O{$matches[1]}");
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $row = json_decode($line, true);
                if (! is_array($row) || ($row['combination_key'] ?? null) !== $combinationKey) {
                    continue;
                }

                return trim((string) ($row['share_safe_summary_zh'] ?? ''));
            }
        } finally {
            fclose($handle);
        }

        throw new InvalidArgumentException("Big Five V2 share adapter cannot find route row: {$combinationKey}");
    }
}
