<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Services\Audit\AuditLogger;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
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

    public static function canAccess(): bool
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        return is_object($user)
            && method_exists($user, 'hasPermission')
            && (
                $user->hasPermission(PermissionNames::ADMIN_MENU_SRE)
                || $user->hasPermission(PermissionNames::ADMIN_OWNER)
            );
    }

    public static function getNavigationBadge(): ?string
    {
        if (! \App\Support\SchemaBaseline::hasTable('failed_jobs')) {
            return null;
        }

        $count = (int) DB::table('failed_jobs')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string | array | null
    {
        return 'danger';
    }

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
        app(AuditLogger::class)->log(
            request(),
            'queue_failed_job_retry',
            'failed_jobs',
            (string) $failedJobId,
            [
                'org_id' => $orgId,
                'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
                'failed_job_id' => $failedJobId,
            ],
            'ops_retry_failed_job',
            'requested',
        );

        $this->statusMessage = 'Retry queued for failed job #'.$failedJobId;
        $this->refresh();
    }
}
