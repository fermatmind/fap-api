<?php

namespace App\Services\Agent\Notifiers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class InAppNotifier
{
    public function send(int $userId, array $payload): array
    {
        if (!Schema::hasTable('agent_messages')) {
            return ['ok' => false, 'error' => 'agent_messages_missing'];
        }

        $idempotencyKey = (string) ($payload['idempotency_key'] ?? '');
        if ($idempotencyKey !== '') {
            $existing = DB::table('agent_messages')->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return [
                    'ok' => true,
                    'id' => (string) ($existing->id ?? ''),
                    'status' => (string) ($existing->status ?? 'sent'),
                    'idempotent' => true,
                ];
            }
        }

        $why = $payload['why_json'] ?? [];
        if (empty($why)) {
            $why = ['trigger_type' => 'unknown', 'generated_at' => now()->toIso8601String()];
        }
        $evidence = $payload['evidence_json'] ?? [];
        if (empty($evidence)) {
            $evidence = [['type' => 'system', 'note' => 'no_evidence']];
        }

        $id = (string) Str::uuid();
        $now = now();

        DB::table('agent_messages')->insert([
            'id' => $id,
            'user_id' => $userId,
            'decision_id' => $payload['decision_id'] ?? null,
            'channel' => 'in_app',
            'status' => 'sent',
            'title' => $payload['title'] ?? null,
            'body' => (string) ($payload['body'] ?? ''),
            'template_key' => $payload['template_key'] ?? null,
            'content_hash' => $payload['content_hash'] ?? null,
            'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
            'why_json' => json_encode($why, JSON_UNESCAPED_UNICODE),
            'evidence_json' => json_encode($evidence, JSON_UNESCAPED_UNICODE),
            'sent_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'ok' => true,
            'id' => $id,
            'status' => 'sent',
        ];
    }
}
