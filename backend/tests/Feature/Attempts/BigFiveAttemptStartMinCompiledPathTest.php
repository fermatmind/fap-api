<?php

declare(strict_types=1);

namespace Tests\Feature\Attempts;

use App\Services\Content\BigFivePackLoader;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class BigFiveAttemptStartMinCompiledPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_big5_start_uses_min_compiled_question_index_when_full_questions_payload_is_missing(): void
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
            $response = $this->postJson('/api/v0.3/attempts/start', [
                'scale_code' => 'BIG5_OCEAN',
                'region' => 'CN_MAINLAND',
                'locale' => 'zh-CN',
                'anon_id' => 'anon_big5_min_path',
            ]);

            $response->assertStatus(200);
            $response->assertJsonPath('ok', true);
            $response->assertJsonPath('scale_code', 'BIG5_OCEAN');
            $response->assertJsonPath('question_count', 120);
        } finally {
            if (File::exists($backupPath)) {
                File::move($backupPath, $fullPath);
            }
        }
    }
}

