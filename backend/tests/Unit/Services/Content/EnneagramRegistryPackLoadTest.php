<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Content;

use App\Services\Content\EnneagramPackLoader;
use App\Services\Enneagram\Registry\RegistryValidator;
use Tests\TestCase;

final class EnneagramRegistryPackLoadTest extends TestCase
{
    public function test_registry_files_can_load_and_validate(): void
    {
        $loader = app(EnneagramPackLoader::class);
        $pack = $loader->loadRegistryPack();
        $uiEntries = (array) data_get($pack, 'ui_copy_registry.entries', []);
        $sampleEntries = (array) data_get($pack, 'sample_report_registry.entries', []);
        $technicalEntries = collect((array) data_get($pack, 'technical_note_registry.entries', []));
        $typeEntries = collect((array) data_get($pack, 'type_registry.entries', []));

        $this->assertSame('ENNEAGRAM', data_get($pack, 'manifest.scale_code'));
        $this->assertSame('enneagram_registry.v1', data_get($pack, 'manifest.registry_version'));
        $this->assertSame('enneagram_registry_pack_v1_p0_ready_2026_04', data_get($pack, 'manifest.release_id'));
        $this->assertSame('enneagram_type_registry', data_get($pack, 'type_registry.registry_key'));
        $this->assertSame('enneagram_method_registry', data_get($pack, 'method_registry.registry_key'));
        $this->assertSame('查看 Technical Note v0.1', data_get($uiEntries['technical_note.link_label'] ?? [], 'label'));
        $this->assertSame('clear_sample', data_get($sampleEntries['clear_sample'] ?? [], 'sample_key'));
        $this->assertSame('privacy', data_get($technicalEntries->firstWhere('section_key', 'privacy') ?? [], 'section_key'));
        $this->assertNotSame('', (string) data_get($typeEntries->firstWhere('type_id', '8') ?? [], 'deep_dive.core_desire'));
        $this->assertSame('p0_ready', data_get($pack, 'type_registry.content_maturity'));
        $this->assertSame('experimental', data_get($pack, 'theory_hint_registry.content_maturity'));
        $this->assertStringStartsWith('sha256:', (string) ($pack['release_hash'] ?? ''));

        $errors = app(RegistryValidator::class)->validate($pack);

        $this->assertSame([], $errors);
    }

    public function test_registry_release_hash_is_stable(): void
    {
        $loader = app(EnneagramPackLoader::class);

        $first = $loader->resolveRegistryReleaseHash();
        $second = $loader->resolveRegistryReleaseHash();

        $this->assertNotSame('', $first);
        $this->assertSame($first, $second);
    }
}
