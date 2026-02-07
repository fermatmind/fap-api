<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AdminMigrationObservabilityTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_TOKEN = 'pr55-admin-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'admin.token' => self::ADMIN_TOKEN,
        ]);
    }

    public function test_observability_returns_migrations_and_index_audit_summary(): void
    {
        DB::table('migration_index_audits')->insert([
            'migration_name' => '2026_02_08_040000_create_migration_index_audits_table',
            'table_name' => 'attempts',
            'index_name' => 'attempts_org_id_idx',
            'action' => 'create_index',
            'phase' => 'up',
            'driver' => 'sqlite',
            'status' => 'logged',
            'reason' => 'feature_test',
            'meta_json' => null,
            'recorded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v0.2/admin/migrations/observability?limit=5');

        $response
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.index_audit.total', 1);

        $recent = $response->json('data.migrations.recent');
        $this->assertIsArray($recent);
        $this->assertNotEmpty($recent);

        $recentAudit = $response->json('data.index_audit.recent');
        $this->assertIsArray($recentAudit);
        $this->assertNotEmpty($recentAudit);
        $this->assertSame('attempts', (string) ($recentAudit[0]['table_name'] ?? ''));
        $this->assertSame('attempts_org_id_idx', (string) ($recentAudit[0]['index_name'] ?? ''));
    }

    public function test_rollback_preview_returns_latest_items_by_steps(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v0.2/admin/migrations/rollback-preview?steps=2');

        $response
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.steps', 2);

        $items = $response->json('data.items');
        $this->assertIsArray($items);
        $this->assertNotEmpty($items);
        $this->assertLessThanOrEqual(2, count($items));
        $this->assertNotSame('', (string) ($items[0]['migration'] ?? ''));
    }

    public function test_observability_requires_admin_token(): void
    {
        $response = $this->getJson('/api/v0.2/admin/migrations/observability');

        $response
            ->assertStatus(401)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'UNAUTHORIZED');
    }

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        return [
            'X-FAP-Admin-Token' => self::ADMIN_TOKEN,
            'Accept' => 'application/json',
        ];
    }
}
