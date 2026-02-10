<?php

namespace App\Services\VectorStore\Drivers;

use App\Services\VectorStore\VectorStoreInterface;
use App\Support\Http\ResilientClient;

final class QdrantDriver implements VectorStoreInterface
{
    public function driverName(): string
    {
        return 'qdrant';
    }

    public function health(): array
    {
        $endpoint = rtrim((string) config('vectorstore.qdrant.endpoint', ''), '/');
        if ($endpoint === '') {
            return [
                'ok' => false,
                'error' => 'qdrant_endpoint_missing',
            ];
        }

        try {
            $resp = ResilientClient::get($endpoint . '/healthz');
            if ($resp->ok()) {
                return [
                    'ok' => true,
                    'driver' => $this->driverName(),
                    'status' => $resp->json() ?? [],
                ];
            }
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => 'qdrant_unreachable',
            ];
        }

        return [
            'ok' => false,
            'error' => 'qdrant_unhealthy',
        ];
    }

    public function upsert(string $namespace, array $items): array
    {
        return [
            'ok' => false,
            'error' => 'qdrant_upsert_not_implemented',
        ];
    }

    public function query(string $namespace, array $vector, int $topK, array $filters = []): array
    {
        return [
            'ok' => false,
            'error' => 'qdrant_query_not_implemented',
            'matches' => [],
        ];
    }

    public function delete(string $namespace, array $ids): array
    {
        return [
            'ok' => false,
            'error' => 'qdrant_delete_not_implemented',
        ];
    }
}
