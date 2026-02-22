<?php

declare(strict_types=1);

namespace Tests\Feature\ClinicalCombo68;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClinicalComboQuestionsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_questions_api_returns_clinical_contract_fields(): void
    {
        $this->artisan('content:compile --pack=CLINICAL_COMBO_68 --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder)->run();

        $response = $this->getJson('/api/v0.3/scales/CLINICAL_COMBO_68/questions?locale=zh-CN&region=CN_MAINLAND');
        $response->assertStatus(200);
        $response->assertJsonPath('scale_code', 'CLINICAL_COMBO_68');
        $response->assertJsonPath('meta.locale_resolved', 'zh-CN');
        $this->assertCount(68, (array) data_get($response->json(), 'questions.items', []));
        $this->assertNotSame('', (string) data_get($response->json(), 'meta.consent.version', ''));
        $this->assertNotSame('', (string) data_get($response->json(), 'meta.consent.hash', ''));
    }
}
