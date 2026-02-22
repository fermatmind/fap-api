<?php

declare(strict_types=1);

namespace Tests\Feature\ClinicalCombo68;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClinicalComboQuestionsLocaleSplitTest extends TestCase
{
    use RefreshDatabase;

    public function test_questions_api_returns_68_items_for_zh_and_en(): void
    {
        $this->artisan('content:compile --pack=CLINICAL_COMBO_68 --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder())->run();

        $zh = $this->getJson('/api/v0.3/scales/CLINICAL_COMBO_68/questions?locale=zh-CN');
        $zh->assertStatus(200);
        $zh->assertJsonPath('meta.locale_requested', 'zh-CN');
        $zh->assertJsonPath('meta.locale_resolved', 'zh-CN');
        $this->assertCount(68, (array) data_get($zh->json(), 'questions.items', []));

        $en = $this->getJson('/api/v0.3/scales/CLINICAL_COMBO_68/questions?locale=en');
        $en->assertStatus(200);
        $en->assertJsonPath('meta.locale_requested', 'en');
        $en->assertJsonPath('meta.locale_resolved', 'en');
        $this->assertCount(68, (array) data_get($en->json(), 'questions.items', []));
    }
}

