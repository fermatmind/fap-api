<?php

namespace App\Services\Memory;

use App\Services\AI\Embeddings\EmbeddingClient;
use App\Services\VectorStore\VectorStoreManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MemoryRetriever
{
    public function search(int $userId, string $query, array $filters = []): array
    {
        if (!Schema::hasTable('memories')) {
            return ['ok' => false, 'error' => 'memories_table_missing', 'items' => []];
        }

        $query = trim($query);
        if ($query === '') {
            return ['ok' => true, 'items' => []];
        }

        $embeddingClient = app(EmbeddingClient::class);
        $embed = $embeddingClient->embed($query, [
            'subject' => 'memory_query',
        ]);

        if (!($embed['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'embedding_failed', 'items' => []];
        }

        $vectorStore = app(VectorStoreManager::class);
        $namespace = (string) config('memory.default_namespace', 'memory');
        $matches = $vectorStore->query($namespace, $embed['vector'] ?? [], 10, [
            'owner_type' => 'memory',
            'user_id' => (string) $userId,
        ]);

        $ids = [];
        foreach ($matches['matches'] ?? [] as $match) {
            if (!empty($match['id'])) {
                $ids[] = (string) $match['id'];
            }
        }

        if (empty($ids)) {
            return ['ok' => true, 'items' => []];
        }

        $rows = DB::table('memories')
            ->where('user_id', $userId)
            ->where('status', 'confirmed')
            ->whereIn('id', $ids)
            ->get();

        return [
            'ok' => true,
            'items' => $rows,
            'matches' => $matches['matches'] ?? [],
        ];
    }
}
