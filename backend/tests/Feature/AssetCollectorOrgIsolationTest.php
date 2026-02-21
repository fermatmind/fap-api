<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attempt;
use App\Services\Account\AssetCollector;
use App\Support\OrgContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AssetCollectorOrgIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_append_by_anon_id_updates_only_current_org(): void
    {
        $anonId = 'anon_shared';

        $attemptOrg1 = $this->seedAttempt(orgId: 1, anonId: $anonId);
        $attemptOrg2 = $this->seedAttempt(orgId: 2, anonId: $anonId);

        $orgContext = app(OrgContext::class);
        $orgContext->set(1, null, 'public');
        app()->instance(OrgContext::class, $orgContext);

        $result = app(AssetCollector::class)->appendByAnonId('u_1', $anonId);

        $this->assertSame(1, (int) ($result['updated'] ?? 0));
        $this->assertSame('u_1', (string) Attempt::query()->where('id', $attemptOrg1)->value('user_id'));
        $this->assertNull(Attempt::query()->where('id', $attemptOrg2)->value('user_id'));
    }

    public function test_append_by_device_key_hash_updates_only_current_org(): void
    {
        if (!Schema::hasColumn('attempts', 'device_key_hash')) {
            Schema::table('attempts', static function (Blueprint $table): void {
                $table->string('device_key_hash', 191)->nullable()->index();
            });
        }

        $deviceKeyHash = 'dk_shared_hash';
        $attemptOrg1 = $this->seedAttempt(orgId: 1, anonId: 'anon_org_1');
        $attemptOrg2 = $this->seedAttempt(orgId: 2, anonId: 'anon_org_2');

        DB::table('attempts')->where('id', $attemptOrg1)->update(['device_key_hash' => $deviceKeyHash]);
        DB::table('attempts')->where('id', $attemptOrg2)->update(['device_key_hash' => $deviceKeyHash]);

        $orgContext = app(OrgContext::class);
        $orgContext->set(1, null, 'public');
        app()->instance(OrgContext::class, $orgContext);

        $result = app(AssetCollector::class)->appendByDeviceKeyHash('u_1', $deviceKeyHash);

        $this->assertSame(1, (int) ($result['updated'] ?? 0));
        $this->assertSame('u_1', (string) Attempt::query()->where('id', $attemptOrg1)->value('user_id'));
        $this->assertNull(Attempt::query()->where('id', $attemptOrg2)->value('user_id'));
    }

    private function seedAttempt(int $orgId, string $anonId): string
    {
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'org_id' => $orgId,
            'anon_id' => $anonId,
            'user_id' => null,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.2',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'answers_summary_json' => ['seed' => true],
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'channel' => 'test',
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3'),
            'dir_version' => (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3'),
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
        ]);

        return $attemptId;
    }
}
