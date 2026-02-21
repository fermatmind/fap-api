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
}

