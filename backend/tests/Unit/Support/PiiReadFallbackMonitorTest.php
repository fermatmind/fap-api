<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\PiiReadFallbackMonitor;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class PiiReadFallbackMonitorTest extends TestCase
{
    public function test_record_and_snapshot_compute_expected_rate(): void
    {
        Cache::flush();

        /** @var PiiReadFallbackMonitor $monitor */
        $monitor = app(PiiReadFallbackMonitor::class);

        $monitor->record('users.phone_read', false);
        $monitor->record('users.phone_read', true);
        $monitor->record('users.phone_read', true);

        $snapshot = $monitor->snapshot('users.phone_read');

        $this->assertSame('users.phone_read', (string) ($snapshot['metric'] ?? ''));
        $this->assertSame(3, (int) ($snapshot['total'] ?? 0));
        $this->assertSame(2, (int) ($snapshot['fallback'] ?? 0));
        $this->assertSame(0.6667, (float) ($snapshot['rate'] ?? 0.0));
    }

    public function test_snapshot_many_returns_metrics_in_given_order(): void
    {
        Cache::flush();

        /** @var PiiReadFallbackMonitor $monitor */
        $monitor = app(PiiReadFallbackMonitor::class);

        $monitor->record('email_outbox.payload_read', true);
        $monitor->record('email_outbox.recipient_read', false);

        $rows = $monitor->snapshotMany([
            'email_outbox.payload_read',
            'email_outbox.recipient_read',
        ]);

        $this->assertCount(2, $rows);
        $this->assertSame('email_outbox.payload_read', (string) ($rows[0]['metric'] ?? ''));
        $this->assertSame(1, (int) ($rows[0]['fallback'] ?? 0));
        $this->assertSame('email_outbox.recipient_read', (string) ($rows[1]['metric'] ?? ''));
        $this->assertSame(0, (int) ($rows[1]['fallback'] ?? 0));
    }
}
