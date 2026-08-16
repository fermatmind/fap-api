<?php

declare(strict_types=1);

namespace App\Services\Riasec;

use App\Services\Content\RiasecPrivateResultCompileService;
use App\Services\Content\RiasecPrivateResultPackLoader;
use RuntimeException;

final class RiasecPrivateResultSourceRepository
{
    /** @var array<string,mixed>|null */
    private ?array $loaded = null;

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
    public function asset(string $filename): array
    {
        $assets = $this->loaded()['assets'];
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
    private function loaded(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }
        if ($this->loader !== null) {
            return $this->loaded = $this->loader->load('zh-CN');
        }
        if (function_exists('app') && app()->bound('env')) {
            return $this->loaded = app(RiasecPrivateResultPackLoader::class)->load('zh-CN');
        }

        $payload = (new RiasecPrivateResultCompileService)->compile()['payload'];

        return $this->loaded = [
            'assets' => (array) $payload['assets'],
            'payload' => $payload,
            'authority' => [
                'schema_version' => 'fap.riasec.private_result_authority.v1',
                'authority_id' => RiasecPrivateResultCompileService::AUTHORITY_ID,
                'mode' => 'canonical',
                'locale' => 'zh-CN',
                'source_hash' => (string) $payload['source_hash'],
                'compiled_hash' => (string) $payload['compiled_hash'],
                'compiled_schema' => RiasecPrivateResultCompileService::SCHEMA,
                'compiler_schema' => RiasecPrivateResultCompileService::COMPILER_SCHEMA,
                'compiler_version' => RiasecPrivateResultCompileService::COMPILER_VERSION,
                'runtime_contract' => (string) $payload['runtime_contract'],
            ],
        ];
    }
}
