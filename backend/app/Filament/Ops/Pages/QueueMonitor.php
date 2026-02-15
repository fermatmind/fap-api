<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Support\OrgContext;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class QueueMonitor extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = 'SRE';

    protected static ?string $navigationLabel = 'Queue Monitor';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'queue-monitor';

    protected static string $view = 'filament.ops.pages.queue-monitor';

    /** @var list<array<string,mixed>> */
    public array $failedJobs = [];

    public string $statusMessage = '';

    public function mount(): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        if (! \App\Support\SchemaBaseline::hasTable('failed_jobs')) {
            $this->failedJobs = [];

            return;
        }

        $this->failedJobs = DB::table('failed_jobs')
            ->select('id', 'connection', 'queue', 'failed_at', 'exception')
            ->orderByDesc('failed_at')
            ->limit(50)
            ->get()
            ->map(fn ($row): array => [
                'id' => (int) $row->id,
                'connection' => (string) ($row->connection ?? ''),
                'queue' => (string) ($row->queue ?? ''),
                'failed_at' => (string) ($row->failed_at ?? ''),
                'exception' => mb_substr((string) ($row->exception ?? ''), 0, 240),
            ])
            ->all();
    }

    public function retry(int $failedJobId): void
    {
        Artisan::call('queue:retry', [
            'id' => [(string) $failedJobId],
        ]);

        $orgId = max(0, (int) app(OrgContext::class)->orgId());
        DB::table('audit_logs')->insert([
            'org_id' => $orgId,
            'actor_admin_id' => auth((string) config('admin.guard', 'admin'))->id(),
            'action' => 'queue_failed_job_retry',
            'target_type' => 'failed_jobs',
            'target_id' => (string) $failedJobId,
            'meta_json' => json_encode([
                'org_id' => $orgId,
                'reason' => 'ops_retry_failed_job',
                'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
                'failed_job_id' => $failedJobId,
            ], JSON_UNESCAPED_UNICODE),
            'ip' => request()?->ip(),
            'user_agent' => (string) (request()?->userAgent() ?? ''),
            'request_id' => (string) (request()?->attributes->get('request_id') ?? ''),
            'created_at' => now(),
        ]);

        $this->statusMessage = 'Retry queued for failed job #'.$failedJobId;
        $this->refresh();
    }
}
