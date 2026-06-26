<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileSeoMeta;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantSection;
use App\Models\PersonalityProfileVariantSeoMeta;
use App\Services\SeoIntel\Sources\BackendAuthorityUrlTruthSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelMbti64PersonalityUrlTruthInventoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function backend_authority_source_emits_mbti64_variant_and_comparison_url_truth_candidates(): void
    {
        config(['seo_intel.public_canonical_host' => 'https://fermatmind.com']);

        $this->seedPublishedMbti64Profiles();

        $source = new BackendAuthorityUrlTruthSource;
        $records = collect($source->candidates());
        $personalityRecords = $records
            ->filter(static fn ($record): bool => in_array($record->pageEntityType, [
                'personality_profile_variant',
                'personality_profile_comparison',
            ], true))
            ->values();

        $this->assertCount(96, $personalityRecords);
        $this->assertCount(64, $personalityRecords->where('pageEntityType', 'personality_profile_variant'));
        $this->assertCount(32, $personalityRecords->where('pageEntityType', 'personality_profile_comparison'));

        $canonicalUrls = $personalityRecords->pluck('canonicalUrl')->all();
        foreach ($this->pilotCanonicalUrls() as $url) {
            $this->assertContains($url, $canonicalUrls);
        }

        $this->assertFalse($personalityRecords->contains(
            static fn ($record): bool => str_contains($record->canonicalUrl, 'https://www.fermatmind.com')
                || str_contains($record->canonicalUrl, '/zh-CN/')
                || str_contains($record->canonicalUrl, '/results')
                || str_contains($record->canonicalUrl, '/orders')
                || str_contains($record->canonicalUrl, '/pay')
                || str_contains($record->canonicalUrl, '/account')
        ));

        $sampleVariant = $personalityRecords->firstWhere(
            'canonicalUrl',
            'https://fermatmind.com/en/personality/intj-a'
        );
        $sampleComparison = $personalityRecords->firstWhere(
            'canonicalUrl',
            'https://fermatmind.com/en/personality/intj-a-vs-intj-t'
        );

        $this->assertNotNull($sampleVariant);
        $this->assertSame('personality_profile_variant', $sampleVariant->pageEntityType);
        $this->assertSame('backend_cms', $sampleVariant->sourceAuthority);
        $this->assertSame('indexable', $sampleVariant->indexabilityState);
        $this->assertFalse($sampleVariant->isPrivateFlow);
        $this->assertSame('published', $sampleVariant->metadata['publication_state'] ?? null);
        $this->assertTrue((bool) ($sampleVariant->metadata['claim_safe'] ?? false));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) ($sampleVariant->metadata['content_hash'] ?? ''));
        $this->assertSame('personality_profile_variant_live_content_v1', $sampleVariant->metadata['content_hash_source'] ?? null);
        $this->assertFalse((bool) ($sampleVariant->metadata['frontend_fallback'] ?? true));
        $this->assertFalse((bool) ($sampleVariant->metadata['static_sitemap_fallback'] ?? true));
        $this->assertFalse((bool) ($sampleVariant->metadata['static_llms_fallback'] ?? true));

        $this->assertNotNull($sampleComparison);
        $this->assertSame('personality_profile_comparison', $sampleComparison->pageEntityType);
        $this->assertSame('backend_cms', $sampleComparison->sourceAuthority);
        $this->assertSame('indexable', $sampleComparison->indexabilityState);
        $this->assertSame('a_vs_t', $sampleComparison->metadata['comparison_kind'] ?? null);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) ($sampleComparison->metadata['content_hash'] ?? ''));
        $this->assertSame('personality_profile_comparison_live_content_v1', $sampleComparison->metadata['content_hash_source'] ?? null);

        $metadata = $source->metadata();
        $this->assertTrue((bool) ($metadata['personality_profiles_attempted'] ?? false));
        $this->assertTrue((bool) ($metadata['personality_profiles_available'] ?? false));
        $this->assertContains('personality_profile_variant', config('seo_intel.url_truth_inventory.allowed_page_entity_types', []));
        $this->assertContains('personality_profile_comparison', config('seo_intel.url_truth_inventory.allowed_page_entity_types', []));
        $this->assertContains('personality_profile_variant', config('seo_intel.search_channel_queue.allowed_page_entity_types', []));
        $this->assertContains('personality_profile_comparison', config('seo_intel.search_channel_queue.allowed_page_entity_types', []));
    }

    #[Test]
    public function backend_authority_source_excludes_unpublished_private_or_incomplete_personality_pages(): void
    {
        config(['seo_intel.public_canonical_host' => 'https://fermatmind.com']);

        $draft = $this->createProfile('INTJ', 'en', [
            'status' => 'draft',
            'is_public' => true,
            'is_indexable' => true,
        ]);
        $this->createVariant($draft, 'A', ['is_published' => true]);
        $this->createVariant($draft, 'T', ['is_published' => true]);

        $noindex = $this->createProfile('INTP', 'en', [
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => false,
        ]);
        $this->createVariant($noindex, 'A', ['is_published' => true]);
        $this->createVariant($noindex, 'T', ['is_published' => true]);

        $missingT = $this->createProfile('ENTJ', 'en', [
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
        ]);
        $this->createVariant($missingT, 'A', ['is_published' => true]);
        $this->createVariant($missingT, 'T', ['is_published' => false]);

        $records = collect((new BackendAuthorityUrlTruthSource)->candidates());
        $canonicalUrls = $records->pluck('canonicalUrl')->all();

        $this->assertNotContains('https://fermatmind.com/en/personality/intj-a', $canonicalUrls);
        $this->assertNotContains('https://fermatmind.com/en/personality/intp-a', $canonicalUrls);
        $this->assertContains('https://fermatmind.com/en/personality/entj-a', $canonicalUrls);
        $this->assertNotContains('https://fermatmind.com/en/personality/entj-t', $canonicalUrls);
        $this->assertNotContains('https://fermatmind.com/en/personality/entj-a-vs-entj-t', $canonicalUrls);
    }

    #[Test]
    public function backend_authority_source_uses_variant_live_content_for_url_truth_freshness(): void
    {
        config(['seo_intel.public_canonical_host' => 'https://fermatmind.com']);

        $now = now()->startOfSecond();
        $profile = $this->createProfile('ENFJ', 'en');
        PersonalityProfile::withoutTimestamps(fn () => $profile->forceFill(['updated_at' => $now->copy()->subDays(12)])->save());
        $variant = $this->createVariant($profile, 'A');
        PersonalityProfileVariant::withoutTimestamps(fn () => $variant->forceFill(['updated_at' => $now->copy()->subDays(11)])->save());
        $this->createVariant($profile, 'T', ['is_published' => true]);

        $seoMeta = PersonalityProfileVariantSeoMeta::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'seo_title' => 'ENFJ-A old title',
            'seo_description' => 'Old description',
            'canonical_url' => 'https://fermatmind.com/en/personality/enfj-a',
            'robots' => 'index,follow',
        ]);
        PersonalityProfileVariantSeoMeta::withoutTimestamps(fn () => $seoMeta->forceFill(['updated_at' => $now->copy()->subDays(3)])->save());
        $section = PersonalityProfileVariantSection::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'section_key' => 'quick_answer',
            'render_variant' => 'callout',
            'body_md' => 'Old quick answer.',
            'payload_json' => ['summary' => 'old'],
            'sort_order' => 10,
            'is_enabled' => true,
        ]);
        PersonalityProfileVariantSection::withoutTimestamps(fn () => $section->forceFill(['updated_at' => $now->copy()->subDays(2)])->save());

        $before = $this->recordFor('https://fermatmind.com/en/personality/enfj-a');
        $this->assertSame('personality_profile_variant_live_content.updated_at', $before->lastmodSource);
        $this->assertSame($now->copy()->subDays(2)->toDateTimeString(), $before->lastmodAt?->toDateTimeString());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) ($before->metadata['content_hash'] ?? ''));

        PersonalityProfileVariantSection::withoutTimestamps(fn () => $section->forceFill([
            'body_md' => 'Promoted quick answer.',
            'payload_json' => ['summary' => 'promoted'],
            'updated_at' => $now,
        ])->save());

        $after = $this->recordFor('https://fermatmind.com/en/personality/enfj-a');
        $this->assertNotSame($before->metadata['content_hash'], $after->metadata['content_hash']);
        $this->assertSame($now->toDateTimeString(), $after->lastmodAt?->toDateTimeString());
    }

    #[Test]
    public function backend_authority_source_uses_comparison_live_overlay_for_url_truth_freshness(): void
    {
        config(['seo_intel.public_canonical_host' => 'https://fermatmind.com']);

        $now = now()->startOfSecond();
        $profile = $this->createProfile('INTP', 'zh-CN');
        PersonalityProfile::withoutTimestamps(fn () => $profile->forceFill(['updated_at' => $now->copy()->subDays(8)])->save());
        $this->createVariant($profile, 'A', ['is_published' => true]);
        $this->createVariant($profile, 'T', ['is_published' => true]);
        $seoMeta = PersonalityProfileSeoMeta::query()->create([
            'profile_id' => (int) $profile->id,
            'seo_title' => 'INTP A/T old comparison',
            'seo_description' => 'Old comparison description',
            'canonical_url' => 'https://fermatmind.com/zh/personality/intp-a-vs-intp-t',
            'robots' => 'index,follow',
        ]);
        PersonalityProfileSeoMeta::withoutTimestamps(fn () => $seoMeta->forceFill(['updated_at' => $now->copy()->subDays(4)])->save());
        $section = PersonalityProfileSection::query()->create([
            'profile_id' => (int) $profile->id,
            'section_key' => 'mbti64_comparison_a_vs_t',
            'title' => 'Old comparison',
            'render_variant' => 'rich_text',
            'body_md' => 'Old comparison quick answer.',
            'payload_json' => ['content' => ['quick_answer' => 'old']],
            'sort_order' => 920,
            'is_enabled' => true,
        ]);
        PersonalityProfileSection::withoutTimestamps(fn () => $section->forceFill(['updated_at' => $now->copy()->subDay()])->save());

        $before = $this->recordFor('https://fermatmind.com/zh/personality/intp-a-vs-intp-t');
        $this->assertSame('personality_profile_sections.mbti64_comparison_a_vs_t.updated_at', $before->lastmodSource);
        $this->assertSame($now->copy()->subDay()->toDateTimeString(), $before->lastmodAt?->toDateTimeString());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) ($before->metadata['content_hash'] ?? ''));

        PersonalityProfileSection::withoutTimestamps(fn () => $section->forceFill([
            'body_md' => 'Promoted comparison quick answer.',
            'payload_json' => ['content' => ['quick_answer' => 'promoted']],
            'updated_at' => $now,
        ])->save());

        $after = $this->recordFor('https://fermatmind.com/zh/personality/intp-a-vs-intp-t');
        $this->assertNotSame($before->metadata['content_hash'], $after->metadata['content_hash']);
        $this->assertSame($now->toDateTimeString(), $after->lastmodAt?->toDateTimeString());
    }

    private function seedPublishedMbti64Profiles(): void
    {
        foreach (PersonalityProfile::SUPPORTED_LOCALES as $locale) {
            foreach (PersonalityProfile::BASE_TYPE_CODES as $typeCode) {
                $profile = $this->createProfile($typeCode, $locale, [
                    'status' => 'published',
                    'is_public' => true,
                    'is_indexable' => true,
                ]);
                $this->createVariant($profile, 'A', ['is_published' => true]);
                $this->createVariant($profile, 'T', ['is_published' => true]);
            }
        }
    }

    private function recordFor(string $canonicalUrl): object
    {
        $record = collect((new BackendAuthorityUrlTruthSource)->candidates())
            ->firstWhere('canonicalUrl', $canonicalUrl);

        $this->assertNotNull($record);

        return $record;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createProfile(string $typeCode, string $locale, array $overrides = []): PersonalityProfile
    {
        return PersonalityProfile::query()->create($overrides + [
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => $typeCode,
            'canonical_type_code' => $typeCode,
            'slug' => strtolower($typeCode),
            'locale' => $locale,
            'title' => $typeCode.' profile',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subDay(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createVariant(PersonalityProfile $profile, string $variantCode, array $overrides = []): PersonalityProfileVariant
    {
        return PersonalityProfileVariant::query()->create($overrides + [
            'org_id' => 0,
            'personality_profile_id' => (int) $profile->id,
            'canonical_type_code' => (string) $profile->canonical_type_code,
            'variant_code' => $variantCode,
            'runtime_type_code' => ((string) $profile->canonical_type_code).'-'.$variantCode,
            'type_name' => ((string) $profile->canonical_type_code).'-'.$variantCode,
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            'is_published' => true,
            'published_at' => now()->subDay(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function pilotCanonicalUrls(): array
    {
        return [
            'https://fermatmind.com/en/personality/intj-a-vs-intj-t',
            'https://fermatmind.com/zh/personality/istj-a',
            'https://fermatmind.com/en/personality/intp-a-vs-intp-t',
            'https://fermatmind.com/zh/personality/infp-t',
            'https://fermatmind.com/en/personality/intj-a',
            'https://fermatmind.com/en/personality/intj-t',
            'https://fermatmind.com/zh/personality/intj-a',
            'https://fermatmind.com/zh/personality/intj-t',
        ];
    }
}
