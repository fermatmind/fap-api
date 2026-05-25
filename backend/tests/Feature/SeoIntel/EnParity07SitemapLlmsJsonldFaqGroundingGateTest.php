<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EnParity07SitemapLlmsJsonldFaqGroundingGateTest extends TestCase
{
    #[Test]
    public function generated_gate_records_no_mutation_and_authority_boundaries(): void
    {
        $payload = $this->payload();

        $this->assertSame('en-parity-07-sitemap-llms-jsonld-faq-grounding-gate.v1', $payload['schema_version'] ?? null);
        $this->assertSame('EN-PARITY-07', $payload['task'] ?? null);
        $this->assertSame('backend_cms_url_truth_translation_media_authority', $payload['source_authority'] ?? null);
        $this->assertFalse((bool) ($payload['frontend_fallback_authority_used'] ?? true));
        $this->assertFalse((bool) ($payload['cms_mutation_performed'] ?? true));
        $this->assertFalse((bool) ($payload['production_migration_performed'] ?? true));
        $this->assertFalse((bool) ($payload['deploy_performed'] ?? true));
        $this->assertFalse((bool) ($payload['search_channel_action_performed'] ?? true));
        $this->assertFalse((bool) ($payload['url_submission_performed'] ?? true));
        $this->assertFalse((bool) ($payload['sitemap_mutation_performed'] ?? true));
        $this->assertFalse((bool) ($payload['llms_mutation_performed'] ?? true));
        $this->assertFalse((bool) ($payload['jsonld_runtime_mutation_performed'] ?? true));
        $this->assertFalse((bool) ($payload['faq_runtime_mutation_performed'] ?? true));
    }

    #[Test]
    public function sitemap_and_llms_rules_block_invalid_public_surfaces(): void
    {
        $payload = $this->payload();

        foreach (['sitemap', 'llms'] as $surface) {
            $rules = data_get($payload, 'surface_gate_rules.'.$surface);
            $this->assertTrue((bool) ($rules['exclude_404'] ?? false), $surface);
            $this->assertTrue((bool) ($rules['exclude_soft_404'] ?? false), $surface);
            $this->assertTrue((bool) ($rules['exclude_noindex'] ?? false), $surface);
            $this->assertTrue((bool) ($rules['exclude_private'] ?? false), $surface);
            $this->assertTrue((bool) ($rules['exclude_frontend_fallback_only'] ?? false), $surface);
            $this->assertTrue((bool) ($rules['exclude_draft_placeholder_or_missing_authority'] ?? false), $surface);
            $this->assertSame('backend_url_truth_published_indexable', $rules['authority_required'] ?? null);
        }

        foreach ([
            '404',
            'soft_404',
            'noindex',
            'private',
            'draft',
            'placeholder',
            'fallback_only',
            'missing_authority',
            'stale_slug',
            'staging_host',
            'www_host_canonical',
        ] as $blockedClass) {
            $this->assertContains($blockedClass, $payload['invalid_url_classes_blocked'] ?? []);
        }
    }

    #[Test]
    public function canonical_hreflang_jsonld_and_faq_rules_fail_closed(): void
    {
        $payload = $this->payload();

        $this->assertTrue((bool) data_get($payload, 'surface_gate_rules.canonical_hreflang.hreflang_targets_must_be_200_canonical_counterparts'));
        $this->assertTrue((bool) data_get($payload, 'surface_gate_rules.canonical_hreflang.counterpart_lookup_uses_translation_group_or_authority_key'));
        $this->assertFalse((bool) data_get($payload, 'surface_gate_rules.canonical_hreflang.slug_guessing_only_allowed', true));
        $this->assertFalse((bool) data_get($payload, 'surface_gate_rules.canonical_hreflang.www_host_canonical_allowed', true));
        $this->assertFalse((bool) data_get($payload, 'surface_gate_rules.canonical_hreflang.staging_host_canonical_allowed', true));

        $this->assertTrue((bool) data_get($payload, 'surface_gate_rules.json_ld.article_schema_requires_article_authority'));
        $this->assertTrue((bool) data_get($payload, 'surface_gate_rules.json_ld.dataset_schema_requires_research_asset_authority'));
        $this->assertTrue((bool) data_get($payload, 'surface_gate_rules.json_ld.faq_schema_requires_visible_or_authority_backed_faq'));
        $this->assertTrue((bool) data_get($payload, 'surface_gate_rules.json_ld.image_requires_authority_backed_media_metadata'));

        $this->assertFalse((bool) data_get($payload, 'surface_gate_rules.faq_grounding.sr_only_faq_as_sole_grounding_allowed', true));
        $this->assertFalse((bool) data_get($payload, 'surface_gate_rules.faq_grounding.frontend_fallback_faq_allowed', true));
        $this->assertTrue((bool) data_get($payload, 'surface_gate_rules.faq_grounding.questions_answers_must_be_visible_or_backend_authority_backed'));
    }

    #[Test]
    public function gate_inputs_and_deferred_frontend_runtime_contract_are_explicit(): void
    {
        $payload = $this->payload();
        $inputs = $payload['gate_inputs'] ?? [];

        foreach ([
            'backend/docs/seo/generated/en-parity-00-full-site-bilingual-inventory.v1.json',
            'backend/docs/seo/generated/en-parity-01-url-truth-canonical-baseline.v1.json',
            'backend/docs/seo/generated/en-parity-02-translation-group-read-model.v1.json',
            'backend/docs/seo/generated/en-parity-06-media-assets-parity-inventory.v1.json',
        ] as $requiredInput) {
            $this->assertContains($requiredInput, $inputs);
            $this->assertFileExists(base_path(str_replace('backend/', '', $requiredInput)));
        }

        $deferred = collect($payload['deferred_items'] ?? [])->pluck('id')->all();
        $this->assertContains('en_parity_07_frontend_runtime_contract', $deferred);
        $this->assertContains('en_parity_07_live_surface_recheck', $deferred);
        $this->assertFalse((bool) data_get($payload, 'en_zh_surface_parity_summary.runtime_public_surface_reverified_in_this_pr', true));
        $this->assertFalse((bool) data_get($payload, 'en_zh_surface_parity_summary.frontend_rendering_changed_in_this_pr', true));
        $this->assertSame('EN-PARITY-08 Chrome Playwright bilingual visual parity pass', $payload['next_task'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $path = base_path('docs/seo/generated/en-parity-07-sitemap-llms-jsonld-faq-grounding-gate.v1.json');
        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
