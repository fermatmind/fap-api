<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Services\Auth\FmTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BigFiveOpsReleasesEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_list_big5_releases_with_evidence_fields(): void
    {
        $owner = $this->createUserWithToken('ops-owner@big5.test');
        $orgId = $this->createOrgForToken($owner['token']);

        $now = now();
        $this->insertRelease([
            'id' => (string) Str::uuid(),
            'action' => 'publish',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => 'v1',
            'from_pack_id' => 'BIG5_OCEAN',
            'to_pack_id' => 'BIG5_OCEAN',
            'status' => 'success',
            'manifest_hash' => str_repeat('a', 64),
            'compiled_hash' => str_repeat('b', 64),
            'content_hash' => str_repeat('c', 64),
            'norms_version' => '2026Q1_zhcn_prod_v1',
            'git_sha' => str_repeat('d', 40),
            'created_at' => $now->copy()->subMinutes(2),
            'updated_at' => $now->copy()->subMinutes(2),
        ]);

        $this->insertRelease([
            'id' => (string) Str::uuid(),
            'action' => 'rollback',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => 'v1',
            'from_pack_id' => 'BIG5_OCEAN',
            'to_pack_id' => 'BIG5_OCEAN',
            'status' => 'success',
            'manifest_hash' => str_repeat('e', 64),
            'compiled_hash' => str_repeat('f', 64),
            'content_hash' => str_repeat('1', 64),
            'norms_version' => '2026Q1_zhcn_prod_v1',
            'git_sha' => str_repeat('2', 40),
            'created_at' => $now->copy()->subMinute(),
            'updated_at' => $now->copy()->subMinute(),
        ]);

        $this->insertRelease([
            'id' => (string) Str::uuid(),
            'action' => 'publish',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => 'MBTI-CN-v0.3',
            'from_pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'to_pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'status' => 'success',
            'manifest_hash' => str_repeat('9', 64),
            'compiled_hash' => str_repeat('8', 64),
            'content_hash' => str_repeat('7', 64),
            'norms_version' => null,
            'git_sha' => str_repeat('6', 40),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $owner['token'],
            'X-Org-Id' => (string) $orgId,
        ])->getJson('/api/v0.3/orgs/' . $orgId . '/big5/releases?region=CN_MAINLAND&locale=zh-CN&limit=10');

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('org_id', $orgId);
        $response->assertJsonPath('count', 2);

        $items = $response->json('items');
        $this->assertIsArray($items);
        $this->assertCount(2, $items);
        $this->assertSame('rollback', (string) ($items[0]['action'] ?? ''));
        $this->assertSame('publish', (string) ($items[1]['action'] ?? ''));
        $this->assertSame('BIG5_OCEAN', (string) ($items[0]['to_pack_id'] ?? ''));
        $this->assertSame(64, strlen((string) ($items[0]['evidence']['manifest_hash'] ?? '')));
        $this->assertSame('2026Q1_zhcn_prod_v1', (string) ($items[0]['evidence']['norms_version'] ?? ''));
    }

    public function test_non_member_cannot_access_big5_releases_endpoint(): void
    {
        $owner = $this->createUserWithToken('ops-owner-2@big5.test');
        $outsider = $this->createUserWithToken('ops-outsider@big5.test');
        $orgId = $this->createOrgForToken($owner['token']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $outsider['token'],
            'X-Org-Id' => (string) $orgId,
        ])->getJson('/api/v0.3/orgs/' . $orgId . '/big5/releases');

        $response->assertStatus(404);
        $response->assertJsonPath('error_code', 'ORG_NOT_FOUND');
    }

    public function test_owner_can_get_latest_big5_release(): void
    {
        $owner = $this->createUserWithToken('ops-owner-latest@big5.test');
        $orgId = $this->createOrgForToken($owner['token']);

        $now = now();
        $this->insertRelease([
            'id' => (string) Str::uuid(),
            'action' => 'publish',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'from_pack_id' => 'BIG5_OCEAN',
            'to_pack_id' => 'BIG5_OCEAN',
            'status' => 'success',
            'norms_version' => '2026Q1_zhcn_prod_v1',
            'created_at' => $now->copy()->subMinutes(2),
            'updated_at' => $now->copy()->subMinutes(2),
        ]);

        $latestId = (string) Str::uuid();
        $this->insertRelease([
            'id' => $latestId,
            'action' => 'publish',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'from_pack_id' => 'BIG5_OCEAN',
            'to_pack_id' => 'BIG5_OCEAN',
            'status' => 'success',
            'norms_version' => '2026Q2_zhcn_prod_v1',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $owner['token'],
            'X-Org-Id' => (string) $orgId,
        ])->getJson('/api/v0.3/orgs/' . $orgId . '/big5/releases/latest?region=CN_MAINLAND&locale=zh-CN');

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('item.release_id', $latestId);
        $response->assertJsonPath('item.evidence.norms_version', '2026Q2_zhcn_prod_v1');
    }

    public function test_owner_can_list_big5_audits_with_filters(): void
    {
        $owner = $this->createUserWithToken('ops-owner-audits@big5.test');
        $orgId = $this->createOrgForToken($owner['token']);
        $releaseId = (string) Str::uuid();

        $this->insertAudit([
            'action' => 'big5_pack_publish',
            'target_type' => 'content_pack_release',
            'target_id' => $releaseId,
            'result' => 'success',
            'reason' => '',
            'request_id' => 'req_audit_publish',
            'meta_json' => json_encode(['git_sha' => str_repeat('d', 40)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $this->insertAudit([
            'action' => 'big5_pack_rollback',
            'target_type' => 'content_pack_release',
            'target_id' => $releaseId,
            'result' => 'failed',
            'reason' => 'release not found',
            'request_id' => 'req_audit_rollback_failed',
        ]);
        $this->insertAudit([
            'action' => 'admin_login',
            'target_type' => 'admin_user',
            'target_id' => '1',
            'result' => 'success',
            'reason' => '',
            'request_id' => 'req_non_big5',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $owner['token'],
            'X-Org-Id' => (string) $orgId,
        ])->getJson('/api/v0.3/orgs/' . $orgId . '/big5/audits?action=big5_pack_publish&result=success&release_id=' . $releaseId . '&limit=10');

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('count', 1);
        $response->assertJsonPath('items.0.action', 'big5_pack_publish');
        $response->assertJsonPath('items.0.target_id', $releaseId);
        $response->assertJsonPath('items.0.meta.git_sha', str_repeat('d', 40));
    }

    public function test_non_member_cannot_access_big5_audits_endpoint(): void
    {
        $owner = $this->createUserWithToken('ops-owner-audits-2@big5.test');
        $outsider = $this->createUserWithToken('ops-outsider-audits@big5.test');
        $orgId = $this->createOrgForToken($owner['token']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $outsider['token'],
            'X-Org-Id' => (string) $orgId,
        ])->getJson('/api/v0.3/orgs/' . $orgId . '/big5/audits');

        $response->assertStatus(404);
        $response->assertJsonPath('error_code', 'ORG_NOT_FOUND');
    }

    public function test_owner_can_get_big5_audit_detail_with_related_release(): void
    {
        $owner = $this->createUserWithToken('ops-owner-audit-detail@big5.test');
        $orgId = $this->createOrgForToken($owner['token']);
        $releaseId = (string) Str::uuid();

        $this->insertRelease([
            'id' => $releaseId,
            'action' => 'publish',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'from_pack_id' => 'BIG5_OCEAN',
            'to_pack_id' => 'BIG5_OCEAN',
            'status' => 'success',
            'norms_version' => '2026Q2_zhcn_prod_v1',
        ]);

        $auditId = (int) DB::table('audit_logs')->insertGetId([
            'actor_admin_id' => null,
            'action' => 'big5_pack_publish',
            'target_type' => 'content_pack_release',
            'target_id' => $releaseId,
            'meta_json' => json_encode(['manifest_hash' => str_repeat('a', 64)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'request_id' => 'req_audit_detail',
            'reason' => '',
            'result' => 'success',
            'created_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $owner['token'],
            'X-Org-Id' => (string) $orgId,
        ])->getJson('/api/v0.3/orgs/' . $orgId . '/big5/audits/' . $auditId);

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('item.id', $auditId);
        $response->assertJsonPath('item.action', 'big5_pack_publish');
        $response->assertJsonPath('release.release_id', $releaseId);
    }

    public function test_audit_detail_returns_not_found_for_non_big5_action(): void
    {
        $owner = $this->createUserWithToken('ops-owner-audit-detail-nonbig5@big5.test');
        $orgId = $this->createOrgForToken($owner['token']);

        $auditId = (int) DB::table('audit_logs')->insertGetId([
            'actor_admin_id' => null,
            'action' => 'admin_login',
            'target_type' => 'admin_user',
            'target_id' => '1',
            'meta_json' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'request_id' => 'req_audit_detail_nonbig5',
            'reason' => '',
            'result' => 'success',
            'created_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $owner['token'],
            'X-Org-Id' => (string) $orgId,
        ])->getJson('/api/v0.3/orgs/' . $orgId . '/big5/audits/' . $auditId);

        $response->assertStatus(404);
        $response->assertJsonPath('error_code', 'AUDIT_NOT_FOUND');
    }

    public function test_non_member_cannot_access_big5_audit_detail_endpoint(): void
    {
        $owner = $this->createUserWithToken('ops-owner-audit-detail-2@big5.test');
        $outsider = $this->createUserWithToken('ops-outsider-audit-detail@big5.test');
        $orgId = $this->createOrgForToken($owner['token']);

        $auditId = (int) DB::table('audit_logs')->insertGetId([
            'actor_admin_id' => null,
            'action' => 'big5_pack_publish',
            'target_type' => 'content_pack_release',
            'target_id' => (string) Str::uuid(),
            'meta_json' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'request_id' => 'req_audit_detail_forbidden',
            'reason' => '',
            'result' => 'success',
            'created_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $outsider['token'],
            'X-Org-Id' => (string) $orgId,
        ])->getJson('/api/v0.3/orgs/' . $orgId . '/big5/audits/' . $auditId);

        $response->assertStatus(404);
        $response->assertJsonPath('error_code', 'ORG_NOT_FOUND');
    }

    public function test_latest_release_returns_not_found_when_big5_release_absent(): void
    {
        $owner = $this->createUserWithToken('ops-owner-latest-missing@big5.test');
        $orgId = $this->createOrgForToken($owner['token']);

        $this->insertRelease([
            'id' => (string) Str::uuid(),
            'action' => 'publish',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'from_pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'to_pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $owner['token'],
            'X-Org-Id' => (string) $orgId,
        ])->getJson('/api/v0.3/orgs/' . $orgId . '/big5/releases/latest');

        $response->assertStatus(404);
        $response->assertJsonPath('error_code', 'RELEASE_NOT_FOUND');
    }

    public function test_owner_can_get_big5_release_detail_with_audits(): void
    {
        $owner = $this->createUserWithToken('ops-owner-detail@big5.test');
        $orgId = $this->createOrgForToken($owner['token']);

        $releaseId = (string) Str::uuid();
        $this->insertRelease([
            'id' => $releaseId,
            'action' => 'publish',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => 'v1',
            'from_pack_id' => 'BIG5_OCEAN',
            'to_pack_id' => 'BIG5_OCEAN',
            'status' => 'success',
            'manifest_hash' => str_repeat('a', 64),
            'compiled_hash' => str_repeat('b', 64),
            'content_hash' => str_repeat('c', 64),
            'norms_version' => '2026Q1_zhcn_prod_v1',
            'git_sha' => str_repeat('d', 40),
        ]);

        $this->insertAudit([
            'action' => 'big5_pack_publish',
            'target_type' => 'content_pack_release',
            'target_id' => $releaseId,
            'result' => 'success',
            'reason' => '',
            'meta_json' => json_encode(['manifest_hash' => str_repeat('a', 64)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'request_id' => 'req_big5_release_detail',
        ]);

        $this->insertAudit([
            'action' => 'big5_pack_publish',
            'target_type' => 'content_pack_release',
            'target_id' => (string) Str::uuid(),
            'result' => 'success',
            'reason' => '',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $owner['token'],
            'X-Org-Id' => (string) $orgId,
        ])->getJson('/api/v0.3/orgs/' . $orgId . '/big5/releases/' . $releaseId);

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('org_id', $orgId);
        $response->assertJsonPath('item.release_id', $releaseId);
        $response->assertJsonPath('item.evidence.norms_version', '2026Q1_zhcn_prod_v1');
        $response->assertJsonPath('audits.0.action', 'big5_pack_publish');
        $response->assertJsonPath('audits.0.target_id', $releaseId);
        $response->assertJsonPath('audits.0.meta.manifest_hash', str_repeat('a', 64));
    }

    public function test_release_detail_returns_not_found_for_non_big5_release(): void
    {
        $owner = $this->createUserWithToken('ops-owner-nonbig5@big5.test');
        $orgId = $this->createOrgForToken($owner['token']);

        $releaseId = (string) Str::uuid();
        $this->insertRelease([
            'id' => $releaseId,
            'action' => 'publish',
            'from_pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'to_pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $owner['token'],
            'X-Org-Id' => (string) $orgId,
        ])->getJson('/api/v0.3/orgs/' . $orgId . '/big5/releases/' . $releaseId);

        $response->assertStatus(404);
        $response->assertJsonPath('error_code', 'RELEASE_NOT_FOUND');
    }

    /**
     * @return array{user_id:int,token:string}
     */
    private function createUserWithToken(string $email): array
    {
        $now = now();
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'User ' . $email,
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
            'Authorization' => 'Bearer ' . $token,
            'X-Org-Id' => '0',
        ])->postJson('/api/v0.3/orgs', [
            'name' => 'BIG5 Ops Org ' . Str::random(6),
        ]);

        $response->assertStatus(200);

        return (int) ($response->json('org.org_id') ?? 0);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function insertRelease(array $row): void
    {
        DB::table('content_pack_releases')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'action' => 'publish',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'dir_alias' => 'v1',
            'from_version_id' => null,
            'to_version_id' => null,
            'from_pack_id' => 'BIG5_OCEAN',
            'to_pack_id' => 'BIG5_OCEAN',
            'status' => 'success',
            'message' => null,
            'created_by' => 'test',
            'manifest_hash' => null,
            'compiled_hash' => null,
            'content_hash' => null,
            'norms_version' => null,
            'git_sha' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $row));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function insertAudit(array $row): void
    {
        DB::table('audit_logs')->insert(array_merge([
            'actor_admin_id' => null,
            'action' => 'big5_pack_publish',
            'target_type' => 'content_pack_release',
            'target_id' => null,
            'meta_json' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'request_id' => 'req_' . Str::random(8),
            'reason' => null,
            'result' => null,
            'created_at' => now(),
        ], $row));
    }
}
