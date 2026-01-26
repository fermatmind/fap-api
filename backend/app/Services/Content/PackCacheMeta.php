<?php

declare(strict_types=1);

namespace App\Services\Content;

use RuntimeException;

final class PackCacheMeta
{
    public string $pack;
    public int $fetchedAt;
    public ?string $manifestEtag;
    public string $driver;
    /** @var array<string, string> */
    public array $source;

    /**
     * @param array<string, string> $source
     */
    public function __construct(string $pack, int $fetchedAt, ?string $manifestEtag, string $driver, array $source)
    {
        $this->pack = $pack;
        $this->fetchedAt = $fetchedAt;
        $this->manifestEtag = $manifestEtag;
        $this->driver = $driver;
        $this->source = $source;
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException("Pack cache meta not found: {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            throw new RuntimeException("Pack cache meta is empty: {$path}");
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException("Pack cache meta invalid json: {$path}");
        }

        $pack = $data['pack'] ?? null;
        $fetchedAt = $data['fetched_at'] ?? null;
        $driver = $data['driver'] ?? null;
        $source = $data['source'] ?? null;

        if (!is_string($pack) || $pack === '') {
            throw new RuntimeException("Pack cache meta missing pack: {$path}");
        }

        if (!is_int($fetchedAt)) {
            throw new RuntimeException("Pack cache meta missing fetched_at: {$path}");
        }

        if (!is_string($driver) || $driver === '') {
            throw new RuntimeException("Pack cache meta missing driver: {$path}");
        }

        if (!is_array($source)) {
            throw new RuntimeException("Pack cache meta missing source: {$path}");
        }

        $manifestEtag = $data['manifest_etag'] ?? null;
        if ($manifestEtag !== null && !is_string($manifestEtag)) {
            throw new RuntimeException("Pack cache meta invalid manifest_etag: {$path}");
        }

        return new self($pack, $fetchedAt, $manifestEtag, $driver, $source);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pack' => $this->pack,
            'fetched_at' => $this->fetchedAt,
            'manifest_etag' => $this->manifestEtag,
            'driver' => $this->driver,
            'source' => $this->source,
        ];
    }

    public function saveAtomic(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create meta dir: {$dir}");
        }

        $tmp = tempnam($dir, '.tmp-');
        if ($tmp === false) {
            throw new RuntimeException("Failed to create temp meta file in: {$dir}");
        }

        $json = json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            @unlink($tmp);
            throw new RuntimeException('Failed to encode pack cache meta to json.');
        }

        if (file_put_contents($tmp, $json) === false) {
            @unlink($tmp);
            throw new RuntimeException("Failed to write temp meta file: {$tmp}");
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Failed to move meta file into place: {$path}");
        }
    }
}
