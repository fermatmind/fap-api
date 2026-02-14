<?php

namespace Tests\Feature\Admin;

use App\Filament\Ops\Resources\AuditLogResource;
use App\Support\OrgContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLogOrgScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_eloquent_query_only_returns_current_org_id(): void
    {
        $this->insertAuditLogs();

        $context = app(OrgContext::class);
        $context->set(1, null, 'admin');
        app()->instance(OrgContext::class, $context);

        $rows = AuditLogResource::getEloquentQuery()
            ->orderBy('id')
            ->get(['id', 'org_id'])
            ->toArray();

        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) ($rows[0]['org_id'] ?? -1));
    }

    public function test_get_eloquent_query_defaults_to_org_zero(): void
    {
        $this->insertAuditLogs();

        $rows = AuditLogResource::getEloquentQuery()
            ->orderBy('id')
            ->get(['id', 'org_id'])
            ->toArray();

        $this->assertCount(1, $rows);
        $this->assertSame(0, (int) ($rows[0]['org_id'] ?? -1));
    }

    private function insertAuditLogs(): void
    {
        $now = now();

        DB::table('audit_logs')->insert([
            [
                'org_id' => 0,
                'actor_admin_id' => null,
                'action' => 'seed_org_0',
                'target_type' => null,
                'target_id' => null,
                'meta_json' => json_encode(['case' => 'org0']),
                'ip' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'request_id' => 'req-org-0',
                'created_at' => $now,
            ],
            [
                'org_id' => 1,
                'actor_admin_id' => null,
                'action' => 'seed_org_1',
                'target_type' => null,
                'target_id' => null,
                'meta_json' => json_encode(['case' => 'org1']),
                'ip' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'request_id' => 'req-org-1',
                'created_at' => $now,
            ],
            [
                'org_id' => 2,
                'actor_admin_id' => null,
                'action' => 'seed_org_2',
                'target_type' => null,
                'target_id' => null,
                'meta_json' => json_encode(['case' => 'org2']),
                'ip' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'request_id' => 'req-org-2',
                'created_at' => $now,
            ],
        ]);
    }
}
