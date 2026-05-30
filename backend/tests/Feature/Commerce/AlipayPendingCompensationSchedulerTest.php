<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Console\Kernel;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use ReflectionMethod;
use Tests\TestCase;

final class AlipayPendingCompensationSchedulerTest extends TestCase
{
    public function test_alipay_pending_compensation_is_registered_conservatively(): void
    {
        $event = $this->scheduledEventFor('commerce:compensate-pending-orders');

        $this->assertNotNull($event);
        $this->assertStringContainsString('--provider=alipay', (string) $event->command);
        $this->assertStringContainsString('--include-created', (string) $event->command);
        $this->assertStringContainsString('--limit=50', (string) $event->command);
        $this->assertStringContainsString('--older-than-minutes=15', (string) $event->command);
        $this->assertStringNotContainsString('--close-expired', (string) $event->command);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    private function scheduledEventFor(string $needle): ?Event
    {
        $schedule = new Schedule;
        $kernel = $this->app->make(Kernel::class);
        $method = new ReflectionMethod($kernel, 'schedule');
        $method->setAccessible(true);
        $method->invoke($kernel, $schedule);

        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, $needle)) {
                return $event;
            }
        }

        return null;
    }
}
