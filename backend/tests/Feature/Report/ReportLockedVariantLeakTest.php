<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Attempt;
use App\Models\Result;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReportLockedVariantLeakTest extends TestCase
{
    use RefreshDatabase;

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder)->run();
        (new Pr19CommerceSeeder)->run();
    }

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();

        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function createAttemptWithResult(string $anonId): string
    {
        $attemptId = (string) Str::uuid();
        $packId = (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3');
        $dirVersion = (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3');

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_code_v2' => 'MBTI_PERSONALITY_TEST_16_TYPES',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_code_v2' => 'MBTI_PERSONALITY_TEST_16_TYPES',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => [
                'EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'SN' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'TF' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'JP' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'AT' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
            ],
            'scores_pct' => ['EI' => 50, 'SN' => 50, 'TF' => 50, 'JP' => 50, 'AT' => 50],
            'axis_states' => ['EI' => 'clear', 'SN' => 'clear', 'TF' => 'clear', 'JP' => 'clear', 'AT' => 'clear'],
            'content_package_version' => 'v0.3',
            'result_json' => ['type_code' => 'INTJ-A'],
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }

    public function test_locked_variant_does_not_leak_paid_cards(): void
    {
        $this->seedScales();

        $anonId = 'anon_locked_leak';
        $token = $this->issueAnonToken($anonId);
        $attemptId = $this->createAttemptWithResult($anonId);

        $resp = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $resp->assertStatus(200);
        $resp->assertJson([
            'ok' => true,
            'locked' => true,
            'variant' => 'free',
            'access_level' => 'free',
        ]);

        $this->assertNotEmpty((array) $resp->json('report.profile'));
        $this->assertNotEmpty((array) $resp->json('report.identity_card'));
        $this->assertNotEmpty((array) $resp->json('report.highlights'));
        $this->assertIsArray($resp->json('report.recommended_reads'));
        $this->assertIsArray($resp->json('cta'));
        $this->assertTrue((bool) $resp->json('cta.visible'));
        $this->assertSame('upsell', $resp->json('cta.kind'));
        $this->assertNotEmpty((array) $resp->json('cta.benefit_bullets'));
        $identityLayer = (array) $resp->json('report.layers.identity');
        $this->assertNotEmpty($identityLayer);
        $this->assertNotContains('fallback:true', (array) ($identityLayer['tags'] ?? []));
        foreach (['traits', 'growth', 'career', 'relationships'] as $section) {
            $cards = (array) $resp->json("report.sections.{$section}.cards");
            $this->assertNotEmpty($cards);
            $this->assertTrue($this->hasRicherCopy($cards), "Section {$section} should keep richer teaser copy");
        }

        foreach ((array) $resp->json('report.recommended_reads') as $item) {
            $this->assertIsArray($item);
            $this->assertArrayNotHasKey('access_level', $item);
            $this->assertArrayNotHasKey('module_code', $item);
            $this->assertArrayNotHasKey('locked', $item);
        }

        $this->assertArrayNotHasKey('access_level', $identityLayer);
        $this->assertArrayNotHasKey('module_code', $identityLayer);

        $levels = $this->collectAccessLevels($resp->json('report'));
        $this->assertNotEmpty($levels);
        $this->assertNotContains('paid', $levels);
    }

    /**
     * @param  array<int, mixed>  $cards
     */
    private function hasRicherCopy(array $cards): bool
    {
        foreach ($cards as $card) {
            if (! is_array($card)) {
                continue;
            }

            $title = trim((string) ($card['title'] ?? ''));
            $desc = trim((string) ($card['desc'] ?? $card['text'] ?? ''));

            if ($title !== '' && $desc !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function collectAccessLevels(mixed $node): array
    {
        $levels = [];

        $walk = function (mixed $value) use (&$walk, &$levels): void {
            if (is_array($value)) {
                if (array_key_exists('access_level', $value) && is_string($value['access_level'])) {
                    $levels[] = strtolower((string) $value['access_level']);
                }
                foreach ($value as $child) {
                    $walk($child);
                }
            }
        };

        $walk($node);

        return array_values(array_unique($levels));
    }
}
