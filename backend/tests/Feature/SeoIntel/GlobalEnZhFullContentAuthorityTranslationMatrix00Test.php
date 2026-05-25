<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class GlobalEnZhFullContentAuthorityTranslationMatrix00Test extends TestCase
{
    #[Test]
    public function generated_translation_matrix_artifact_lands_expected_read_only_contract(): void
    {
        $path = base_path('docs/seo/generated/global-en-zh-full-content-authority-translation-matrix-00.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            'global-en-zh-full-content-authority-translation-matrix-00.v1',
            $payload['schema_version'] ?? null
        );
        $this->assertSame(
            'GLOBAL-EN-ZH-FULL-CONTENT-AUTHORITY-TRANSLATION-MATRIX-00',
            $payload['task'] ?? null
        );

        $this->assertSame([
            'content_help_policy_pages',
            'articles',
            'topics',
            'test_landing_pages',
            'research_pages',
            'career_content',
            'result_report_assets',
            'media_assets',
            'global_ui_i18n',
        ], $payload['scanned_asset_families'] ?? null);

        $this->assertIsArray($payload['full_translation_matrix'] ?? null);
        $this->assertNotEmpty($payload['full_translation_matrix']);
        $this->assertIsArray($payload['missing_en_counterparts'] ?? null);
        $this->assertIsArray($payload['recommended_pr_train'] ?? null);
        $this->assertNotEmpty($payload['recommended_pr_train']);

        $this->assertTrue($payload['no_cms_mutation'] ?? false);
        $this->assertTrue($payload['no_publish'] ?? false);
        $this->assertTrue($payload['no_deploy'] ?? false);
        $this->assertTrue($payload['no_search_channel_action'] ?? false);
        $this->assertTrue($payload['no_url_submission'] ?? false);
        $this->assertTrue($payload['no_pseo_generation'] ?? false);
        $this->assertTrue($payload['no_frontend_fallback_authority'] ?? false);

        $this->assertArrayHasKey('final_decision', $payload);
        $this->assertArrayHasKey('next_task', $payload);
    }
}
