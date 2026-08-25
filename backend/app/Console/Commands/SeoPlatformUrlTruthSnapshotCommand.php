<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\UrlTruth\UrlTruthReconciliationRuntimeService;
use Illuminate\Console\Command;

final class SeoPlatformUrlTruthSnapshotCommand extends Command
{
    protected $signature = 'seo-intel:url-truth-reconcile-snapshot
        {--no-http : Skip public HTTP and consumer probes}
        {--resume-cursor= : Continue after the prior sanitized SHA-256 cursor}
        {--limit=50 : Maximum authority pages in this HTTP batch (1-100)}
        {--concurrency=4 : Maximum concurrent public GETs (1-4)}
        {--timeout=10 : Per-request timeout seconds (1-15)}
        {--retries=1 : Maximum retry count (0-2)}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Build a read-only dynamic URL Truth authority and consumer reconciliation snapshot.';

    public function handle(UrlTruthReconciliationRuntimeService $service): int
    {
        $snapshot = $service->read(
            (bool) $this->option('no-http') === false,
            $this->cursor(),
            (int) $this->option('limit'),
            (int) $this->option('concurrency'),
            (int) $this->option('timeout'),
            (int) $this->option('retries'),
        );

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line('authority_total='.(string) data_get($snapshot, 'counts.authority_total'));
            $this->line('effective_public='.(string) data_get($snapshot, 'counts.effective_public'));
            $this->line('url_truth_valid='.(string) data_get($snapshot, 'counts.url_truth_valid'));
            $this->line('live_http_state='.(string) data_get($snapshot, 'source_state.live_http'));
            $this->line('next_resume_cursor='.(string) data_get($snapshot, 'live_http.next_resume_cursor'));
        }

        return self::SUCCESS;
    }

    private function cursor(): ?string
    {
        $cursor = strtolower(trim((string) $this->option('resume-cursor')));

        return preg_match('/^[a-f0-9]{64}$/', $cursor) === 1 ? $cursor : null;
    }
}
