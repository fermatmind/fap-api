<?php

declare(strict_types=1);

namespace Tests\Feature\PersonalityCms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PersonalityVariantAuthorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_variant_authority_rows_can_coexist_while_public_route_stays_base_only(): void
    {
        $profile = PersonalityProfile::query()->create([
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => 'ENFJ',
            'slug' => 'enfj',
            'locale' => 'en',
            'title' => 'ENFJ Personality',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);

        $assertive = PersonalityProfileVariant::query()->create([
            'personality_profile_id' => (int) $profile->id,
            'canonical_type_code' => 'ENFJ',
            'variant_code' => 'A',
            'runtime_type_code' => 'ENFJ-A',
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);

        $turbulent = PersonalityProfileVariant::query()->create([
            'personality_profile_id' => (int) $profile->id,
            'canonical_type_code' => 'ENFJ',
            'variant_code' => 'T',
            'runtime_type_code' => 'ENFJ-T',
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);

        $this->assertSame('ENFJ', $profile->canonical_type_code);
        $this->assertSame('A', $assertive->variant_code);
        $this->assertSame('ENFJ-A', $assertive->runtime_type_code);
        $this->assertSame('T', $turbulent->variant_code);
        $this->assertSame('ENFJ-T', $turbulent->runtime_type_code);

        $this->getJson('/api/v0.5/personality/enfj?locale=en')
            ->assertOk()
            ->assertJsonPath('profile.type_code', 'ENFJ')
            ->assertJsonPath('profile.canonical_type_code', 'ENFJ');

        $this->getJson('/api/v0.5/personality/enfj-a?locale=en')
            ->assertStatus(404);
    }
}
