<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\GscReadModelCanaryReadbackAuditor;
use Illuminate\Console\Command;
use Throwable;

final class SeoIntelGscReadModelCanaryReadbackCommand extends Command
{
    protected $signature = 'seo-intel:gsc-readmodel-canary-readback
        {--artifact= : Path to a sanitized GSC sidecar live-read artifact}
        {--artifact-sha256= : Required SHA256 expected for the artifact}
        {--limit=250 : Preview/readback row limit, bounded 1..250}
        {--json : Emit JSON summary}';

    protected $description = 'Read-only audit for GSC readmodel canary idempotency/readback without writing or printing raw query/url.';

    public function handle(GscReadModelCanaryReadbackAuditor $auditor): int
    {
        $path = $this->artifactPath();
        if ($path === null) {
            return $this->finish($this->failureSummary('artifact_path_required'));
        }

        $expectedSha256 = $this->expectedSha256();
        if ($expectedSha256 === null) {
            return $this->finish($this->failureSummary('artifact_sha256_required'));
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $this->finish($this->failureSummary('artifact_json_invalid'));
        }

        if (! is_array($decoded)) {
            return $this->finish($this->failureSummary('artifact_must_be_object'));
        }

        $summary = $auditor->audit(
            $decoded,
            (string) hash_file('sha256', $path),
            $expectedSha256,
            $this->limit(),
        );

        return $this->finish($summary);
    }

    private function artifactPath(): ?string
    {
        $path = trim((string) $this->option('artifact'));
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }

        $path = str_starts_with($path, '/') ? $path : base_path($path);

        return is_file($path) ? $path : null;
    }

    private function expectedSha256(): ?string
    {
        $sha256 = trim((string) $this->option('artifact-sha256'));

        return preg_match('/^[a-f0-9]{64}$/', $sha256) === 1 ? $sha256 : null;
    }

    private function limit(): int
    {
        $raw = trim((string) $this->option('limit'));
        if (preg_match('/^\d+$/', $raw) !== 1) {
            return 250;
        }

        return max(1, min((int) $raw, 250));
    }

    /**
     * @return array<string, mixed>
     */
    private function failureSummary(string $issue): array
    {
        return [
            'schema_version' => GscReadModelCanaryReadbackAuditor::SCHEMA_VERSION,
            'task' => GscReadModelCanaryReadbackAuditor::TASK,
            'status' => 'blocked',
            'ok' => false,
            'read_only' => true,
            'dry_run' => true,
            'would_write' => false,
            'target_table' => GscReadModelCanaryReadbackAuditor::TARGET_TABLE,
            'rows_previewed' => 0,
            'idempotency_key_count' => 0,
            'rows_found' => 0,
            'distinct_keys' => 0,
            'rows_missing' => 0,
            'would_duplicate' => false,
            'all_rows_already_present' => false,
            'issues' => [$issue],
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function finish(array $summary): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('status='.(string) ($summary['status'] ?? 'blocked'));
            $this->line('ok='.((bool) ($summary['ok'] ?? false) ? 'true' : 'false'));
            $this->line('read_only=true');
            $this->line('would_write=false');
            $this->line('target_table=seo_gsc_daily');
            $this->line('rows_found='.(string) ($summary['rows_found'] ?? 0));
            $this->line('distinct_keys='.(string) ($summary['distinct_keys'] ?? 0));
            $this->line('rows_missing='.(string) ($summary['rows_missing'] ?? 0));
            $this->line('would_duplicate='.((bool) ($summary['would_duplicate'] ?? false) ? 'true' : 'false'));

            foreach ((array) ($summary['issues'] ?? []) as $issue) {
                $this->line('issue='.(string) $issue);
            }
        }

        return ($summary['status'] ?? null) === 'success' ? self::SUCCESS : self::FAILURE;
    }
}
