<?php

namespace App\Services\VectorStore\Drivers;

use App\Services\VectorStore\VectorStoreInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class MySqlFallbackDriver implements VectorStoreInterface
{
    public function driverName(): string
    {
        return 'mysql_fallback';
    }

    public function health(): array
    {
        if (!\App\Support\SchemaBaseline::hasTable('embeddings')) {
            return [
                'ok' => false,
                'error' => 'embeddings_table_missing',
            ];
        }

        try {
            DB::table('embeddings')->limit(1)->get();
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => 'embeddings_table_unavailable',
            ];
        }

        return [
            'ok' => true,
            'driver' => $this->driverName(),
        ];
    }

    public function upsert(string $namespace, array $items): array
    {
        if (!\App\Support\SchemaBaseline::hasTable('embeddings')) {
            return [
                'ok' => false,
                'error' => 'embeddings_table_missing',
            ];
        }

        $now = now();
        $count = 0;
        foreach ($items as $item) {
            $id = (string) ($item['id'] ?? Str::uuid());
            $vector = $item['vector'] ?? [];
            $payload = [
                'id' => $id,
                'namespace' => $namespace,
                'owner_type' => (string) ($item['owner_type'] ?? 'memory'),
                'owner_id' => (string) ($item['owner_id'] ?? ''),
                'model' => (string) ($item['model'] ?? 'mock-embedding'),
                'dim' => (int) ($item['dim'] ?? count($vector)),
                'content_hash' => (string) ($item['content_hash'] ?? ''),
                'vector_json' => json_encode($vector, JSON_UNESCAPED_UNICODE),
                'meta_json' => json_encode($item['meta'] ?? [], JSON_UNESCAPED_UNICODE),
                'content' => $item['content'] ?? null,
                'updated_at' => $now,
            ];

            $exists = DB::table('embeddings')->where('id', $id)->exists();
            if ($exists) {
                DB::table('embeddings')->where('id', $id)->update($payload);
            } else {
                $payload['created_at'] = $now;
                DB::table('embeddings')->insert($payload);
            }
            $count++;
        }

        return [
            'ok' => true,
            'driver' => $this->driverName(),
            'upserted' => $count,
        ];
    }

    public function query(string $namespace, array $vector, int $topK, array $filters = []): array
    {
        if (!\App\Support\SchemaBaseline::hasTable('embeddings')) {
            return [
                'ok' => false,
                'error' => 'embeddings_table_missing',
                'matches' => [],
            ];
        }

        $topK = $topK > 0 ? $topK : (int) config('vectorstore.mysql_fallback.top_k', 10);
        $topK = min(50, max(1, $topK));

        $query = DB::table('embeddings')->where('namespace', $namespace);
        if (!empty($filters['owner_type'])) {
            $query->where('owner_type', (string) $filters['owner_type']);
        }
        if (!empty($filters['owner_id'])) {
            $query->where('owner_id', (string) $filters['owner_id']);
        }
        if (!empty($filters['content_hash'])) {
            $query->where('content_hash', (string) $filters['content_hash']);
        }

        $rows = $query->limit(200)->get();
        $matches = [];
        foreach ($rows as $row) {
            $rowVector = $this->decodeVector($row->vector_json ?? null);
            if (empty($rowVector)) {
                continue;
            }

            $meta = $this->decodeMeta($row->meta_json ?? null);
            if (!empty($filters['user_id'])) {
                $metaUser = (string) ($meta['user_id'] ?? '');
                if ($metaUser !== (string) $filters['user_id']) {
                    continue;
                }
            }

            $score = $this->cosineSimilarity($vector, $rowVector);
            $matches[] = [
                'id' => (string) ($row->id ?? ''),
                'owner_type' => (string) ($row->owner_type ?? ''),
                'owner_id' => (string) ($row->owner_id ?? ''),
                'score' => $score,
                'content' => $row->content ?? null,
                'meta' => $meta,
            ];
        }

        usort($matches, function ($a, $b) {
            return ($b['score'] <=> $a['score']);
        });

        return [
            'ok' => true,
            'driver' => $this->driverName(),
            'matches' => array_slice($matches, 0, $topK),
        ];
    }

    public function delete(string $namespace, array $ids): array
    {
        if (!\App\Support\SchemaBaseline::hasTable('embeddings')) {
            return [
                'ok' => false,
                'error' => 'embeddings_table_missing',
            ];
        }

        $ids = array_values(array_filter($ids, fn ($id) => is_string($id) && $id !== ''));
        if (empty($ids)) {
            return [
                'ok' => true,
                'driver' => $this->driverName(),
                'deleted' => 0,
            ];
        }

        $deleted = DB::table('embeddings')
            ->where('namespace', $namespace)
            ->whereIn('id', $ids)
            ->delete();

        return [
            'ok' => true,
            'driver' => $this->driverName(),
            'deleted' => $deleted,
        ];
    }

    private function decodeVector($raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_map(fn ($v) => (float) $v, $decoded);
    }

    private function decodeMeta($raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        $len = min(count($a), count($b));
        if ($len === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        for ($i = 0; $i < $len; $i++) {
            $va = (float) $a[$i];
            $vb = (float) $b[$i];
            $dot += $va * $vb;
            $normA += $va * $va;
            $normB += $vb * $vb;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
