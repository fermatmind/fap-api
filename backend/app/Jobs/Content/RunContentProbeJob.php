<?php

declare(strict_types=1);

namespace App\Jobs\Content;

use App\Services\Content\Publisher\ContentProbeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class RunContentProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    /** @var array<int,int> */
    public array $backoff = [5, 15];

    public function __construct(
        public string $releaseId,
        public int $orgId,
        public ?string $baseUrl = null,
        public string $correlationId = '',
    ) {
        $this->onConnection('database');
        $this->onQueue('content');
    }

    public function handle(ContentProbeService $probeService): void
    {
        $release = DB::table('content_pack_releases')->where('id', $this->releaseId)->first();
        if (! $release) {
            return;
        }

        $baseUrl = trim((string) ($this->baseUrl ?? config('app.url', '')));
        $result = $probeService->probe(
            $baseUrl,
            (string) ($release->region ?? ''),
            (string) ($release->locale ?? ''),
            (string) ($release->to_pack_id ?? ''),
        );

        DB::table('content_pack_releases')
            ->where('id', $this->releaseId)
            ->update([
                'probe_ok' => ($result['ok'] ?? false) ? 1 : 0,
                'probe_json' => json_encode($result, JSON_UNESCAPED_UNICODE),
                'probe_run_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('audit_logs')->insert([
            'org_id' => $this->orgId,
            'actor_admin_id' => null,
            'action' => ($result['ok'] ?? false) ? 'content_probe_success' : 'content_probe_failed',
            'target_type' => 'ContentPackRelease',
            'target_id' => (string) $this->releaseId,
            'meta_json' => json_encode([
                'actor' => 'system',
                'org_id' => $this->orgId,
                'correlation_id' => $this->correlationId,
                'region' => $release->region ?? null,
                'locale' => $release->locale ?? null,
                'to_pack_id' => $release->to_pack_id ?? null,
                'probe_ok' => (bool) ($result['ok'] ?? false),
                'probe_message' => $result['message'] ?? null,
            ], JSON_UNESCAPED_UNICODE),
            'ip' => null,
            'user_agent' => 'queue:content',
            'request_id' => '',
            'created_at' => now(),
        ]);
    }
}
