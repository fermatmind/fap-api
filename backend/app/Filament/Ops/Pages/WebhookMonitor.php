<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Support\OrgContext;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class WebhookMonitor extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $navigationGroup = 'SRE';

    protected static ?string $navigationLabel = 'Webhook Monitor';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'webhook-monitor';

    protected static string $view = 'filament.ops.pages.webhook-monitor';

    public int $limit = 50;

    /** @var list<array<string,mixed>> */
    public array $events = [];

    /** @var array<string,int> */
    public array $aggregates = [
        'signature_failed' => 0,
        'processed_failed' => 0,
    ];

    public function mount(): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        $orgId = max(0, (int) app(OrgContext::class)->orgId());
        $limit = max(10, min(200, $this->limit));

        $this->events = DB::table('payment_events')
            ->where('org_id', $orgId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'provider' => (string) ($row->provider ?? ''),
                'provider_event_id' => (string) ($row->provider_event_id ?? ''),
                'order_no' => (string) ($row->order_no ?? ''),
                'signature_ok' => (bool) ($row->signature_ok ?? false),
                'status' => (string) ($row->status ?? ''),
                'handle_status' => (string) ($row->handle_status ?? ''),
                'last_error_code' => (string) ($row->last_error_code ?? ''),
                'created_at' => (string) ($row->created_at ?? ''),
            ])
            ->all();

        $this->aggregates['signature_failed'] = (int) DB::table('payment_events')
            ->where('org_id', $orgId)
            ->where('signature_ok', 0)
            ->count();

        $this->aggregates['processed_failed'] = (int) DB::table('payment_events')
            ->where('org_id', $orgId)
            ->where(function ($query): void {
                $query->whereIn('status', ['failed', 'rejected', 'post_commit_failed'])
                    ->orWhereIn('handle_status', ['failed', 'reprocess_failed']);
            })
            ->count();
    }
}
