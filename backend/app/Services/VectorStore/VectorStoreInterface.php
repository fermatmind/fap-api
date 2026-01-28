<?php

namespace App\Services\VectorStore;

interface VectorStoreInterface
{
    public function driverName(): string;

    public function health(): array;

    public function upsert(string $namespace, array $items): array;

    public function query(string $namespace, array $vector, int $topK, array $filters = []): array;

    public function delete(string $namespace, array $ids): array;
}
