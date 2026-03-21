<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use App\Models\Share;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ShareClickAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_click_accepts_legacy_body_and_returns_lightweight_response(): void
    {
        $share = $this->createShareFixture('legacy_click_owner');

        $response = $this->postJson("/api/v0.3/shares/{$share->id}/click", [
            'anon_id' => 'legacy_click_probe',
            'meta_json' => [
                'legacy_marker' => 'keep_me',
                'utm' => [
                    'source' => 'legacy-source',
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('share_id', (string) $share->id)
            ->assertJsonMissingPath('report')
            ->assertJsonMissingPath('result');

        $event = DB::table('events')
            ->where('event_code', 'share_click')
            ->where('share_id', (string) $share->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($event);

        $meta = json_decode((string) ($event->meta_json ?? '{}'), true);
        $this->assertSame('legacy_click_probe', (string) $event->anon_id);
        $this->assertSame('keep_me', data_get($meta, 'legacy_marker'));
        $this->assertSame('legacy-source', data_get($meta, 'utm.source'));
        $this->assertSame((string) $share->attempt_id, data_get($meta, 'attempt_id'));
    }

    public function test_click_persists_new_attribution_meta_without_share_generate_dependency(): void
    {
        $share = $this->createShareFixture('scan_owner_anon');

        $response = $this->withHeaders([
            'Referer' => 'https://ref.example/share',
        ])->postJson("/api/v0.3/shares/{$share->id}/click", [
            'anon_id' => 'scan_probe',
            'meta' => [
                'entrypoint' => 'share_page',
                'utm' => [
                    'source' => 'share',
                    'medium' => 'organic',
                    'campaign' => 'pr06',
                    'term' => 'mbti',
                    'content' => 'hero',
                ],
                'landing_path' => '/zh/share/'.(string) $share->id,
                'compare_intent' => false,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('share_id', (string) $share->id)
            ->assertJsonMissingPath('report')
            ->assertJsonMissingPath('result');

        $event = DB::table('events')
            ->where('event_code', 'share_click')
            ->where('share_id', (string) $share->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($event);
        $meta = json_decode((string) ($event->meta_json ?? '{}'), true);

        $this->assertSame('scan_probe', (string) $event->anon_id);
        $this->assertSame('share_page', data_get($meta, 'entrypoint'));
        $this->assertSame('share', data_get($meta, 'utm.source'));
        $this->assertSame('organic', data_get($meta, 'utm.medium'));
        $this->assertSame('pr06', data_get($meta, 'utm.campaign'));
        $this->assertSame('mbti', data_get($meta, 'utm.term'));
        $this->assertSame('hero', data_get($meta, 'utm.content'));
        $this->assertSame('https://ref.example/share', data_get($meta, 'referrer'));
        $this->assertSame('/zh/share/'.(string) $share->id, data_get($meta, 'landing_path'));
        $this->assertFalse((bool) data_get($meta, 'compare_intent'));
        $this->assertSame('INTJ-A', data_get($meta, 'type_code'));
        $this->assertSame((string) $share->attempt_id, data_get($meta, 'attempt_id'));
    }

    public function test_click_accepts_flat_utm_fields_and_normalizes_them_into_nested_utm_meta(): void
    {
        $share = $this->createShareFixture('flat_utm_owner');

        $response = $this->postJson("/api/v0.3/shares/{$share->id}/click", [
            'anon_id' => 'flat_utm_probe',
            'utm_source' => 'share',
            'utm_medium' => 'organic',
            'utm_campaign' => 'pr07a',
            'utm_term' => 'mbti_compare',
            'utm_content' => 'invite_card',
            'entrypoint' => 'share_page',
            'landing_path' => '/zh/share/'.(string) $share->id,
            'share_click_id' => 'clk_flat_001',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('share_id', (string) $share->id);

        $event = DB::table('events')
            ->where('event_code', 'share_click')
            ->where('share_id', (string) $share->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($event);
        $meta = json_decode((string) ($event->meta_json ?? '{}'), true);

        $this->assertSame('share', data_get($meta, 'utm.source'));
        $this->assertSame('organic', data_get($meta, 'utm.medium'));
        $this->assertSame('pr07a', data_get($meta, 'utm.campaign'));
        $this->assertSame('mbti_compare', data_get($meta, 'utm.term'));
        $this->assertSame('invite_card', data_get($meta, 'utm.content'));
        $this->assertSame('clk_flat_001', data_get($meta, 'share_click_id'));
    }

    private function createShareFixture(string $ownerAnonId): Share
    {
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $ownerAnonId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3'),
            'dir_version' => (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3'),
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => ['total' => 100],
            'scores_pct' => [
                'EI' => 35,
                'SN' => 72,
                'TF' => 68,
                'JP' => 63,
                'AT' => 58,
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'clear',
                'JP' => 'moderate',
                'AT' => 'moderate',
            ],
            'profile_version' => 'mbti32-v2.5',
            'content_package_version' => 'v0.3',
            'result_json' => ['type_code' => 'INTJ-A'],
            'pack_id' => (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3'),
            'dir_version' => (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3'),
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        $share = new Share;
        $share->id = bin2hex(random_bytes(16));
        $share->attempt_id = $attemptId;
        $share->anon_id = $ownerAnonId;
        $share->scale_code = 'MBTI';
        $share->scale_version = 'v0.3';
        $share->content_package_version = 'v0.3';
        $share->save();

        return $share;
    }
}
