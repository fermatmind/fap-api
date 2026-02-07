<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Support\SensitiveDataRedactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AuditLogger
{
    public function __construct(
        private readonly SensitiveDataRedactor $redactor
    ) {
    }

    public function log(
        Request $request,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        ?array $meta = null
    ): void {
        if (!Schema::hasTable('audit_logs')) {
            return;
        }

        $meta = $meta ?? [];
        $meta['method'] = $meta['method'] ?? $request->method();
        $meta['path'] = $meta['path'] ?? $request->path();
        $meta['status_intended'] = $meta['status_intended'] ?? null;
        $meta['params_sanitized'] = $meta['params_sanitized'] ?? $this->sanitizeParams($request->all());

        $actorAdminId = null;
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();
        if ($user && property_exists($user, 'id')) {
            $actorAdminId = (int) $user->id;
        }

        if ($actorAdminId === null) {
            $meta['actor'] = $meta['actor'] ?? 'admin_token';
        }

        AuditLog::create([
            'actor_admin_id' => $actorAdminId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'meta_json' => $meta,
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'request_id' => (string) ($request->attributes->get('request_id') ?? ''),
            'created_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function sanitizeParams(array $params): array
    {
        $redacted = $this->redactor->redact($params);
        $sanitized = [];

        foreach ($redacted as $key => $value) {
            $sanitized[$key] = $this->sanitizeScalar($value);
        }

        return $sanitized;
    }

    private function sanitizeScalar(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $sanitized = [];
        foreach ($value as $key => $item) {
            $sanitized[$key] = $this->sanitizeScalar($item);
        }

        return $sanitized;
    }
}
