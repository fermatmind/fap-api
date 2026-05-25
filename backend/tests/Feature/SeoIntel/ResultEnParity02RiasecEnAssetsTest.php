<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\Riasec\RiasecLifecycleCopyService;
use Tests\TestCase;

final class ResultEnParity02RiasecEnAssetsTest extends TestCase
{
    public function test_riasec_lifecycle_contract_has_explicit_english_assets(): void
    {
        $service = new RiasecLifecycleCopyService;

        $contract = $service->lifecycleCopyContract(snapshotBound: true, locale: 'en-US');

        $this->assertSame('available', $contract['status']);
        $this->assertSame('en', $contract['locale']);
        $this->assertSame('share_pdf_history_v1.en', $contract['share_pdf_history_asset_id']);
        $this->assertSame('faq_v1.en', $contract['faq_asset_id']);
        $this->assertSame('technical_note_user_summary_v1.en', $contract['technical_note_summary_asset_id']);
        $this->assertSame('professional_method_boundary_v1.en', $contract['professional_method_boundary_asset_id']);
        $this->assertCount(7, $contract['surfaces']);
        $this->assertCount(20, $contract['faq_items']);
        $this->assertTrue($contract['faq_markdown_reference_available']);
        $this->assertFalse($contract['frontend_fallback_allowed']);
        $this->assertSame('omit_module_fail_closed', $contract['missing_content_behavior']);
    }

    public function test_english_lifecycle_copy_does_not_fall_back_to_chinese(): void
    {
        $service = new RiasecLifecycleCopyService;

        $payload = [
            'contract' => $service->lifecycleCopyContract(snapshotBound: true, locale: 'en'),
            'technical_note_summary' => $service->technicalNoteSummarySections('en'),
            'professional_method_boundary' => $service->professionalMethodBoundarySections('en'),
        ];

        $serialized = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertDoesNotMatchRegularExpression('/[\x{4e00}-\x{9fff}]/u', $serialized);
        $this->assertStringNotContainsString('.zh-CN', $serialized);
        $this->assertStringContainsString('share_pdf_history_v1.en', $serialized);
        $this->assertStringContainsString('faq_v1.en', $serialized);
    }

    public function test_english_lifecycle_copy_keeps_riasec_claim_boundaries(): void
    {
        $service = new RiasecLifecycleCopyService;
        $payload = [
            'contract' => $service->lifecycleCopyContract(snapshotBound: true, locale: 'en'),
            'technical_note_summary' => $service->technicalNoteSummarySections('en'),
            'professional_method_boundary' => $service->professionalMethodBoundarySections('en'),
        ];

        $visibleText = $this->visibleText($payload);

        foreach ([
            'best career for you',
            'precise career',
            'job fit guarantee',
            'career success prediction',
            'salary prediction',
            'hiring suitability',
            'treatment plan',
            'cure',
        ] as $claim) {
            $this->assertStringNotContainsString($claim, strtolower($visibleText), $claim);
        }

        $this->assertStringContainsString('not a population percentile', $visibleText);
        $this->assertStringContainsString('not an ability score', $visibleText);
        $this->assertStringContainsString('not be used for recruiting', $visibleText);
        $this->assertStringContainsString('not a full report', $visibleText);
    }

    public function test_default_chinese_lifecycle_behavior_remains_unchanged(): void
    {
        $service = new RiasecLifecycleCopyService;

        $contract = $service->lifecycleCopyContract(snapshotBound: true);

        $this->assertSame('zh-CN', $contract['locale']);
        $this->assertSame('share_pdf_history_v1.zh-CN', $contract['share_pdf_history_asset_id']);
        $this->assertSame('faq_v1.zh-CN', $contract['faq_asset_id']);
        $this->assertCount(7, $contract['surfaces']);
        $this->assertCount(20, $contract['faq_items']);
        $this->assertCount(6, $service->technicalNoteSummarySections());
        $this->assertCount(8, $service->professionalMethodBoundarySections());
    }

    public function test_generated_riasec_inventory_json_parses_and_records_deferred_assets(): void
    {
        $path = base_path('docs/seo/generated/result-en-parity-02-riasec-en-assets.v1.json');
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('RESULT-EN-PARITY-02', $decoded['pr_id'] ?? null);
        $this->assertSame('riasec', $decoded['family'] ?? null);
        $this->assertSame('fail_closed_no_zh_fallback', $decoded['english_runtime_policy'] ?? null);
        $this->assertContains('share_pdf_history_v1.en', $decoded['prepared_english_assets'] ?? []);
        $this->assertContains('faq_v1.en', $decoded['prepared_english_assets'] ?? []);
        $this->assertNotEmpty($decoded['deferred_assets'] ?? []);
    }

    /**
     * @param  mixed  $payload
     */
    private function visibleText($payload): string
    {
        if (is_string($payload)) {
            return $payload;
        }

        if (! is_array($payload)) {
            return '';
        }

        $parts = [];
        foreach ($payload as $key => $value) {
            if (in_array($key, ['asset_id', 'faq_asset_id', 'share_pdf_history_asset_id', 'technical_note_summary_asset_id', 'professional_method_boundary_asset_id'], true)) {
                continue;
            }

            $parts[] = $this->visibleText($value);
        }

        return trim(implode(' ', array_filter($parts)));
    }
}
