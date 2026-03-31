<?php

declare(strict_types=1);

namespace Tests\Feature\PersonalityCms;

use Tests\TestCase;

final class PersonalityDesktopCloneOpsResourceTest extends TestCase
{
    public function test_ops_resource_exposes_asset_slot_fields_for_minimal_management(): void
    {
        $source = file_get_contents(app_path('Filament/Ops/Resources/PersonalityVariantCloneContentResource.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("Repeater::make('asset_slots_json')", $source);
        $this->assertStringContainsString("Select::make('slot_id')", $source);
        $this->assertStringContainsString("TextInput::make('aspect_ratio')", $source);
        $this->assertStringContainsString("Select::make('status')", $source);
        $this->assertStringContainsString("TextInput::make('asset_ref.provider')", $source);
        $this->assertStringContainsString("TextInput::make('asset_ref.path')", $source);
        $this->assertStringContainsString("TextInput::make('asset_ref.url')", $source);
        $this->assertStringContainsString("TextInput::make('alt')", $source);
    }
}
