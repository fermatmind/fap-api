<?php

namespace App\Services\Memory;

use App\Services\AI\Embeddings\EmbeddingClient;
use App\Services\VectorStore\VectorStoreManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;

final class MemoryService
{
    private const DEFAULT_EXPORT_LIMIT = 200;
    private const MAX_EXPORT_LIMIT = 500;

    public function propose(int $userId, array $payload): array
    {
        if (!\App\Support\SchemaBaseline::hasTable('memories')) {
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
        if (!\App\Support\SchemaBaseline::hasTable('memories')) {
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
        if (!\App\Support\SchemaBaseline::hasTable('memories')) {
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

    public function exportConfirmedPage(int $userId, int $limit, ?string $cursor): array
    {
        if (!\App\Support\SchemaBaseline::hasTable('memories')) {
            return [
                'ok' => false,
                'error' => 'memories_table_missing',
                'items' => [],
                'next_cursor' => null,
                'truncated' => false,
            ];
        }

        $safeLimit = min(self::MAX_EXPORT_LIMIT, max(1, $limit > 0 ? $limit : self::DEFAULT_EXPORT_LIMIT));
        $cursorPayload = $this->decodeExportCursor($cursor);
        if ($cursorPayload === null && $cursor !== null && trim($cursor) !== '') {
            return [
                'ok' => false,
                'error' => 'invalid_cursor',
                'items' => [],
                'next_cursor' => null,
                'truncated' => false,
            ];
        }

        $query = $this->confirmedQuery($userId);

        if ($cursorPayload !== null) {
            $query->where(function (Builder $builder) use ($cursorPayload): void {
                $builder
                    ->where('confirmed_at', '<', $cursorPayload['confirmed_at'])
                    ->orWhere(function (Builder $nested) use ($cursorPayload): void {
                        $nested
                            ->where('confirmed_at', '=', $cursorPayload['confirmed_at'])
                            ->where('id', '<', $cursorPayload['id']);
                    });
            });
        }

        $rows = $query
            ->limit($safeLimit + 1)
            ->get();

        $hasMore = $rows->count() > $safeLimit;
        $items = $hasMore ? $rows->slice(0, $safeLimit)->values() : $rows->values();

        $nextCursor = null;
        if ($hasMore && $items->isNotEmpty()) {
            $tail = $items->last();
            $nextCursor = $this->encodeExportCursor((string) ($tail->confirmed_at ?? ''), (string) ($tail->id ?? ''));
        }

        return [
            'ok' => true,
            'items' => $items,
            'next_cursor' => $nextCursor,
            'truncated' => $hasMore,
        ];
    }

    public function exportConfirmedCursor(int $userId): LazyCollection
    {
        if (!\App\Support\SchemaBaseline::hasTable('memories')) {
            return LazyCollection::make([]);
        }

        return $this->confirmedQuery($userId)->cursor();
    }

    private function confirmedQuery(int $userId): Builder
    {
        return DB::table('memories')
            ->where('user_id', $userId)
            ->where('status', 'confirmed')
            ->whereNotNull('confirmed_at')
            ->orderByDesc('confirmed_at')
            ->orderByDesc('id');
    }

    private function encodeExportCursor(string $confirmedAt, string $id): ?string
    {
        $confirmedAt = trim($confirmedAt);
        $id = trim($id);
        if ($confirmedAt === '' || $id === '') {
            return null;
        }

        $json = json_encode([
            'confirmed_at' => $confirmedAt,
            'id' => $id,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($json)) {
            return null;
        }

        return base64_encode($json);
    }

    /**
     * @return array{confirmed_at:string,id:string}|null
     */
    private function decodeExportCursor(?string $cursor): ?array
    {
        $cursor = trim((string) $cursor);
        if ($cursor === '') {
            return null;
        }

        $decoded = base64_decode($cursor, true);
        if (!is_string($decoded) || $decoded === '') {
            return null;
        }

        $payload = json_decode($decoded, true);
        if (!is_array($payload)) {
            return null;
        }

        $confirmedAt = trim((string) ($payload['confirmed_at'] ?? ''));
        $id = trim((string) ($payload['id'] ?? ''));
        if ($confirmedAt === '' || $id === '') {
            return null;
        }

        return [
            'confirmed_at' => $confirmedAt,
            'id' => $id,
        ];
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
