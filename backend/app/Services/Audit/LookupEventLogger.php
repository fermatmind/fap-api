<?php

namespace App\Services\Audit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LookupEventLogger
{
    public function log(string $method, bool $success, Request $request, ?string $userId = null, array $meta = []): void
    {
        if (!Schema::hasTable('lookup_events')) {
            return;
        }

        $metaJson = null;
        if (!empty($meta)) {
            $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $uid = is_string($userId) ? trim($userId) : '';
        $ip = (string) ($request->ip() ?? '');
        $requestId = trim((string) $request->header('X-Request-Id', ''));
        if ($requestId === '') {
            $requestId = trim((string) $request->header('X-Request-ID', ''));
        }
        $orgId = $request->attributes->get('org_id');
        $orgId = is_numeric($orgId) ? (int) $orgId : (is_numeric($meta['org_id'] ?? null) ? (int) $meta['org_id'] : 0);

        try {
            DB::table('lookup_events')->insert([
                'id' => (string) Str::uuid(),
                'method' => $method,
                'success' => $success ? 1 : 0,
                'user_id' => $uid !== '' ? $uid : null,
                'ip' => $ip !== '' ? $ip : null,
                'meta_json' => $metaJson,
                'request_id' => $requestId !== '' ? $requestId : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('LOOKUP_EVENT_LOG_WRITE_FAILED', [
                'method' => $method,
                'success' => $success,
                'org_id' => $orgId,
                'request_id' => $requestId !== '' ? $requestId : null,
                'user_id' => $uid !== '' ? $uid : null,
                'exception' => $e,
            ]);
        }
    }
}
