<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\UrlTruth\PublicCanonicalConsumerSnapshot;
use Illuminate\Console\Command;
use Throwable;

final class SeoPlatform10ProductionCloseoutCommand extends Command
{
    protected $signature = 'seo-intel:platform-10-closeout {--json : Emit a sanitized machine-readable receipt}';

    protected $description = 'Read back the active SEO Platform 10 public-consumer snapshot and LKG pointer.';

    public function handle(PublicCanonicalConsumerSnapshot $snapshot): int
    {
        try {
            $receipt = $snapshot->closeoutReceipt();
        } catch (Throwable) {
            $receipt = [
                'schema_version' => 'seo-platform-10-consumer-closeout.v1',
                'status' => 'blocked',
                'issues' => ['consumer_snapshot_unavailable'],
                'boundaries' => [
                    'raw_error_emitted' => false,
                    'raw_urls_emitted' => false,
                    'search_submission_allowed' => false,
                    'destructive_probe_performed' => false,
                ],
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line('status='.(string) ($receipt['status'] ?? 'blocked'));
        }

        return ($receipt['status'] ?? null) === 'success' ? self::SUCCESS : self::FAILURE;
    }
}
