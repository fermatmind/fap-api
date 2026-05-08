<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\Share\BigFiveV2ShareSafeSummaryAdapter;
use InvalidArgumentException;
use Tests\TestCase;

final class BigFiveResultPageV2ShareSafeSummaryAdapterTest extends TestCase
{
    private const ROUTE_DRIVEN_O59_FIXTURE_PATH = 'tests/Fixtures/big5_result_page_v2/route_driven_o59_canonical_pilot_payload_v0_1.payload.json';

    private const SHARE_O59_FIXTURE_PATH = 'tests/Fixtures/big5_result_page_v2/share_o59_route_driven_payload_v0_1.json';

    public function test_o59_route_driven_payload_adapts_to_share_safe_summary_fixture(): void
    {
        $adapter = new BigFiveV2ShareSafeSummaryAdapter;
        $shareEnvelope = $adapter->adapt($this->decodeJson(self::ROUTE_DRIVEN_O59_FIXTURE_PATH));
        $sharePayload = $shareEnvelope[BigFiveV2ShareSafeSummaryAdapter::PAYLOAD_KEY] ?? null;

        $this->assertIsArray($sharePayload);
        $this->assertSame(BigFiveV2ShareSafeSummaryAdapter::SCHEMA_VERSION, $sharePayload['schema_version'] ?? null);
        $this->assertSame('share_card', $sharePayload['surface_key'] ?? null);
        $this->assertSame('big5_result_page_v2.pilot_payload.v0_1', $sharePayload['content_version'] ?? null);
        $this->assertSame('B5-CONTENT-staging-pilot.v0_1', $sharePayload['package_version'] ?? null);
        $this->assertSame('我更适合用敏感、理解和低成本表达来观察自己的工作与恢复节奏。', $sharePayload['summary_zh'] ?? null);
        $this->assertSame('route_matrix.share_safe_summary_zh', data_get($sharePayload, 'share_policy.source'));

        $this->assertSame($shareEnvelope, $this->decodeJson(self::SHARE_O59_FIXTURE_PATH));
    }

    public function test_invalid_payload_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a valid route-driven payload');

        (new BigFiveV2ShareSafeSummaryAdapter)->adapt([
            'big5_result_page_v2' => [
                'schema_version' => 'invalid',
                'modules' => [],
            ],
        ]);
    }

    public function test_missing_route_score_fails_closed(): void
    {
        $envelope = $this->decodeJson(self::ROUTE_DRIVEN_O59_FIXTURE_PATH);
        unset($envelope['big5_result_page_v2']['projection_v2']['domains']['N']['score']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing route score for N');

        (new BigFiveV2ShareSafeSummaryAdapter)->adapt($envelope);
    }

    public function test_share_payload_does_not_expose_raw_scores_or_internal_metadata(): void
    {
        $encoded = json_encode(
            (new BigFiveV2ShareSafeSummaryAdapter)->adapt($this->decodeJson(self::ROUTE_DRIVEN_O59_FIXTURE_PATH)),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        foreach ([
            'source_reference',
            'selector_basis',
            'qa_notes',
            'editor_notes',
            'internal_metadata',
            'review_status',
            'production_use_allowed',
            'runtime_use',
            'ready_for_pilot',
            'ready_for_runtime',
            'ready_for_production',
            'frontend_fallback',
            'source_trace',
            'repair_log_refs',
            'raw_score',
            'raw_scores',
            'raw_mean',
            'standardized_scores',
            'score_vector',
            'percentile',
            'percentiles',
            'domains',
            'facets',
            'facet_vector',
            'domain_vector',
            '敏锐的独立思考者',
            '[object Object]',
        ] as $forbiddenPublicTerm) {
            $this->assertStringNotContainsString($forbiddenPublicTerm, $encoded, $forbiddenPublicTerm);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents(base_path($path)), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
