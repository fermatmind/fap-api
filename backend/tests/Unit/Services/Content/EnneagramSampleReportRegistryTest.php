<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Content;

use App\Services\Content\EnneagramPackLoader;
use Tests\TestCase;

final class EnneagramSampleReportRegistryTest extends TestCase
{
    public function test_sample_report_registry_contains_p0_ready_clear_close_call_and_diffuse_samples(): void
    {
        $loader = app(EnneagramPackLoader::class);
        $entries = collect((array) data_get($loader->loadRegistryPack(), 'sample_report_registry.entries', []));

        $this->assertSame(['clear_sample', 'close_call_sample', 'diffuse_sample'], $entries->keys()->sort()->values()->all());
        $this->assertFalse($entries->contains(fn ($entry): bool => trim((string) ($entry['short_summary'] ?? '')) === ''));
        $this->assertFalse($entries->contains(fn ($entry): bool => trim((string) ($entry['page_1_preview'] ?? '')) === ''));
        $this->assertFalse($entries->contains(fn ($entry): bool => trim((string) ($entry['method_boundary'] ?? '')) === ''));
        $this->assertFalse($entries->contains(fn ($entry): bool => ($entry['content_maturity'] ?? null) !== 'p0_ready'));
        $this->assertFalse($entries->contains(fn ($entry): bool => ($entry['evidence_level'] ?? null) !== 'descriptive'));
    }
}
