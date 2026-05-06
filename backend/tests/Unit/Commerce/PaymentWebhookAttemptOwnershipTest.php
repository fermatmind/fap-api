<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use App\Internal\Commerce\PaymentWebhookHandlerCore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PaymentWebhookAttemptOwnershipTest extends TestCase
{
    public function test_logged_in_order_accepts_pre_login_anon_attempt_binding(): void
    {
        $core = $this->core();

        $result = $core->validateAttemptOwnershipForOrder((object) [
            'user_id' => 'user_1',
            'anon_id' => 'anon_1',
        ], [
            'attempt_id' => 'attempt_1',
            'user_id' => null,
            'anon_id' => 'anon_1',
        ]);

        $this->assertSame(['ok' => true], $result);
    }

    public function test_logged_in_order_rejects_different_anon_attempt_binding(): void
    {
        $core = $this->core();

        $result = $core->validateAttemptOwnershipForOrder((object) [
            'user_id' => 'user_1',
            'anon_id' => 'anon_1',
        ], [
            'attempt_id' => 'attempt_1',
            'user_id' => null,
            'anon_id' => 'anon_2',
        ]);

        $this->assertSame(false, $result['ok']);
        $this->assertSame('ATTEMPT_OWNER_MISMATCH', $result['error']);
    }

    private function core(): PaymentWebhookHandlerCore
    {
        /** @var PaymentWebhookHandlerCore $core */
        $core = (new ReflectionClass(PaymentWebhookHandlerCore::class))->newInstanceWithoutConstructor();

        return $core;
    }
}
