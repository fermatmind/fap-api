<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Services\BigFive\ResultPageV2\Pdf\BigFiveV2PdfPayloadAdapter;
use InvalidArgumentException;
use Tests\TestCase;

final class BigFiveResultPageV2PdfPayloadAdapterTest extends TestCase
{
    private const ROUTE_DRIVEN_O59_FIXTURE_PATH = 'tests/Fixtures/big5_result_page_v2/route_driven_o59_canonical_pilot_payload_v0_1.payload.json';

    private const PDF_O59_FIXTURE_PATH = 'tests/Fixtures/big5_result_page_v2/pdf_o59_route_driven_payload_v0_1.json';

    public function test_o59_route_driven_payload_adapts_to_pdf_payload_fixture(): void
    {
        $adapter = new BigFiveV2PdfPayloadAdapter;
        $pdfEnvelope = $adapter->adapt($this->decodeJson(self::ROUTE_DRIVEN_O59_FIXTURE_PATH));
        $pdfPayload = $pdfEnvelope[BigFiveV2PdfPayloadAdapter::PAYLOAD_KEY] ?? null;

        $this->assertIsArray($pdfPayload);
        $this->assertSame(BigFiveV2PdfPayloadAdapter::SCHEMA_VERSION, $pdfPayload['schema_version'] ?? null);
        $this->assertSame('pdf', $pdfPayload['surface_key'] ?? null);
        $this->assertSame('big5_result_page_v2.pilot_payload.v0_1', $pdfPayload['content_version'] ?? null);
        $this->assertSame('B5-CONTENT-staging-pilot.v0_1', $pdfPayload['package_version'] ?? null);
        $this->assertSame('sensitive_independent_thinker', $pdfPayload['canonical_profile_key'] ?? null);
        $this->assertSame('敏锐的独立思考者', $pdfPayload['profile_label_zh'] ?? null);
        $this->assertNotContains('module_08_share_save', array_column((array) ($pdfPayload['sections'] ?? []), 'module_key'));

        $encoded = json_encode($pdfEnvelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('敏锐的独立思考者', $encoded);
        $this->assertStringContainsString('n_high_x_o_mid_high', $encoded);

        $this->assertSame($pdfEnvelope, $this->decodeJson(self::PDF_O59_FIXTURE_PATH));
    }

    public function test_invalid_payload_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a valid route-driven payload');

        (new BigFiveV2PdfPayloadAdapter)->adapt([
            'big5_result_page_v2' => [
                'schema_version' => 'invalid',
                'modules' => [],
            ],
        ]);
    }

    public function test_pdf_payload_filters_internal_metadata_and_runtime_flags(): void
    {
        $encoded = json_encode(
            (new BigFiveV2PdfPayloadAdapter)->adapt($this->decodeJson(self::ROUTE_DRIVEN_O59_FIXTURE_PATH)),
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
            '[object Object]',
        ] as $forbiddenPublicTerm) {
            $this->assertStringNotContainsString($forbiddenPublicTerm, $encoded, $forbiddenPublicTerm);
        }
    }

    public function test_pdf_payload_strips_share_specific_and_score_vector_fields(): void
    {
        $encoded = json_encode(
            (new BigFiveV2PdfPayloadAdapter)->adapt($this->decodeJson(self::ROUTE_DRIVEN_O59_FIXTURE_PATH)),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        foreach ([
            'share_safe_summary_zh',
            'share_card_summary_zh',
            'raw_score',
            'raw_scores',
            'standardized_scores',
            'score_vector',
            'percentile',
            'percentiles',
            'facet_vector',
            'domain_vector',
        ] as $forbiddenPdfTerm) {
            $this->assertStringNotContainsString($forbiddenPdfTerm, $encoded, $forbiddenPdfTerm);
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
