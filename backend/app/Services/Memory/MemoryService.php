<?php

namespace App\Services\Memory;

use App\Services\AI\Embeddings\EmbeddingClient;
use App\Services\VectorStore\VectorStoreManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class MemoryService
{
    public function propose(int $userId, array $payload): array
    {
        if (!Schema::hasTable('memories')) {
            return ['ok' => false, 'error' => 'memories_table_missing'];
        }

        $id = (string) Str::uuid();
        $content = trim((string) ($payload['content'] ?? ''));
        if ($content === '') {
            return ['ok' => false, 'error' => 'content_required'];
        }

        $contentHash = hash('sha256', $content);
        $now = now();
        DB::table('memories')->insert([
            'id' => $id,
            'user_id' => $userId,
            'status' => 'proposed',
            'kind' => (string) ($payload['kind'] ?? 'note'),
            'title' => $payload['title'] ?? null,
            'content' => $content,
            'content_hash' => $contentHash,
            'tags_json' => json_encode($payload['tags'] ?? [], JSON_UNESCAPED_UNICODE),
            'evidence_json' => json_encode($payload['evidence'] ?? [], JSON_UNESCAPED_UNICODE),
            'source_refs_json' => json_encode($payload['source_refs'] ?? [], JSON_UNESCAPED_UNICODE),
            'consent_version' => $payload['consent_version'] ?? null,
            'proposed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['ok' => true, 'id' => $id, 'content_hash' => $contentHash];
    }

    public function confirm(int $userId, string $memoryId): array
    {
        if (!Schema::hasTable('memories')) {
            return ['ok' => false, 'error' => 'memories_table_missing'];
        }

        $row = DB::table('memories')
            ->where('id', $memoryId)
            ->where('user_id', $userId)
            ->first();

        if (!$row) {
            return ['ok' => false, 'error' => 'memory_not_found'];
        }

        $now = now();
        DB::table('memories')
            ->where('id', $memoryId)
            ->update([
                'status' => 'confirmed',
                'confirmed_at' => $now,
                'updated_at' => $now,
            ]);

        $embeddingResult = $this->embedMemory($row);

        return [
            'ok' => true,
            'id' => $memoryId,
            'embedded' => $embeddingResult['ok'] ?? false,
        ];
    }

    public function delete(int $userId, string $memoryId): array
    {
        if (!Schema::hasTable('memories')) {
            return ['ok' => false, 'error' => 'memories_table_missing'];
        }

        $now = now();
        $updated = DB::table('memories')
            ->where('id', $memoryId)
            ->where('user_id', $userId)
            ->update([
                'status' => 'deleted',
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);

        return ['ok' => $updated > 0, 'deleted' => $updated];
    }

    public function exportConfirmed(int $userId): array
    {
        if (!Schema::hasTable('memories')) {
            return ['ok' => false, 'error' => 'memories_table_missing', 'items' => []];
        }

        $rows = DB::table('memories')
            ->where('user_id', $userId)
            ->where('status', 'confirmed')
            ->orderByDesc('confirmed_at')
            ->get();

        return ['ok' => true, 'items' => $rows];
    }

    private function embedMemory(object $row): array
    {
        $embeddingClient = app(EmbeddingClient::class);
        $embed = $embeddingClient->embed((string) ($row->content ?? ''), [
            'subject' => 'memory_embedding',
        ]);

        if (!($embed['ok'] ?? false)) {
            return $embed;
        }

        $vectorStore = app(VectorStoreManager::class);
        return $vectorStore->upsert((string) config('memory.default_namespace', 'memory'), [[
            'id' => (string) ($row->id ?? ''),
            'owner_type' => 'memory',
            'owner_id' => (string) ($row->id ?? ''),
            'model' => (string) ($embed['model'] ?? 'mock-embedding'),
            'dim' => (int) ($embed['dim'] ?? 0),
            'content_hash' => (string) ($row->content_hash ?? ''),
            'vector' => $embed['vector'] ?? [],
            'meta' => [
                'user_id' => (string) ($row->user_id ?? ''),
                'kind' => (string) ($row->kind ?? ''),
            ],
            'content' => (string) ($row->content ?? ''),
        ]]);
    }
}
