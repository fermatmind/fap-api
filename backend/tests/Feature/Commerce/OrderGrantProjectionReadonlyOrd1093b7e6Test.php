<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use Tests\TestCase;

final class OrderGrantProjectionReadonlyOrd1093b7e6Test extends TestCase
{
    public function test_generated_report_locks_readonly_order_grant_projection_state(): void
    {
        $path = base_path('docs/commerce/generated/order-grant-projection-readonly-ord-1093b7e6.v1.json');

        $this->assertFileExists($path);

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('redacted_production_order_ref_1093b7e6', $payload['order_ref']);
        $this->assertArrayNotHasKey('order_no', $payload);
        $this->assertTrue($payload['production_readonly_verification']);
        $this->assertTrue($payload['no_write_performed']);
        $this->assertTrue($payload['no_env_edit']);
        $this->assertTrue($payload['no_migration']);
        $this->assertTrue($payload['no_grant_created']);
        $this->assertTrue($payload['no_projection_write']);
        $this->assertFalse($payload['public_order_endpoint_invoked']);
        $this->assertFalse($payload['builder_repair_invoked']);
        $this->assertSame('pending', $payload['order_state']['payment_state']);
        $this->assertSame('not_started', $payload['order_state']['grant_state']);
        $this->assertSame(0, $payload['benefit_grants']['count']);
        $this->assertFalse($payload['unified_access_projection']['exists']);
        $this->assertSame(
            'projection_missing_result_ready',
            $payload['exact_result_entry_readonly_inference']['reason_code']
        );
        $this->assertFalse($payload['exact_result_entry_readonly_inference']['ready_to_enter']);
        $this->assertNotEmpty($payload['final_decision']);
        $this->assertNotEmpty($payload['next_task']);
    }
}
