<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\MbtiCrossTypeComparisonAuthority;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantSeoMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class PersonalityMbtiContent15IndexabilityPromotionCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $packagePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packagePath = base_path('content_assets/personality_public/mbti-content15-indexability-promotion-v1.json');
        $this->seedHeldBatch();
    }

    public function test_dry_run_returns_exact_hashes_without_mutation(): void
    {
        self::assertSame(0, Artisan::call('personality:mbti-content15-indexability-promote', [
            '--package' => $this->packagePath,
            '--dry-run' => true,
            '--json' => true,
        ]));
        $payload = json_decode(Artisan::output(), true);

        self::assertTrue($payload['ok']);
        self::assertSame(9, $payload['record_count']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['promotion_package_sha256']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['authorization_payload_sha256']);
        self::assertFalse($payload['production_promotion_executed']);
        self::assertSame('noindex,follow', PersonalityProfileVariantSeoMeta::query()->firstOrFail()->robots);
    }

    public function test_write_requires_exact_hashes_and_every_safety_guard(): void
    {
        self::assertSame(1, Artisan::call('personality:mbti-content15-indexability-promote', [
            '--package' => $this->packagePath,
            '--write' => true,
            '--json' => true,
        ]));
        self::assertStringContainsString('--production-promotion-authorized', Artisan::output());
        self::assertSame('noindex,follow', PersonalityProfileVariantSeoMeta::query()->firstOrFail()->robots);
    }

    public function test_dry_run_rejects_a_held_comparison_with_explicit_index_robots(): void
    {
        $section = PersonalityProfileSection::query()->firstOrFail();
        $payload = (array) $section->payload_json;
        data_set($payload, 'content.seo.robots', 'index,follow');
        $section->forceFill(['payload_json' => $payload])->save();

        self::assertSame(1, Artisan::call('personality:mbti-content15-indexability-promote', [
            '--package' => $this->packagePath,
            '--dry-run' => true,
            '--json' => true,
        ]));
        self::assertStringContainsString('held indexability gate', Artisan::output());
    }

    public function test_exact_authorized_write_promotes_all_nine_but_never_search_submission(): void
    {
        Artisan::call('personality:mbti-content15-indexability-promote', ['--package' => $this->packagePath, '--dry-run' => true, '--json' => true]);
        $plan = json_decode(Artisan::output(), true);

        self::assertSame(0, Artisan::call('personality:mbti-content15-indexability-promote', [
            '--package' => $this->packagePath,
            '--package-sha256' => $plan['promotion_package_sha256'],
            '--authorization-payload-sha256' => $plan['authorization_payload_sha256'],
            '--record-count' => 9,
            '--write' => true,
            '--production-promotion-authorized' => true,
            '--release-indexability' => true,
            '--release-sitemap' => true,
            '--release-llms' => true,
            '--no-gsc' => true,
            '--no-url-inspection' => true,
            '--json' => true,
        ]));

        self::assertSame(4, PersonalityProfileVariantSeoMeta::query()->where('robots', 'index,follow')->count());
        self::assertFalse((bool) data_get(PersonalityProfileSection::query()->firstOrFail()->payload_json, 'indexability_held'));
        self::assertSame(4, MbtiCrossTypeComparisonAuthority::query()->where('is_indexable', true)->where('sitemap_eligible', true)->where('llms_eligible', true)->count());
        self::assertSame(0, MbtiCrossTypeComparisonAuthority::query()->where('search_submission_eligible', true)->count());
    }

    private function seedHeldBatch(): void
    {
        foreach (['ISTJ-A', 'ISTP-A', 'ISFP-A', 'ESFJ-A'] as $runtime) {
            $base = substr($runtime, 0, 4);
            $profile = $this->profile($base);
            $variant = PersonalityProfileVariant::query()->create([
                'org_id' => 0, 'personality_profile_id' => $profile->id, 'canonical_type_code' => $base,
                'variant_code' => 'A', 'runtime_type_code' => $runtime, 'type_name' => $runtime,
                'schema_version' => 'v2', 'is_published' => true, 'published_at' => now(),
            ]);
            PersonalityProfileVariantSeoMeta::query()->create([
                'org_id' => 0, 'personality_profile_variant_id' => $variant->id,
                'seo_title' => $runtime, 'seo_description' => $runtime, 'canonical_url' => 'https://fermatmind.com/zh/personality/'.strtolower($runtime),
                'robots' => 'noindex,follow',
            ]);
        }

        $intp = $this->profile('INTP');
        PersonalityProfileSection::query()->create([
            'org_id' => 0, 'profile_id' => $intp->id, 'section_key' => 'mbti64_comparison_a_vs_t',
            'render_variant' => 'comparison', 'payload_json' => ['indexability_held' => true, 'content' => ['seo' => ['title' => 'INTP A/T comparison']]],
            'sort_order' => 1, 'is_enabled' => true,
        ]);

        foreach ([['intj-vs-intp', 'INTJ', 'INTP'], ['entj-vs-intj', 'ENTJ', 'INTJ'], ['infj-vs-infp', 'INFJ', 'INFP'], ['istj-vs-isfj', 'ISTJ', 'ISFJ']] as [$slug, $left, $right]) {
            MbtiCrossTypeComparisonAuthority::query()->create([
                'org_id' => 0, 'locale' => 'zh-CN', 'slug' => $slug, 'left_type_code' => $left, 'right_type_code' => $right,
                'title' => $slug, 'seo_title' => $slug, 'seo_description' => $slug, 'summary' => $slug,
                'content_payload_json' => ['faq' => [['question' => 'Q', 'answer' => 'A']]], 'claim_boundary' => 'non-diagnostic',
                'source_package_id' => 'MBTI-CONTENT-15', 'source_sha256' => str_repeat('a', 64),
                'review_status' => 'approved', 'publish_status' => 'published', 'indexability_status' => 'held_for_mbti_index_24',
                'is_public' => true, 'is_indexable' => false, 'sitemap_eligible' => false, 'llms_eligible' => false,
                'search_submission_eligible' => false, 'published_at' => now(), 'imported_at' => now(),
            ]);
        }
    }

    private function profile(string $base): PersonalityProfile
    {
        return PersonalityProfile::query()->firstOrCreate(
            ['org_id' => 0, 'locale' => 'zh-CN', 'canonical_type_code' => $base],
            ['scale_code' => 'MBTI', 'type_code' => $base, 'slug' => strtolower($base), 'title' => $base, 'status' => 'published', 'is_public' => true, 'is_indexable' => true, 'published_at' => now(), 'schema_version' => 'v2'],
        );
    }
}
