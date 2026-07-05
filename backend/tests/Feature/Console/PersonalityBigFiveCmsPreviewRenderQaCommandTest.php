<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\PersonalityBigFiveCmsPreviewRenderQa;
use App\Models\PersonalityPublicContentAsset;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class PersonalityBigFiveCmsPreviewRenderQaCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE_PACKAGE = 'big-five-cms-import-draft-polished.v2';

    private const SOURCE_HASH = '15d6b6df08cf3ce7c9cd8a859b566c5bfd5fc4f6c6b279c493d48bc9e447ebc6';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand($this->app->make(PersonalityBigFiveCmsPreviewRenderQa::class));
    }

    public function test_preview_render_qa_passes_for_forty_two_draft_noindex_rows(): void
    {
        $this->seedDraftRows();

        $exitCode = Artisan::call('personality:big-five-cms-preview-render-qa', [
            '--source-hash' => self::SOURCE_HASH,
            '--target-env' => 'staging',
            '--allow-testing' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('pass', $payload['status']);
        $this->assertSame(42, $payload['row_count']);
        $this->assertSame(42, $payload['preview_payload_count']);
        $this->assertSame(0, $payload['public_api_readback_visible_count']);
        $this->assertTrue($payload['public_api_draft_blocked']);
        $this->assertSame(0, $payload['faq_duplicate_render_risk_count']);
        $this->assertSame(0, $payload['runtime_jsonld_enabled_count']);
        $this->assertSame(0, $payload['schema_payload_non_empty_count']);
        $this->assertTrue($payload['discoverability_gates']['sitemap_blocked']);
        $this->assertTrue($payload['discoverability_gates']['llms_blocked']);
        $this->assertTrue($payload['discoverability_gates']['jsonld_runtime_blocked']);
        $this->assertSame(0, $payload['noindex_gate']['is_public_true']);
        $this->assertSame(0, $payload['noindex_gate']['index_eligible_true']);
        $this->assertSame(0, $payload['noindex_gate']['sitemap_eligible_true']);
        $this->assertSame(0, $payload['noindex_gate']['llms_eligible_true']);
        $this->assertSame(['noindex,follow'], $payload['noindex_gate']['robots_values']);
        $this->assertSame(['review'], $payload['noindex_gate']['launch_states']);
        $this->assertSame(['cms_import_draft_pending_review'], $payload['noindex_gate']['review_states']);
        $this->assertSame(5, $payload['rows'][0]['faq_count']);
        $this->assertFalse($payload['rows'][0]['faq_duplicate_render_risk']);
        $this->assertFalse($payload['rows'][0]['schema_runtime_eligible']);
        $this->assertTrue($payload['rows'][0]['schema_payload_empty_for_public_runtime']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['cms_write_attempted']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['jsonld_runtime_release_attempted']);
    }

    public function test_preview_render_qa_fails_when_body_sections_still_contain_faq(): void
    {
        $this->seedDraftRows(faqBodySectionPosition: 7);

        $exitCode = Artisan::call('personality:big-five-cms-preview-render-qa', [
            '--source-hash' => self::SOURCE_HASH,
            '--target-env' => 'staging',
            '--allow-testing' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame(1, $payload['faq_duplicate_render_risk_count']);
        $this->assertContains('gate_must_remain_zero', array_column($payload['errors'], 'code'));
    }

    public function test_preview_render_qa_rejects_production_runtime(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $exitCode = Artisan::call('personality:big-five-cms-preview-render-qa', [
            '--source-hash' => self::SOURCE_HASH,
            '--target-env' => 'staging',
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertStringContainsString('Production environment is not authorized', (string) ($payload['errors'][0]['message'] ?? ''));
    }

    private function seedDraftRows(?int $faqBodySectionPosition = null): void
    {
        for ($position = 1; $position <= 42; $position++) {
            $sections = [
                [
                    'key' => 'overview',
                    'title' => 'Overview',
                    'body_md' => 'This is a draft preview/readback section for Big Five CMS QA.',
                ],
                [
                    'key' => 'method_boundary',
                    'title' => 'Method Boundary',
                    'body_md' => 'This draft is non-diagnostic, non-predictive, and not for hiring or high-stakes decisions.',
                ],
            ];

            if ($faqBodySectionPosition === $position) {
                $sections[] = [
                    'key' => 'faq',
                    'title' => 'FAQ',
                    'body_md' => 'This section would duplicate structured FAQ rendering and must fail QA.',
                ];
            }

            PersonalityPublicContentAsset::query()->create([
                'org_id' => 0,
                'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
                'entity_type' => $position === 1 ? PersonalityPublicContentAsset::ENTITY_HUB : PersonalityPublicContentAsset::ENTITY_DOMAIN,
                'entity_key' => 'qa-page-'.str_pad((string) $position, 2, '0', STR_PAD_LEFT),
                'slug' => 'qa-page-'.str_pad((string) $position, 2, '0', STR_PAD_LEFT),
                'locale' => $position <= 35 ? 'zh-CN' : 'en',
                'title' => 'Big Five QA Page '.$position,
                'summary' => 'Draft Big Five CMS preview/readback QA page.',
                'content_sections_json' => $sections,
                'seo_json' => [
                    'title' => 'Big Five QA Page '.$position,
                    'description' => 'Draft Big Five CMS preview/readback QA page.',
                ],
                'canonical_json' => [
                    'path' => ($position <= 35 ? '/zh' : '/en').'/personality/big-five/qa-page-'.$position,
                ],
                'hreflang_json' => [],
                'faq_json' => $this->faq(),
                'media_json' => [],
                'schema_json' => [
                    'recommendation' => 'FAQPage',
                    'draft_only' => true,
                    'runtime_jsonld_enabled' => false,
                ],
                'method_boundary_json' => [
                    'claim_boundaries' => ['non_diagnostic', 'non_predictive', 'no_hiring_screening'],
                    'method_boundary' => 'Big Five CMS draft content is non-diagnostic and non-predictive.',
                    'indexability_gate' => 'manual_review_required',
                ],
                'evidence_notes_json' => [[
                    'source_type' => 'cms_import_draft',
                    'source' => self::SOURCE_PACKAGE,
                    'package_sha256' => self::SOURCE_HASH,
                    'schema_runtime_release' => false,
                    'sitemap_release' => false,
                    'llms_release' => false,
                ]],
                'internal_links_json' => ['/zh/personality/big-five'],
                'is_public' => false,
                'index_eligible' => false,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'launch_state' => PersonalityPublicContentAsset::LAUNCH_REVIEW,
                'review_state' => 'cms_import_draft_pending_review',
                'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
                'source_package' => self::SOURCE_PACKAGE,
                'source_hash' => self::SOURCE_HASH,
                'published_at' => null,
                'last_reviewed_at' => null,
            ]);
        }
    }

    /**
     * @return list<array{question:string,answer:string}>
     */
    private function faq(): array
    {
        return [
            ['question' => 'What is this page?', 'answer' => 'A draft Big Five CMS preview/readback QA page.'],
            ['question' => 'Is it diagnostic?', 'answer' => 'No.'],
            ['question' => 'Is it for hiring?', 'answer' => 'No.'],
            ['question' => 'Is it public?', 'answer' => 'No, it remains draft review content.'],
            ['question' => 'Can it enter sitemap?', 'answer' => 'No, not before a separate reviewed indexability release.'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }
}
