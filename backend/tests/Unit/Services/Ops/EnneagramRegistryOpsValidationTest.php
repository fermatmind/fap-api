<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ops;

use App\Services\Content\EnneagramPackLoader;
use App\Services\Ops\EnneagramRegistryOpsService;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

final class EnneagramRegistryOpsValidationTest extends TestCase
{
    private string $typeRegistryPath = '';

    private string $typeRegistryBackup = '';

    protected function setUp(): void
    {
        parent::setUp();

        $loader = app(EnneagramPackLoader::class);
        $this->typeRegistryPath = $loader->registryPath('type_registry.json');
        $this->typeRegistryBackup = (string) File::get($this->typeRegistryPath);
    }

    protected function tearDown(): void
    {
        if ($this->typeRegistryPath !== '' && $this->typeRegistryBackup !== '') {
            File::put($this->typeRegistryPath, $this->typeRegistryBackup);
        }

        parent::tearDown();
    }

    public function test_publish_blocks_when_registry_validation_fails(): void
    {
        $decoded = json_decode($this->typeRegistryBackup, true);
        $this->assertIsArray($decoded);
        $this->assertIsArray($decoded['entries'] ?? null);

        $decoded['content_maturity'] = 'invalid_maturity';

        File::put($this->typeRegistryPath, (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $service = app(EnneagramRegistryOpsService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ENNEAGRAM_REGISTRY_VALIDATION_FAILED');

        $service->publish('ops_validation_test');
    }
}
