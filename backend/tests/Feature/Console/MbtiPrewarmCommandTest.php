<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class MbtiPrewarmCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('content_packs.mbti_response_cache_store', 'array');
        Config::set('content_packs.mbti_lookup_cache_ttl_seconds', 600);
        Config::set('content_packs.mbti_questions_cache_ttl_seconds', 600);
        Config::set('content_packs.loader_cache_store', 'array');
        Config::set('content_packs.loader_cache_ttl_seconds', 300);

        Cache::store('array')->flush();

        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');
        $this->artisan('fap:scales:sync-slugs');
    }

    public function test_mbti_prewarm_command_warms_lookup_and_questions(): void
    {
        $this->artisan('mbti:prewarm')
            ->expectsOutputToContain('lookup locale=zh status=200')
            ->expectsOutputToContain('lookup locale=en status=200')
            ->expectsOutputToContain('questions locale=zh-CN form=mbti_93 status=200')
            ->expectsOutputToContain('questions locale=en form=mbti_144 status=200')
            ->expectsOutputToContain('MBTI prewarm completed successfully.')
            ->assertExitCode(0);

        $this->getJson('/api/v0.3/scales/lookup?slug=mbti-personality-test-16-personality-types&locale=zh')
            ->assertStatus(200)
            ->assertHeader('X-FAP-Cache', 'hit');

        $this->getJson('/api/v0.3/scales/MBTI/questions?locale=zh-CN&form_code=mbti_93')
            ->assertStatus(200)
            ->assertHeader('X-FAP-Cache', 'hit');

        $this->getJson('/api/v0.3/scales/MBTI/questions?locale=en&form_code=mbti_144')
            ->assertStatus(200)
            ->assertHeader('X-FAP-Cache', 'hit');
    }
}
