<?php

declare(strict_types=1);

namespace Tests\Feature\PersonalityCms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileSeoMeta;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantSection;
use App\Models\PersonalityProfileVariantSeoMeta;
use App\Support\OrgContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PersonalityTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_personality_child_rows_inherit_parent_org_id(): void
    {
        $profile = $this->profile(23, 'INTJ');

        $section = PersonalityProfileSection::query()->withoutGlobalScopes()->create([
            'profile_id' => (int) $profile->id,
            'section_key' => 'hero',
            'render_variant' => 'rich_text',
            'body_md' => 'Hero body',
        ]);
        $seo = PersonalityProfileSeoMeta::query()->withoutGlobalScopes()->create([
            'profile_id' => (int) $profile->id,
            'seo_title' => 'INTJ SEO',
        ]);
        $variant = PersonalityProfileVariant::query()->withoutGlobalScopes()->create([
            'personality_profile_id' => (int) $profile->id,
            'canonical_type_code' => 'INTJ',
            'variant_code' => 'A',
            'runtime_type_code' => 'INTJ-A',
        ]);
        $variantSection = PersonalityProfileVariantSection::query()->withoutGlobalScopes()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'section_key' => 'hero',
            'render_variant' => 'rich_text',
        ]);
        $variantSeo = PersonalityProfileVariantSeoMeta::query()->withoutGlobalScopes()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'seo_title' => 'INTJ-A SEO',
        ]);

        $this->assertSame(23, (int) $section->refresh()->org_id);
        $this->assertSame(23, (int) $seo->refresh()->org_id);
        $this->assertSame(23, (int) $variant->refresh()->org_id);
        $this->assertSame(23, (int) $variantSection->refresh()->org_id);
        $this->assertSame(23, (int) $variantSeo->refresh()->org_id);
    }

    public function test_personality_child_rows_are_tenant_scoped_in_http_context(): void
    {
        $ownProfile = $this->profile(31, 'ENFP');
        $otherProfile = $this->profile(32, 'ENTJ');

        PersonalityProfileSection::query()->withoutGlobalScopes()->create([
            'profile_id' => (int) $ownProfile->id,
            'section_key' => 'hero',
            'render_variant' => 'rich_text',
            'body_md' => 'Own tenant section',
        ]);
        PersonalityProfileSection::query()->withoutGlobalScopes()->create([
            'profile_id' => (int) $otherProfile->id,
            'section_key' => 'hero',
            'render_variant' => 'rich_text',
            'body_md' => 'Other tenant section',
        ]);

        app(OrgContext::class)->set(31, 2001, 'admin', null, OrgContext::KIND_TENANT);
        request()->server->set('REQUEST_URI', '/api/v0.4/personality/test');

        $sections = PersonalityProfileSection::query()
            ->pluck('body_md')
            ->all();

        $this->assertSame(['Own tenant section'], $sections);
    }

    private function profile(int $orgId, string $typeCode): PersonalityProfile
    {
        return PersonalityProfile::query()->withoutGlobalScopes()->create([
            'org_id' => $orgId,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => $typeCode,
            'canonical_type_code' => $typeCode,
            'slug' => strtolower($typeCode).'-profile',
            'locale' => 'en',
            'title' => $typeCode.' Profile',
            'status' => 'published',
            'is_public' => true,
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
    }
}
