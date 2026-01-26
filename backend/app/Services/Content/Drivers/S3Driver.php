<?php

declare(strict_types=1);

namespace App\Services\Content\Drivers;

use App\Contracts\ContentSourceDriver;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class S3Driver implements ContentSourceDriver
{
    private string $diskName;
    private string $prefix;

    public function __construct(string $disk, string $prefix = '')
    {
        $this->diskName = $disk;
        $this->prefix = trim($prefix, '/');
    }

    public function get(string $key): string
    {
        $fullKey = $this->fullKey($key);
        return $this->disk()->get($fullKey);
    }

    public function exists(string $key): bool
    {
        $fullKey = $this->fullKey($key);
        return $this->disk()->exists($fullKey);
    }

    public function list(string $prefix): array
    {
        $fullPrefix = $this->fullKey($prefix, true);
        $disk = $this->disk();

        $files = $disk->allFiles($fullPrefix);
        $shallow = $disk->files($fullPrefix);
        if ($shallow !== []) {
            $files = array_values(array_unique(array_merge($shallow, $files)));
        }

        $relative = [];
        $strip = $this->prefix === '' ? '' : $this->prefix . '/';
        foreach ($files as $fullKey) {
            $key = ltrim((string)$fullKey, '/');
            if ($strip !== '' && str_starts_with($key, $strip)) {
                $key = substr($key, strlen($strip));
            }
            $relative[] = $key;
        }

        return $relative;
    }

    public function etag(string $key): ?string
    {
        $fullKey = $this->fullKey($key);
        $disk = $this->disk();

        if (!method_exists($disk, 'checksum')) {
            return null;
        }

        try {
            $checksum = $disk->checksum($fullKey);
            return $checksum !== '' ? $checksum : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function fullKey(string $key, bool $allowEmpty = false): string
    {
        $key = $this->normalizeKey($key);
        if ($key === '' && !$allowEmpty) {
            throw new RuntimeException('Content key cannot be empty.');
        }

        $full = $this->prefix;
        if ($full !== '') {
            $full .= '/';
        }
        $full .= ltrim($key, '/');

        return $full;
    }

    private function normalizeKey(string $key): string
    {
        $key = ltrim($key, '/');

        if (str_contains($key, '..')) {
            throw new RuntimeException('Invalid content key (.. not allowed).');
        }

        return $key;
    }

    private function disk()
    {
        return Storage::disk($this->diskName);
    }
}
