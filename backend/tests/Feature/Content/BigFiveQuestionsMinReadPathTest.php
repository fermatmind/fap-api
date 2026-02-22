<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\BigFivePackLoader;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class BigFiveQuestionsMinReadPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_big5_questions_endpoint_uses_min_compiled_payload_when_full_questions_file_is_missing(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder())->run();

        /** @var BigFivePackLoader $loader */
        $loader = app(BigFivePackLoader::class);
        $fullPath = $loader->compiledPath('questions.compiled.json', 'v1');
        $backupPath = $fullPath.'.bak_test';

        $this->assertFileExists($fullPath);
        $this->assertFileExists($loader->compiledPath('questions.min.compiled.json', 'v1'));

        File::move($fullPath, $backupPath);

        try {
            $zh = $this->getJson('/api/v0.3/scales/BIG5_OCEAN/questions?locale=zh-CN');
            $zh->assertStatus(200);
            $zh->assertJsonPath('ok', true);
            $zh->assertJsonPath('questions.schema', 'fap.questions.v1');
            $this->assertCount(120, (array) $zh->json('questions.items'));
            $zh->assertJsonPath('questions.items.0.text', '我经常感到焦虑或莫名担忧。');
            $zh->assertJsonPath('questions.items.0.options.0.text', '非常不同意');

            $en = $this->getJson('/api/v0.3/scales/BIG5_OCEAN/questions?locale=en');
            $en->assertStatus(200);
            $en->assertJsonPath('ok', true);
            $en->assertJsonPath('questions.schema', 'fap.questions.v1');
            $this->assertCount(120, (array) $en->json('questions.items'));
            $en->assertJsonPath('questions.items.0.text', 'I tend to worry a lot.');
            $en->assertJsonPath('questions.items.0.options.0.text', 'Strongly Disagree');
            $en->assertJsonPath('questions.items.0.options.0.text_zh', '非常不同意');
        } finally {
            if (File::exists($backupPath)) {
                File::move($backupPath, $fullPath);
            }
        }
    }
}
