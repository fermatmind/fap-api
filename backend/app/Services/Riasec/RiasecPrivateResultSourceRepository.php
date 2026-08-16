<?php

declare(strict_types=1);

namespace App\Services\Riasec;

use RuntimeException;

final class RiasecPrivateResultSourceRepository
{
    /** @var array<string,mixed>|null */
    private ?array $runtimeCopy = null;

    /** @return array<string,mixed> */
    public function runtimeCopy(): array
    {
        if ($this->runtimeCopy !== null) {
            return $this->runtimeCopy;
        }

        $path = dirname(__DIR__, 3).'/content_assets/riasec/runtime_surface_copy_v1.zh-CN.json';
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($decoded)
            || ($decoded['schema_version'] ?? null) !== 'riasec.runtime_surface_copy.v1'
            || ($decoded['locale'] ?? null) !== 'zh-CN'
            || ($decoded['frontend_fallback_allowed'] ?? true) !== false) {
            throw new RuntimeException('RIASEC canonical runtime surface copy is missing or invalid.');
        }

        return $this->runtimeCopy = $decoded;
    }

    public function get(string $path, mixed $default = null): mixed
    {
        return data_get($this->runtimeCopy(), $path, $default);
    }
}
