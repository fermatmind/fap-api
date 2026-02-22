<?php

declare(strict_types=1);

namespace Tests\Feature\ClinicalCombo68;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClinicalComboQuestionsMetaComplianceTest extends TestCase
{
    use RefreshDatabase;

    public function test_questions_meta_contains_compliance_payloads_and_locale_fallback(): void
    {
        $this->artisan('content:compile --pack=CLINICAL_COMBO_68 --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder())->run();

        $zh = $this->getJson('/api/v0.3/scales/CLINICAL_COMBO_68/questions?locale=zh-CN&region=CN_MAINLAND');
        $zh->assertStatus(200);
        $zh->assertJsonPath('meta.locale_resolved', 'zh-CN');
        $zh->assertJsonPath('meta.consent.locale_resolved', 'zh-CN');
        $this->assertNotSame('', (string) data_get($zh->json(), 'meta.consent.version', ''));
        $this->assertNotEmpty((array) data_get($zh->json(), 'meta.privacy_addendum.bullets', []));
        $this->assertNotEmpty((array) data_get($zh->json(), 'meta.crisis_resources.resources', []));

        $fallback = $this->getJson('/api/v0.3/scales/CLINICAL_COMBO_68/questions?locale=fr&region=US');
        $fallback->assertStatus(200);
        $fallback->assertJsonPath('meta.locale_requested', 'fr');
        $fallback->assertJsonPath('meta.locale_resolved', 'zh-CN');
        $fallback->assertJsonPath('meta.crisis_resources.region_resolved', 'US');
    }
}
