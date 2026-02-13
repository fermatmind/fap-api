<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Support\OrgContext;
use App\Support\SensitiveDataRedactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        if (!\App\Support\SchemaBaseline::hasTable('audit_logs')) {
            return;
        }

        $meta = $meta ?? [];
        $meta['method'] = $meta['method'] ?? $request->method();
        $meta['path'] = $meta['path'] ?? $request->path();
        $meta['status_intended'] = $meta['status_intended'] ?? null;
        $meta['params_sanitized'] = $meta['params_sanitized'] ?? $request->all();

        $metaRedacted = $this->redactor->redactWithMeta($meta);
        $meta = $this->sanitizeScalar($metaRedacted['data']);
        $meta['_redaction'] = [
            'count' => (int) ($metaRedacted['count'] ?? 0),
            'version' => (string) ($metaRedacted['version'] ?? 'v2'),
        ];

        if ((int) $meta['_redaction']['count'] > 0) {
            Log::info('AUDIT_LOG_REDACTED', [
                'action' => $action,
                'count' => (int) $meta['_redaction']['count'],
                'version' => (string) $meta['_redaction']['version'],
            ]);
        }

        $actorAdminId = null;
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();
        if ($user && property_exists($user, 'id')) {
            $actorAdminId = (int) $user->id;
        }

        if ($actorAdminId === null) {
            $meta['actor'] = $meta['actor'] ?? 'admin_token';
        }

        $orgId = (int) app(OrgContext::class)->orgId();
        if ($orgId <= 0) {
            $rawOrgId = $request->attributes->get('org_id', $request->attributes->get('fm_org_id', 0));
            if (is_numeric($rawOrgId)) {
                $orgId = max(0, (int) $rawOrgId);
            }
        }

        AuditLog::create([
            'org_id' => $orgId,
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
