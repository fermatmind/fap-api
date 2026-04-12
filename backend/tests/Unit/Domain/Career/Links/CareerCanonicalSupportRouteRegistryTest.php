<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Links;

use App\Domain\Career\Links\CareerCanonicalSupportRouteRegistry;
use App\Models\TopicProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerCanonicalSupportRouteRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_emits_only_canonical_test_landing_and_topic_detail_routes(): void
    {
        $this->seedCanonicalMbtiScale();
        $this->createTopicProfile(topicCode: 'mbti', slug: 'mbti', status: TopicProfile::STATUS_PUBLISHED, isPublic: true);
        $this->createTopicProfile(topicCode: 'draft-topic', slug: 'draft-topic', status: TopicProfile::STATUS_DRAFT, isPublic: true);

        $routes = app(CareerCanonicalSupportRouteRegistry::class)->list('en');

        $this->assertNotEmpty($routes);
        $this->assertSame(
            ['test_landing', 'topic_detail'],
            collect($routes)->pluck('route_kind')->unique()->sort()->values()->all()
        );

        $testLanding = collect($routes)->first(static fn (array $row): bool => ($row['route_kind'] ?? null) === 'test_landing'
            && data_get($row, 'metadata.scale_code') === 'MBTI');
        $topicDetail = collect($routes)->first(static fn (array $row): bool => ($row['route_kind'] ?? null) === 'topic_detail'
            && data_get($row, 'metadata.topic_code') === 'mbti');

        $this->assertSame('/en/tests/mbti-personality-test-16-personality-types', $testLanding['canonical_path']);
        $this->assertSame('scales_registry.primary_slug', $testLanding['source_of_truth']);
        $this->assertSame('/en/topics/mbti', $topicDetail['canonical_path']);
        $this->assertSame('topic_profiles.slug', $topicDetail['source_of_truth']);

        $this->assertFalse(collect($routes)->contains(static fn (array $row): bool => str_ends_with((string) ($row['canonical_path'] ?? ''), '/take')));
        $this->assertFalse(collect($routes)->contains(static fn (array $row): bool => ($row['canonical_path'] ?? null) === '/en/tests'));
        $this->assertFalse(collect($routes)->contains(static fn (array $row): bool => data_get($row, 'metadata.topic_code') === 'draft-topic'));
    }

    public function test_it_excludes_invalid_or_unpublished_topic_identity_rows(): void
    {
        $this->seedCanonicalMbtiScale();
        $this->createTopicProfile(topicCode: 'mbti', slug: '', status: TopicProfile::STATUS_PUBLISHED, isPublic: true);
        $this->createTopicProfile(topicCode: 'mbti-private', slug: 'mbti-private', status: TopicProfile::STATUS_PUBLISHED, isPublic: false);

        $routes = app(CareerCanonicalSupportRouteRegistry::class)->list('en');

        $this->assertFalse(collect($routes)->contains(static fn (array $row): bool => ($row['route_kind'] ?? null) === 'topic_detail'));
        $this->assertTrue(collect($routes)->contains(static fn (array $row): bool => ($row['route_kind'] ?? null) === 'test_landing'));
    }

    private function seedCanonicalMbtiScale(): void
    {
        Artisan::call('fap:scales:seed-default');
        Artisan::call('fap:scales:sync-slugs');
    }

    private function createTopicProfile(string $topicCode, string $slug, string $status, bool $isPublic): void
    {
        TopicProfile::query()->create([
            'org_id' => 0,
            'topic_code' => $topicCode,
            'slug' => $slug,
            'locale' => 'en',
            'title' => strtoupper($topicCode).' Topic',
            'status' => $status,
            'is_public' => $isPublic,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => 'v1',
        ]);
    }
}
