<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Support\PiiReadFallbackMonitor;
use Illuminate\Console\Command;

class PiiFallbackMetrics extends Command
{
    protected $signature = 'ops:pii-fallback-metrics
        {--day= : Bucket day in Ymd, default today}
        {--json=0 : Output JSON payload}';

    protected $description = 'Show PII plaintext fallback hit-rate snapshots.';

    /**
     * @var list<string>
     */
    private array $defaultMetrics = [
        'users.phone_read',
        'email_outbox.payload_read',
        'email_outbox.recipient_read',
    ];

    public function __construct(private readonly PiiReadFallbackMonitor $monitor)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $day = trim((string) $this->option('day'));
        $rows = $this->monitor->snapshotMany($this->defaultMetrics, $day !== '' ? $day : null);

        $payload = [
            'day' => $rows[0]['day'] ?? now()->format('Ymd'),
            'metrics' => $rows,
        ];

        if ((bool) ((int) $this->option('json'))) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

            return self::SUCCESS;
        }

        $this->info('PII fallback metrics day='.(string) ($payload['day'] ?? ''));
        foreach ($rows as $row) {
            $this->line(sprintf(
                '%s total=%d fallback=%d rate=%.4f',
                (string) ($row['metric'] ?? ''),
                (int) ($row['total'] ?? 0),
                (int) ($row['fallback'] ?? 0),
                (float) ($row['rate'] ?? 0.0)
            ));
        }

        return self::SUCCESS;
    }
}
