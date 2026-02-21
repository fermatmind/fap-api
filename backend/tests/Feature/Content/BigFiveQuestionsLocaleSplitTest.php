<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BigFiveQuestionsLocaleSplitTest extends TestCase
{
    use RefreshDatabase;

    public function test_big5_questions_are_split_by_locale(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder())->run();

        $zh = $this->getJson('/api/v0.3/scales/BIG5_OCEAN/questions?locale=zh-CN');
        $zh->assertStatus(200);
        $zh->assertJsonPath('ok', true);
        $zh->assertJsonPath('locale', 'zh-CN');
        $zh->assertJsonPath('questions.items.0.text', '我经常感到焦虑或莫名担忧。');

        $en = $this->getJson('/api/v0.3/scales/BIG5_OCEAN/questions?locale=en');
        $en->assertStatus(200);
        $en->assertJsonPath('ok', true);
        $en->assertJsonPath('locale', 'en');
        $en->assertJsonPath('questions.items.0.text', 'I tend to worry a lot.');
    }
}

