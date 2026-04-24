<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Content;

use App\Services\Content\EnneagramPackLoader;
use Tests\TestCase;

final class EnneagramRegistryManifestTest extends TestCase
{
    public function test_registry_manifest_contains_expected_registries(): void
    {
        $loader = app(EnneagramPackLoader::class);
        $manifest = $loader->loadRegistryManifest();

        $this->assertSame('ENNEAGRAM', $manifest['scale_code']);
        $this->assertSame('enneagram_registry.v1', $manifest['registry_version']);
        $this->assertSame(['all', 'e105', 'fc144'], $manifest['supported_form_variants']);
        $this->assertSame(['individual', 'workplace', 'team'], $manifest['supported_context_modes']);

        $registryKeys = collect((array) ($manifest['registries'] ?? []))->pluck('registry_key')->all();

        $this->assertSame([
            'enneagram_type_registry',
            'enneagram_pair_registry',
            'enneagram_group_registry',
            'enneagram_scenario_registry',
            'enneagram_state_registry',
            'enneagram_theory_hint_registry',
            'enneagram_observation_registry',
            'enneagram_method_registry',
            'enneagram_ui_copy_registry',
            'enneagram_sample_report_registry',
            'enneagram_technical_note_registry',
        ], $registryKeys);
    }
}
