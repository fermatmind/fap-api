<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EnParity05CareerGuideDetailImportPackageTest extends TestCase
{
    #[Test]
    public function generated_import_package_records_career_guide_content_controls(): void
    {
        $path = base_path('docs/seo/generated/en-parity-05-career-guide-detail-import-package.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('en-parity-05-career-guide-detail-import-package.v1', $payload['schema_version'] ?? null);
        $this->assertSame('EN-PARITY-05', $payload['task'] ?? null);
        $this->assertFalse((bool) ($payload['frontend_fallback_authority_used'] ?? true));
        $this->assertFalse((bool) ($payload['cms_mutation_performed'] ?? true));
        $this->assertFalse((bool) ($payload['production_migration_performed'] ?? true));
        $this->assertFalse((bool) ($payload['deploy_performed'] ?? true));
        $this->assertFalse((bool) ($payload['search_channel_action_performed'] ?? true));
        $this->assertFalse((bool) ($payload['url_submission_performed'] ?? true));
        $this->assertFalse((bool) ($payload['auto_publish_performed'] ?? true));
        $this->assertFalse((bool) ($payload['mass_english_generation_performed'] ?? true));
        $this->assertFalse((bool) ($payload['substantial_english_prose_generated'] ?? true));
        $this->assertFalse((bool) ($payload['fap_web_modified'] ?? true));

        $this->assertSame(36, data_get($payload, 'current_baseline_summary.career_guides_en_count'));
        $this->assertSame(36, data_get($payload, 'current_baseline_summary.career_guides_zh_count'));
        $this->assertSame(0, data_get($payload, 'current_baseline_summary.missing_english_counterparts_count'));
        $this->assertSame(0, data_get($payload, 'current_baseline_summary.missing_chinese_counterparts_count'));
        $this->assertSame('guide_code', data_get($payload, 'current_baseline_summary.counterpart_key'));
        $this->assertFalse((bool) data_get($payload, 'current_baseline_summary.counterpart_lookup_uses_slug_guessing_only'));
        $this->assertFalse((bool) data_get($payload, 'import_controls.production_import_executed'));
        $this->assertFalse((bool) data_get($payload, 'import_controls.production_publish_executed'));
    }

    #[Test]
    public function career_guide_baselines_have_complete_en_zh_guide_code_parity(): void
    {
        $en = $this->baselineGuideCodes('content_baselines/career_guides/career_guides.en.json');
        $zh = $this->baselineGuideCodes('content_baselines/career_guides/career_guides.zh-CN.json');

        $this->assertCount(36, $en);
        $this->assertCount(36, $zh);
        $this->assertSame($zh, $en);
    }

    #[Test]
    public function generated_package_matches_baseline_guide_code_authority(): void
    {
        $payload = json_decode(
            (string) file_get_contents(base_path('docs/seo/generated/en-parity-05-career-guide-detail-import-package.v1.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(
            $this->baselineGuideCodes('content_baselines/career_guides/career_guides.en.json'),
            $payload['guide_codes_ready_for_controlled_review'] ?? [],
        );
        $this->assertFalse((bool) data_get($payload, 'translation_authority_contract.frontend_fallback_can_satisfy_counterpart'));
    }

    /**
     * @return list<string>
     */
    private function baselineGuideCodes(string $path): array
    {
        $payload = json_decode((string) file_get_contents(base_path('../'.$path)), true, 512, JSON_THROW_ON_ERROR);
        $codes = collect($payload['guides'] ?? [])
            ->pluck('guide_code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->sort()
            ->values()
            ->all();

        return $codes;
    }
}
