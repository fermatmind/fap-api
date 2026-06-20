<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class ScaleQuestionsResponseCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('content_packs.questions_response_cache_store', 'array');
        Config::set('content_packs.questions_response_cache_ttl_seconds', 600);
        Config::set('content_packs.questions_public_cache_max_age_seconds', 300);
        Config::set('content_packs.questions_public_cache_stale_while_revalidate_seconds', 600);
        Config::set('content_packs.loader_cache_store', 'array');
        Config::set('content_packs.loader_cache_ttl_seconds', 300);

        Cache::store('array')->flush();

        $this->artisan('migrate', ['--force' => true]);
        $this->artisan('fap:scales:seed-default');
        $this->artisan('fap:scales:sync-slugs');
    }

    public function test_riasec_questions_return_miss_then_hit_with_public_cache_headers(): void
    {
        $first = $this->getJson('/api/v0.3/scales/RIASEC/questions?locale=zh-CN&form_code=riasec_60');
        $first->assertStatus(200)
            ->assertHeader('X-FAP-Cache', 'miss')
            ->assertJsonPath('form_code', 'riasec_60');
        $this->assertStringContainsString('public', (string) $first->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=300', (string) $first->headers->get('Cache-Control'));
        $this->assertStringContainsString('stale-while-revalidate=600', (string) $first->headers->get('Cache-Control'));

        $second = $this->getJson('/api/v0.3/scales/RIASEC/questions?locale=zh-CN&form_code=riasec_60');
        $second->assertStatus(200)
            ->assertHeader('X-FAP-Cache', 'hit')
            ->assertJsonPath('form_code', 'riasec_60');

        $this->assertSame($first->json(), $second->json());
    }

    public function test_question_cache_isolated_by_scale_form_and_locale(): void
    {
        $riasec60 = $this->getJson('/api/v0.3/scales/RIASEC/questions?locale=en&form_code=riasec_60');
        $riasec60->assertStatus(200)
            ->assertHeader('X-FAP-Cache', 'miss')
            ->assertJsonPath('form_code', 'riasec_60');

        $riasec140 = $this->getJson('/api/v0.3/scales/RIASEC/questions?locale=en&form_code=riasec_140');
        $riasec140->assertStatus(200)
            ->assertHeader('X-FAP-Cache', 'miss')
            ->assertJsonPath('form_code', 'riasec_140');

        $bigFive = $this->getJson('/api/v0.3/scales/BIG5_OCEAN/questions?locale=en&form_code=big5_90');
        $bigFive->assertStatus(200)
            ->assertHeader('X-FAP-Cache', 'miss')
            ->assertJsonPath('form_code', 'big5_90');

        $riasec60Again = $this->getJson('/api/v0.3/scales/RIASEC/questions?locale=en&form_code=riasec_60');
        $riasec60Again->assertStatus(200)
            ->assertHeader('X-FAP-Cache', 'hit')
            ->assertJsonPath('form_code', 'riasec_60');
    }

    public function test_cached_public_question_payload_does_not_expose_answer_keys(): void
    {
        $response = $this->getJson('/api/v0.3/scales/ENNEAGRAM/questions?locale=zh-CN&form_code=enneagram_likert_105');
        $response->assertStatus(200)
            ->assertHeader('X-FAP-Cache', 'miss');

        $keys = $this->collectJsonKeys($response->json());

        $this->assertNotContains('answer_key', $keys);
        $this->assertNotContains('correct_answer', $keys);
        $this->assertNotContains('correct_option', $keys);
        $this->assertNotContains('scoring_key', $keys);
    }

    /**
     * @return list<string>
     */
    private function collectJsonKeys(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $keys = [];
        foreach ($value as $key => $child) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            array_push($keys, ...$this->collectJsonKeys($child));
        }

        return array_values(array_unique($keys));
    }
}
