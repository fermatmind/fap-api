<?php

declare(strict_types=1);

namespace App\Services\Riasec;

use App\Services\Content\RiasecPrivateResultCompileService;
use App\Services\Content\RiasecPrivateResultPackLoader;
use RuntimeException;

final class RiasecPrivateResultSourceRepository
{
    /** @var array<string,array<string,mixed>> */
    private array $loaded = [];

    public function __construct(private readonly ?RiasecPrivateResultPackLoader $loader = null) {}

    /** @return array<string,mixed> */
    public function runtimeCopy(): array
    {
        $decoded = $this->asset('runtime_surface_copy_v1.zh-CN.json');
        if (! is_array($decoded)
            || ($decoded['schema_version'] ?? null) !== 'riasec.runtime_surface_copy.v1'
            || ($decoded['locale'] ?? null) !== 'zh-CN'
            || ($decoded['frontend_fallback_allowed'] ?? true) !== false) {
            throw new RuntimeException('RIASEC canonical runtime surface copy is missing or invalid.');
        }

        return $decoded;
    }

    public function get(string $path, mixed $default = null): mixed
    {
        return data_get($this->runtimeCopy(), $path, $default);
    }

    /** @return array<string,mixed>|list<array<string,mixed>> */
    public function asset(string $filename, string $locale = 'zh-CN'): array
    {
        $assets = $this->loaded($locale)['assets'];
        $asset = $assets[$filename] ?? null;
        if (! is_array($asset)) {
            throw new RuntimeException("RIASEC canonical runtime asset is missing: {$filename}");
        }

        return $asset;
    }

    /** @return array<string,mixed> */
    public function authority(): array
    {
        return $this->loaded()['authority'];
    }

    /** @return array{assets:array<string,mixed>,authority:array<string,mixed>,payload:array<string,mixed>} */
    private function loaded(string $locale = 'zh-CN'): array
    {
        $locale = str_starts_with(strtolower($locale), 'zh') ? 'zh-CN' : $locale;
        if (isset($this->loaded[$locale])) {
            return $this->loaded[$locale];
        }
        if ($this->loader !== null) {
            return $this->loaded[$locale] = $this->loader->load($locale);
        }
        if (function_exists('app') && app()->bound('env')) {
            return $this->loaded[$locale] = app(RiasecPrivateResultPackLoader::class)->load($locale);
        }

        $payload = (new RiasecPrivateResultCompileService)->compile()['payload'];

        return $this->loaded[$locale] = RiasecPrivateResultPackLoader::localeProjection($payload, $locale);
    }
}
