<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ops;

use App\Services\Ops\EnneagramRegistryOpsService;
use Tests\TestCase;

final class EnneagramRegistryOpsServiceTest extends TestCase
{
    public function test_preview_loads_registry_pack_and_release_hash_summary(): void
    {
        $service = app(EnneagramRegistryOpsService::class);

        $preview = $service->preview();

        $this->assertSame('ENNEAGRAM', $preview['scale_code']);
        $this->assertSame('enneagram_registry.v1', $preview['registry_version']);
        $this->assertStringStartsWith('sha256:', (string) $preview['registry_release_hash']);
        $this->assertSame('passed', data_get($preview, 'validation.status'));
        $this->assertNotEmpty((array) $preview['registry_files']);
        $this->assertArrayHasKey('p0_ready', (array) $preview['content_maturity_summary']);
        $this->assertArrayHasKey('descriptive', (array) $preview['evidence_level_summary']);
    }
}
