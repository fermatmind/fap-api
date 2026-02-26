<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Support\PiiReadFallbackMonitor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class PiiFallbackMetricsCommandTest extends TestCase
{
    public function test_command_outputs_json_snapshot_payload(): void
    {
        Cache::flush();

        /** @var PiiReadFallbackMonitor $monitor */
        $monitor = app(PiiReadFallbackMonitor::class);
        $monitor->record('users.phone_read', true);
        $monitor->record('users.phone_read', false);

        $exitCode = Artisan::call('ops:pii-fallback-metrics', ['--json' => 1]);
        $this->assertSame(0, $exitCode);

        $payload = json_decode((string) Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('metrics', $payload);
        $metrics = is_array($payload['metrics'] ?? null) ? $payload['metrics'] : [];

        $usersPhone = null;
        foreach ($metrics as $row) {
            if ((string) ($row['metric'] ?? '') === 'users.phone_read') {
                $usersPhone = $row;
                break;
            }
        }

        $this->assertIsArray($usersPhone);
        $this->assertSame(2, (int) ($usersPhone['total'] ?? 0));
        $this->assertSame(1, (int) ($usersPhone['fallback'] ?? 0));
        $this->assertSame(0.5, (float) ($usersPhone['rate'] ?? 0.0));
    }
}
