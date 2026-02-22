<?php

declare(strict_types=1);

namespace Tests\Feature\Sds20;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class Sds20QuestionsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_questions_api_returns_bilingual_questions_options_and_meta(): void
    {
        (new ScaleRegistrySeeder)->run();

        $zh = $this->getJson('/api/v0.3/scales/SDS_20/questions?locale=zh-CN&region=CN_MAINLAND');
        $zh->assertStatus(200);
        $zh->assertJsonPath('scale_code', 'SDS_20');
        $zh->assertJsonPath('locale', 'zh-CN');
        $this->assertCount(20, (array) data_get($zh->json(), 'questions.items', []));
        $zh->assertJsonPath('questions.items.0.question_id', '1');
        $zh->assertJsonPath('questions.items.0.direction', 1);
        $zh->assertJsonPath('options.format.0', '没有或很少时间');
        $zh->assertJsonPath('meta.consent.required', true);
        $this->assertNotSame('', (string) data_get($zh->json(), 'meta.consent.version', ''));
        $this->assertNotSame('', (string) data_get($zh->json(), 'meta.consent.hash', ''));
        $this->assertNotEmpty((array) data_get($zh->json(), 'meta.source.items', []));

        $en = $this->getJson('/api/v0.3/scales/SDS_20/questions?locale=en&region=GLOBAL');
        $en->assertStatus(200);
        $en->assertJsonPath('scale_code', 'SDS_20');
        $en->assertJsonPath('locale', 'en');
        $this->assertCount(20, (array) data_get($en->json(), 'questions.items', []));
        $en->assertJsonPath('options.format.0', 'A little of the time');
        $en->assertJsonPath('meta.consent.required', true);
    }
}
