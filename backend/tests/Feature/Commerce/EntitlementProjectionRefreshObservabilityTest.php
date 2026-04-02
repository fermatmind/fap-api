<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Services\Commerce\EntitlementManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EntitlementProjectionRefreshObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_grant_attempt_unlock_logs_when_projection_refresh_is_skipped_without_losing_the_grant(): void
    {
        Log::spy();
        config()->set('storage_rollout.access_projection_dual_write_enabled', false);
        config()->set('storage_rollout.receipt_ledger_dual_write_enabled', false);

        /** @var EntitlementManager $manager */
        $manager = $this->app->make(EntitlementManager::class);
        $attemptId = (string) Str::uuid();

        $result = $manager->grantAttemptUnlock(
            0,
            null,
            'anon_projection_observability',
            'MBTI_REPORT_FULL',
            $attemptId,
            'ORDER-PROJECTION-OBS'
        );

        $this->assertTrue((bool) ($result['ok'] ?? false));
        $this->assertDatabaseHas('benefit_grants', [
            'attempt_id' => $attemptId,
            'order_no' => 'ORDER-PROJECTION-OBS',
            'status' => 'active',
        ]);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            static function (string $message, array $context) use ($attemptId): bool {
                return $message === 'ENTITLEMENT_ACCESS_PROJECTION_REFRESH_SKIPPED'
                    && (string) ($context['attempt_id'] ?? '') === $attemptId
                    && (string) ($context['source_ref'] ?? '') === 'ORDER-PROJECTION-OBS';
            }
        );
    }
}
