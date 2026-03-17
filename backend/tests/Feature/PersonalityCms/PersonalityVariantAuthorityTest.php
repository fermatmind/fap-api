<?php

declare(strict_types=1);

namespace Tests\Feature\PersonalityCms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantSection;
use App\Models\PersonalityProfileVariantSeoMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PersonalityVariantAuthorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_imported_variant_authority_rows_can_resolve_published_public_aliases_while_canonical_stays_base_only(): void
    {
        $this->artisan('personality:import-local-baseline', [
            '--locale' => ['en'],
            '--type' => ['ENFP'],
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => 'tests/Fixtures/personality_baseline',
        ])
            ->expectsOutputToContain('profiles_found=1')
            ->expectsOutputToContain('variants_found=2')
            ->expectsOutputToContain('variant_will_create=2')
            ->assertExitCode(0);

        $profile = PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->where('type_code', 'ENFP')
            ->where('locale', 'en')
            ->firstOrFail();

        $assertive = PersonalityProfileVariant::query()
            ->where('personality_profile_id', (int) $profile->id)
            ->where('runtime_type_code', 'ENFP-A')
            ->firstOrFail();

        $turbulent = PersonalityProfileVariant::query()
            ->where('personality_profile_id', (int) $profile->id)
            ->where('runtime_type_code', 'ENFP-T')
            ->firstOrFail();

        $this->assertSame('ENFP', $profile->canonical_type_code);
        $this->assertSame('A', $assertive->variant_code);
        $this->assertSame('ENFP-A', $assertive->runtime_type_code);
        $this->assertSame('T', $turbulent->variant_code);
        $this->assertSame('ENFP-T', $turbulent->runtime_type_code);
        $this->assertSame(2, PersonalityProfileVariantSection::query()->count());
        $this->assertSame(2, PersonalityProfileVariantSeoMeta::query()->count());

        $this->getJson('/api/v0.5/personality/enfp?locale=en')
            ->assertOk()
            ->assertJsonPath('profile.type_code', 'ENFP')
            ->assertJsonPath('profile.canonical_type_code', 'ENFP')
            ->assertJsonPath('mbti_public_projection_v1.runtime_type_code', null)
            ->assertJsonPath('mbti_public_projection_v1.canonical_type_code', 'ENFP');

        $this->getJson('/api/v0.5/personality/enfp-t?locale=en')
            ->assertOk()
            ->assertJsonPath('profile.type_code', 'ENFP')
            ->assertJsonPath('profile.canonical_type_code', 'ENFP')
            ->assertJsonPath('mbti_public_projection_v1.runtime_type_code', 'ENFP-T')
            ->assertJsonPath('mbti_public_projection_v1.display_type', 'ENFP-T')
            ->assertJsonPath('mbti_public_projection_v1.variant_code', 'T')
            ->assertJsonPath('mbti_public_projection_v1._meta.public_route_type', '16-type');

        $this->getJson('/api/v0.5/personality/enfp-a?locale=en')
            ->assertStatus(404);
    }
}
