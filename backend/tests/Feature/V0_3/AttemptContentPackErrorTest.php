<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\ScaleRegistry;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class AttemptContentPackErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_attempt_start_returns_500_with_content_pack_error_code_when_pack_resolution_fails(): void
    {
        (new ScaleRegistrySeeder())->run();

        $brokenPackId = 'PACK_DOES_NOT_EXIST';
        $brokenDirVersion = 'DIR_VERSION_DOES_NOT_EXIST';

        ScaleRegistry::query()
            ->where('org_id', 0)
            ->where('code', 'MBTI')
            ->update([
                'default_pack_id' => $brokenPackId,
                'default_dir_version' => $brokenDirVersion,
            ]);

        Cache::flush();
        Log::spy();

        $response = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'MBTI',
        ]);

        $response->assertStatus(500);
        $response->assertJsonPath('error_code', 'CONTENT_PACK_ERROR');

        Log::shouldHaveReceived('error')
            ->atLeast()
            ->once()
            ->withArgs(function ($message, $context) use ($brokenPackId, $brokenDirVersion): bool {
                $this->assertIsString($message);
                $this->assertIsArray($context);
                if (!str_starts_with($message, 'QUESTIONS_')) {
                    return false;
                }
                $this->assertSame($brokenPackId, $context['pack_id'] ?? null);
                $this->assertSame($brokenDirVersion, $context['dir_version'] ?? null);
                $this->assertArrayHasKey('questions_path', $context);
                $this->assertNotSame('', (string) ($context['exception_message'] ?? ''));

                return true;
            });
    }
}
