<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Services\Attempts\AttemptSubmitSideEffects;
use App\Services\Commerce\EntitlementManager;
use App\Support\OrgContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class UnifiedAccessProjectionDualWriteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('storage_rollout.receipt_ledger_dual_write_enabled', true);
        config()->set('storage_rollout.access_projection_dual_write_enabled', true);
    }

    public function test_submit_and_entitlement_writes_refresh_the_unified_access_projection(): void
    {
        $attemptId = (string) Str::uuid();
        $ctx = new OrgContext;
        $ctx->set(42, null, null, 'anon_projection', OrgContext::KIND_TENANT);

        /** @var AttemptSubmitSideEffects $sideEffects */
        $sideEffects = app(AttemptSubmitSideEffects::class);
        $sideEffects->runAfterSubmit($ctx, [
            'org_id' => 42,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_code_v2' => 'MBTI_V2',
            'scale_uid' => 'MBTI_UID',
            'pack_id' => 'PACK-1',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'v1',
        ], null, 'anon_projection');

        $projection = DB::table('unified_access_projections')->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($projection);
        $this->assertSame('locked', $projection->access_state);
        $this->assertSame('pending', $projection->report_state);
        $this->assertSame('missing', $projection->pdf_state);
        $this->assertSame('submit_received', $projection->reason_code);

        /** @var EntitlementManager $entitlements */
        $entitlements = app(EntitlementManager::class);
        $entitlements->grantAttemptUnlock(
            42,
            null,
            'anon_projection',
            'MBTI_UNLOCK',
            $attemptId,
            'ORDER-123'
        );

        $projection = DB::table('unified_access_projections')->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($projection);
        $this->assertSame('pending', $projection->access_state);
        $this->assertSame('pending', $projection->report_state);
        $this->assertSame('missing', $projection->pdf_state);
        $this->assertSame('entitlement_granted', $projection->reason_code);

        $this->assertDatabaseCount('attempt_receipts', 2);
        $this->assertDatabaseHas('attempt_receipts', [
            'attempt_id' => $attemptId,
            'receipt_type' => 'access_projection_refreshed',
            'source_ref' => $attemptId,
        ]);
        $this->assertDatabaseHas('attempt_receipts', [
            'attempt_id' => $attemptId,
            'receipt_type' => 'access_projection_refreshed',
            'source_ref' => 'ORDER-123',
        ]);
    }
}
