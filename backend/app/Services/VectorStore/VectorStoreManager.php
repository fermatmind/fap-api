<?php

namespace App\Services\VectorStore;

use App\Services\VectorStore\Drivers\MySqlFallbackDriver;
use App\Services\VectorStore\Drivers\QdrantDriver;

final class VectorStoreManager implements VectorStoreInterface
{
    private ?VectorStoreInterface $driver = null;
    private ?VectorStoreInterface $fallback = null;

    public function driverName(): string
    {
        return $this->driver()->driverName();
    }

    public function health(): array
    {
        $driver = $this->driver();
        $result = $driver->health();
        if (($result['ok'] ?? false) || $driver instanceof MySqlFallbackDriver) {
            return $result;
        }

        if ($this->shouldFailOpen()) {
            return $this->fallback()->health();
        }

        return $result;
    }

    public function upsert(string $namespace, array $items): array
    {
        $driver = $this->driver();
        $result = $driver->upsert($namespace, $items);
        if (($result['ok'] ?? false) || $driver instanceof MySqlFallbackDriver) {
            return $result;
        }

        if ($this->shouldFailOpen()) {
            return $this->fallback()->upsert($namespace, $items);
        }

        return $result;
    }

    public function query(string $namespace, array $vector, int $topK, array $filters = []): array
    {
        $driver = $this->driver();
        $result = $driver->query($namespace, $vector, $topK, $filters);
        if (($result['ok'] ?? false) || $driver instanceof MySqlFallbackDriver) {
            return $result;
        }

        if ($this->shouldFailOpen()) {
            return $this->fallback()->query($namespace, $vector, $topK, $filters);
        }

        return $result;
    }

    public function delete(string $namespace, array $ids): array
    {
        $driver = $this->driver();
        $result = $driver->delete($namespace, $ids);
        if (($result['ok'] ?? false) || $driver instanceof MySqlFallbackDriver) {
            return $result;
        }

        if ($this->shouldFailOpen()) {
            return $this->fallback()->delete($namespace, $ids);
        }

        return $result;
    }

    private function driver(): VectorStoreInterface
    {
        if ($this->driver instanceof VectorStoreInterface) {
            return $this->driver;
        }

        $driverName = (string) config('vectorstore.driver', 'mysql_fallback');
        $enabled = (bool) config('vectorstore.enabled', true);

        if (!$enabled) {
            $this->driver = $this->fallback();
            return $this->driver;
        }

        if ($driverName === 'qdrant' && (bool) config('vectorstore.qdrant.enabled', false)) {
            $this->driver = new QdrantDriver();
            return $this->driver;
        }

        $this->driver = $this->fallback();
        return $this->driver;
    }

    private function fallback(): VectorStoreInterface
    {
        if ($this->fallback instanceof VectorStoreInterface) {
            return $this->fallback;
        }

        $this->fallback = new MySqlFallbackDriver();

        return $this->fallback;
    }

    private function shouldFailOpen(): bool
    {
        return (bool) config('vectorstore.fail_open', true);
    }
}
