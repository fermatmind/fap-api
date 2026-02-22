<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Services\Auth\FmTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BigFiveOpsWriteEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private const DIR_ALIAS = 'BIG5-OCEAN-OPS-WRITE-CI-TEST';

    protected function tearDown(): void
    {
        $target = base_path('../content_packages/default/CN_MAINLAND/zh-CN/'.self::DIR_ALIAS);
        if (File::isDirectory($target)) {
            File::deleteDirectory($target);
        }

        parent::tearDown();
    }

    public function test_owner_can_publish_and_rollback_via_ops_write_endpoints(): void
    {
        $owner = $this->createUserWithToken('ops-write-owner@big5.test');
        $orgId = $this->createOrgForToken($owner['token']);

        $target = base_path('../content_packages/default/CN_MAINLAND/zh-CN/'.self::DIR_ALIAS);
        if (File::isDirectory($target)) {
            File::deleteDirectory($target);
        }

        $headers = [
            'Authorization' => 'Bearer '.$owner['token'],
            'X-Org-Id' => (string) $orgId,
        ];

        $first = $this->withHeaders($headers)->postJson('/api/v0.3/orgs/'.$orgId.'/big5/releases/publish', [
            'pack' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => self::DIR_ALIAS,
            'probe' => false,
            'skip_drift' => true,
        ]);
        $first->assertStatus(200);
        $first->assertJsonPath('ok', true);
        $first->assertJsonPath('action', 'publish');
        $first->assertJsonPath('release.action', 'publish');

        $second = $this->withHeaders($headers)->postJson('/api/v0.3/orgs/'.$orgId.'/big5/releases/publish', [
            'pack' => 'BIG5_OCEAN',
            'pack_version' => 'v1',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => self::DIR_ALIAS,
            'probe' => false,
            'skip_drift' => true,
        ]);
        $second->assertStatus(200);
        $second->assertJsonPath('ok', true);

        $targetReleaseId = '';
        $publishRows = DB::table('content_pack_releases')
            ->where('action', 'publish')
            ->where('status', 'success')
            ->where('dir_alias', self::DIR_ALIAS)
            ->orderByDesc('created_at')
            ->orderByDesc('updated_at')
            ->get();
        foreach ($publishRows as $row) {
            $backupPath = storage_path('app/private/content_releases/backups/'.(string) $row->id.'/previous_pack');
            if (! File::isDirectory($backupPath)) {
                continue;
            }
            $targetReleaseId = (string) $row->id;
            break;
        }
        $this->assertNotSame('', $targetReleaseId);

        $rollback = $this->withHeaders($headers)->postJson('/api/v0.3/orgs/'.$orgId.'/big5/releases/rollback', [
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => self::DIR_ALIAS,
            'to_release_id' => $targetReleaseId,
            'probe' => false,
        ]);
        $rollback->assertStatus(200);
        $rollback->assertJsonPath('ok', true);
        $rollback->assertJsonPath('action', 'rollback');
        $rollback->assertJsonPath('release.action', 'rollback');

        $rollbackReleaseId = (string) ($rollback->json('release.release_id') ?? '');
        $this->assertNotSame('', $rollbackReleaseId);

        $publishAudit = DB::table('audit_logs')
            ->where('action', 'big5_pack_publish')
            ->where('target_id', $targetReleaseId)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($publishAudit);
        $this->assertSame('success', (string) ($publishAudit->result ?? ''));

        $rollbackAudit = DB::table('audit_logs')
            ->where('action', 'big5_pack_rollback')
            ->where('target_id', $rollbackReleaseId)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($rollbackAudit);
        $this->assertSame('success', (string) ($rollbackAudit->result ?? ''));
    }

    public function test_owner_can_run_norms_write_operations_via_ops_endpoints(): void
    {
        $owner = $this->createUserWithToken('ops-norms-owner@big5.test');
        $orgId = $this->createOrgForToken($owner['token']);

        $this->insertAttemptResult('zh-CN', 'CN_MAINLAND', 'ops_norms_anon_a', 3.4, 3.6, 3.1, 3.3, 3.0, 'A');

        $headers = [
            'Authorization' => 'Bearer '.$owner['token'],
            'X-Org-Id' => (string) $orgId,
        ];

        $versionV1 = 'OPS_WRITE_V1';
        $groupId = 'zh-CN_prod_all_18-60';

        $rebuild = $this->withHeaders($headers)->postJson('/api/v0.3/orgs/'.$orgId.'/big5/norms/rebuild', [
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'group' => 'prod_all_18-60',
            'window_days' => 365,
            'min_samples' => 1,
            'only_quality' => 'AB',
            'norms_version' => $versionV1,
            'activate' => true,
            'dry_run' => false,
        ]);
        $rebuild->assertStatus(200);
        $rebuild->assertJsonPath('ok', true);
        $rebuild->assertJsonPath('action', 'norms_rebuild');
        $rebuild->assertJsonPath('item.group_id', $groupId);
        $rebuild->assertJsonPath('item.norms_version', $versionV1);

        $drift = $this->withHeaders($headers)->postJson('/api/v0.3/orgs/'.$orgId.'/big5/norms/drift-check', [
            'from' => $versionV1,
            'to' => $versionV1,
            'group_id' => $groupId,
            'threshold_mean' => 0.35,
            'threshold_sd' => 0.35,
        ]);
        $drift->assertStatus(200);
        $drift->assertJsonPath('ok', true);
        $drift->assertJsonPath('action', 'norms_drift_check');

        $this->seedNormVersionAndStats($groupId, 'OPS_WRITE_V2', false);
        $activate = $this->withHeaders($headers)->postJson('/api/v0.3/orgs/'.$orgId.'/big5/norms/activate', [
            'group_id' => $groupId,
            'norms_version' => 'OPS_WRITE_V2',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
        ]);
        $activate->assertStatus(200);
        $activate->assertJsonPath('ok', true);
        $activate->assertJsonPath('action', 'norms_activate');
        $activate->assertJsonPath('item.group_id', $groupId);
        $activate->assertJsonPath('item.norms_version', 'OPS_WRITE_V2');
        $activate->assertJsonPath('item.is_active', true);

        $activeVersion = DB::table('scale_norms_versions')
            ->where('scale_code', 'BIG5_OCEAN')
            ->where('group_id', $groupId)
            ->where('is_active', 1)
            ->orderByDesc('created_at')
            ->first();
        $this->assertNotNull($activeVersion);
        $this->assertSame('OPS_WRITE_V2', (string) ($activeVersion->version ?? ''));

        $rebuildAudit = DB::table('audit_logs')
            ->where('action', 'big5_norms_rebuild')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($rebuildAudit);
        $this->assertSame('success', (string) ($rebuildAudit->result ?? ''));

        $driftAudit = DB::table('audit_logs')
            ->where('action', 'big5_norms_drift_check')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($driftAudit);
        $this->assertSame('success', (string) ($driftAudit->result ?? ''));

        $activateAudit = DB::table('audit_logs')
            ->where('action', 'big5_norms_activate')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($activateAudit);
        $this->assertSame('success', (string) ($activateAudit->result ?? ''));
    }

    /**
     * @return array{user_id:int,token:string}
     */
    private function createUserWithToken(string $email): array
    {
        $now = now();
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'User '.$email,
            'email' => $email,
            'password' => bcrypt('secret'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $issued = app(FmTokenService::class)->issueForUser((string) $userId);

        return [
            'user_id' => $userId,
            'token' => (string) ($issued['token'] ?? ''),
        ];
    }

    private function createOrgForToken(string $token): int
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Org-Id' => '0',
        ])->postJson('/api/v0.3/orgs', [
            'name' => 'BIG5 Ops Write Org '.Str::random(6),
        ]);

        $response->assertStatus(200);

        return (int) ($response->json('org.org_id') ?? 0);
    }

    private function insertAttemptResult(
        string $locale,
        string $region,
        string $anonId,
        float $o,
        float $c,
        float $e,
        float $a,
        float $n,
        string $qualityLevel
    ): void {
        $attemptId = (string) Str::uuid();
        $resultId = (string) Str::uuid();

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'anon_id' => $anonId,
            'user_id' => null,
            'org_id' => 0,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v1',
            'question_count' => 120,
            'answers_summary_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE),
            'client_platform' => 'test',
            'client_version' => '1.0',
            'channel' => 'ci',
            'referrer' => 'unit',
            'region' => $region,
            'locale' => $locale,
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
            'started_at' => now()->subMinutes(5),
            'submitted_at' => now()->subMinutes(1),
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(1),
        ]);

        $facets = $this->facetMeans($o, $c, $e, $a, $n);

        DB::table('results')->insert([
            'id' => $resultId,
            'attempt_id' => $attemptId,
            'org_id' => 0,
            'scale_code' => 'BIG5_OCEAN',
            'scale_version' => 'v1',
            'type_code' => 'BIG5',
            'scores_json' => json_encode([], JSON_UNESCAPED_UNICODE),
            'scores_pct' => json_encode([], JSON_UNESCAPED_UNICODE),
            'axis_states' => json_encode([], JSON_UNESCAPED_UNICODE),
            'profile_version' => null,
            'content_package_version' => 'v1',
            'result_json' => json_encode([
                'raw_scores' => [
                    'domains_mean' => [
                        'O' => $o,
                        'C' => $c,
                        'E' => $e,
                        'A' => $a,
                        'N' => $n,
                    ],
                    'facets_mean' => $facets,
                ],
                'quality' => [
                    'level' => strtoupper($qualityLevel),
                    'flags' => [],
                ],
            ], JSON_UNESCAPED_UNICODE),
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
            'report_engine_version' => 'v1.2',
            'is_valid' => 1,
            'computed_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
    }

    private function seedNormVersionAndStats(string $groupId, string $version, bool $isActive): void
    {
        $versionId = (string) Str::uuid();
        DB::table('scale_norms_versions')->insert([
            'id' => $versionId,
            'scale_code' => 'BIG5_OCEAN',
            'norm_id' => $groupId,
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'version' => $version,
            'group_id' => $groupId,
            'gender' => 'ALL',
            'age_min' => 18,
            'age_max' => 60,
            'source_id' => 'FERMATMIND_PROD_ROLLING',
            'source_type' => 'internal_prod',
            'status' => 'CALIBRATED',
            'is_active' => $isActive ? 1 : 0,
            'published_at' => now(),
            'checksum' => hash('sha256', $version.'|'.$groupId),
            'meta_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $domains = ['O', 'C', 'E', 'A', 'N'];
        $facets = [
            'N1', 'N2', 'N3', 'N4', 'N5', 'N6',
            'E1', 'E2', 'E3', 'E4', 'E5', 'E6',
            'O1', 'O2', 'O3', 'O4', 'O5', 'O6',
            'A1', 'A2', 'A3', 'A4', 'A5', 'A6',
            'C1', 'C2', 'C3', 'C4', 'C5', 'C6',
        ];

        $rows = [];
        foreach ($domains as $domain) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'norm_version_id' => $versionId,
                'metric_level' => 'domain',
                'metric_code' => $domain,
                'mean' => 3.1,
                'sd' => 0.6,
                'sample_n' => 2000,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach ($facets as $facet) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'norm_version_id' => $versionId,
                'metric_level' => 'facet',
                'metric_code' => $facet,
                'mean' => 3.1,
                'sd' => 0.6,
                'sample_n' => 2000,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('scale_norm_stats')->insert($rows);
    }

    /**
     * @return array<string,float>
     */
    private function facetMeans(float $o, float $c, float $e, float $a, float $n): array
    {
        return [
            'N1' => $n, 'N2' => $n, 'N3' => $n, 'N4' => $n, 'N5' => $n, 'N6' => $n,
            'E1' => $e, 'E2' => $e, 'E3' => $e, 'E4' => $e, 'E5' => $e, 'E6' => $e,
            'O1' => $o, 'O2' => $o, 'O3' => $o, 'O4' => $o, 'O5' => $o, 'O6' => $o,
            'A1' => $a, 'A2' => $a, 'A3' => $a, 'A4' => $a, 'A5' => $a, 'A6' => $a,
            'C1' => $c, 'C2' => $c, 'C3' => $c, 'C4' => $c, 'C5' => $c, 'C6' => $c,
        ];
    }
}
