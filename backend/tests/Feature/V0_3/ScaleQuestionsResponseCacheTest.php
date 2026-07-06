<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\Auth\FmTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
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

    public function test_tenant_registry_and_question_cache_responses_are_private_no_store(): void
    {
        [$orgId, $token] = $this->createTenantMemberToken();
        $this->seedTenantRiasecRegistry($orgId);

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'X-Org-Id' => (string) $orgId,
        ];

        $registry = $this->withHeaders($headers)->getJson('/api/v0.3/scales');
        $registry->assertStatus(200)
            ->assertJson(['ok' => true]);
        $this->assertPrivateNoStore($registry->headers->get('Cache-Control'));

        $first = $this->withHeaders($headers)
            ->getJson('/api/v0.3/scales/RIASEC/questions?locale=zh-CN&form_code=riasec_60');
        $first->assertStatus(200)
            ->assertHeader('X-FAP-Cache', 'miss')
            ->assertJsonPath('form_code', 'riasec_60');
        $this->assertPrivateNoStore($first->headers->get('Cache-Control'));

        $second = $this->withHeaders($headers)
            ->getJson('/api/v0.3/scales/RIASEC/questions?locale=zh-CN&form_code=riasec_60');
        $second->assertStatus(200)
            ->assertHeader('X-FAP-Cache', 'hit')
            ->assertJsonPath('form_code', 'riasec_60');
        $this->assertPrivateNoStore($second->headers->get('Cache-Control'));
    }

    /**
     * @return array{0:int,1:string}
     */
    private function createTenantMemberToken(): array
    {
        $now = now();
        $userId = DB::table('users')->insertGetId([
            'name' => 'Tenant Cache User',
            'email' => 'tenant-cache@example.com',
            'password' => bcrypt('secret'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $orgId = DB::table('organizations')->insertGetId([
            'name' => 'Tenant Cache Org',
            'owner_user_id' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('organization_members')->insert([
            'org_id' => $orgId,
            'user_id' => $userId,
            'role' => 'member',
            'joined_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $issued = app(FmTokenService::class)->issueForUser((string) $userId, [
            'org_id' => $orgId,
            'role' => 'member',
        ]);

        return [$orgId, (string) ($issued['token'] ?? '')];
    }

    private function seedTenantRiasecRegistry(int $orgId): void
    {
        DB::table('scales_registry_v2')->updateOrInsert(
            ['org_id' => $orgId, 'code' => 'RIASEC'],
            [
                'primary_slug' => 'tenant-riasec',
                'slugs_json' => json_encode(['tenant-riasec'], JSON_THROW_ON_ERROR),
                'driver_type' => 'riasec',
                'assessment_driver' => 'riasec',
                'default_pack_id' => 'RIASEC',
                'default_region' => 'CN_MAINLAND',
                'default_locale' => 'zh-CN',
                'default_dir_version' => 'v1-standard-60',
                'capabilities_json' => json_encode(['questions' => true], JSON_THROW_ON_ERROR),
                'view_policy_json' => json_encode([], JSON_THROW_ON_ERROR),
                'commercial_json' => json_encode([], JSON_THROW_ON_ERROR),
                'seo_schema_json' => json_encode([], JSON_THROW_ON_ERROR),
                'is_public' => false,
                'is_active' => true,
                'is_indexable' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function assertPrivateNoStore(?string $cacheControl): void
    {
        $value = (string) $cacheControl;

        $this->assertStringContainsString('private', $value);
        $this->assertStringContainsString('no-store', $value);
        $this->assertStringNotContainsString('public', $value);
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
