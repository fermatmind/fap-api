<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\PublicTopicEdge;
use App\Models\TopicProfile;
use App\Models\TopicProfileSeoMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class PublicTopicEdgeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_contract_rejects_unknown_sources_and_locales(): void
    {
        $this->getJson('/api/v0.5/public-topic-edges?source_type=unknown&source_id=1&source_locale=en')
            ->assertUnprocessable();

        $this->getJson('/api/v0.5/public-topic-edges?source_type=topic&source_id=1&source_locale=fr')
            ->assertUnprocessable();
    }

    public function test_empty_projection_keeps_explicit_authority_metadata_and_no_fallback(): void
    {
        $response = $this->getJson('/api/v0.5/public-topic-edges?source_type=topic&source_id=999&source_locale=en');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('schema_version', 'public-topic-edges.v1')
            ->assertJsonPath('authority.owner', 'fap-api/cms')
            ->assertJsonPath('authority.source_publication_eligible', false)
            ->assertJsonPath('authority.frontend_fallback_allowed', false)
            ->assertJsonPath('authority.target_truth_readback', 'live')
            ->assertJsonPath('authority.career_link_publication_gate', 'CLOSED')
            ->assertJsonPath('authority.reason', 'SOURCE_NOT_PUBLICLY_ELIGIBLE')
            ->assertJsonCount(0, 'items');
    }

    public function test_career_source_is_always_closed_before_c06_pass(): void
    {
        $this->getJson('/api/v0.5/public-topic-edges?source_type=career_job&source_id=1&source_locale=en')
            ->assertOk()
            ->assertJsonPath('authority.career_link_publication_gate', 'CLOSED')
            ->assertJsonPath('authority.reason', 'CAREER_LINK_PUBLICATION_GATE_CLOSED')
            ->assertJsonCount(0, 'items');
    }

    public function test_career_edge_write_is_forced_to_waiting_on_c06(): void
    {
        $source = $this->createTopic('source', 'en', 'https://fermatmind.com/en/topics/source');

        $edge = PublicTopicEdge::query()->create([
            'org_id' => 0,
            'source_type' => 'topic',
            'source_id' => (int) $source->id,
            'source_locale' => 'en',
            'relation_type' => 'related',
            'target_type' => 'career_job',
            'target_id' => 99,
            'target_locale' => 'en',
            'visible_label' => 'Career target',
            'position' => 10,
            'active' => true,
            'proposed_active_state' => true,
            'publication_allowed' => true,
            'review_state' => PublicTopicEdge::REVIEW_APPROVED,
            'version' => 'window6-v1',
            'created_by_admin_user_id' => 1,
            'updated_by_admin_user_id' => 1,
            'target_publication_eligible' => true,
            'target_canonical' => 'https://fermatmind.com/en/careers/example',
        ]);

        self::assertFalse((bool) $edge->active);
        self::assertFalse((bool) $edge->proposed_active_state);
        self::assertFalse((bool) $edge->publication_allowed);
        self::assertFalse((bool) $edge->target_publication_eligible);
        self::assertSame('WAITING_ON_C06', $edge->blocker);
        $this->getJson($this->endpoint($source))->assertJsonCount(0, 'items');
    }

    public function test_projection_is_stable_complete_and_deduplicated(): void
    {
        $source = $this->createTopic('source', 'en', 'https://fermatmind.com/en/topics/source');
        $firstTarget = $this->createTopic('first', 'en', 'https://fermatmind.com/en/topics/first');
        $secondTarget = $this->createTopic('second', 'en', 'https://fermatmind.com/en/topics/second');

        $second = $this->createEdge($source, $secondTarget, [
            'position' => 20,
            'visible_label' => 'Second target',
            'created_by_admin_user_id' => 41,
            'updated_by_admin_user_id' => 42,
        ]);
        $first = $this->createEdge($source, $firstTarget, [
            'position' => 10,
            'visible_label' => 'First target',
            'context' => 'A governed next step.',
            'evidence_refs' => ['window6:G03', 'cms-review:17'],
        ]);

        $cacheRows = [$second->toArray(), $first->toArray(), $first->toArray()];
        Cache::put(PublicTopicEdge::candidateCacheKey(0, 'topic', (int) $source->id, 'en'), $cacheRows, 300);

        $response = $this->getJson($this->endpoint($source));

        $response->assertOk()
            ->assertJsonPath('authority.source_publication_eligible', true)
            ->assertJsonPath('authority.source_canonical', 'https://fermatmind.com/en/topics/source')
            ->assertJsonPath('authority.eligible_item_count', 2)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.visible_label', 'First target')
            ->assertJsonPath('items.0.context', 'A governed next step.')
            ->assertJsonPath('items.0.position', 10)
            ->assertJsonPath('items.0.evidence_refs.0', 'window6:G03')
            ->assertJsonPath('items.0.target_publication_eligible', true)
            ->assertJsonPath('items.0.target_canonical', 'https://fermatmind.com/en/topics/first')
            ->assertJsonPath('items.1.visible_label', 'Second target')
            ->assertJsonMissingPath('items.0.created_by')
            ->assertJsonMissingPath('items.0.updated_by');
    }

    public function test_inactive_unreviewed_expired_invalid_relation_and_locale_mismatch_fail_closed(): void
    {
        $source = $this->createTopic('source', 'en', 'https://fermatmind.com/en/topics/source');
        $target = $this->createTopic('target', 'en', 'https://fermatmind.com/en/topics/target');
        $zhTarget = $this->createTopic('target-zh', 'zh-CN', 'https://fermatmind.com/zh/topics/target-zh');

        $this->createEdge($source, $target, ['active' => false, 'relation_type' => 'parent']);
        $this->createEdge($source, $target, ['review_state' => 'draft', 'relation_type' => 'next_step']);
        $this->createEdge($source, $target, ['valid_until' => now()->subMinute(), 'relation_type' => 'supporting_evidence']);
        $this->createEdge($source, $target, ['relation_type' => 'not_allowed']);
        $this->createEdge($source, $zhTarget, ['relation_type' => 'related']);

        $this->getJson($this->endpoint($source))
            ->assertOk()
            ->assertJsonPath('authority.eligible_item_count', 0)
            ->assertJsonCount(0, 'items');
    }

    public function test_private_stale_unqualified_and_canonical_mismatch_targets_fail_closed_on_live_readback(): void
    {
        $source = $this->createTopic('source', 'en', 'https://fermatmind.com/en/topics/source');
        $private = $this->createTopic('private', 'en', 'https://fermatmind.com/en/results/private');
        $stale = $this->createTopic('stale', 'en', 'https://fermatmind.com/en/topics/stale');
        $unqualified = $this->createTopic('unqualified', 'en', 'https://fermatmind.com/en/topics/unqualified');
        $mismatch = $this->createTopic('mismatch', 'en', 'https://fermatmind.com/en/topics/mismatch');
        $noindex = $this->createTopic('noindex', 'en', 'https://fermatmind.com/en/topics/noindex');
        $noindex->seoMeta?->forceFill(['robots' => 'noindex,follow'])->save();

        $this->createEdge($source, $private, ['relation_type' => 'parent']);
        $this->createEdge($source, $stale, ['relation_type' => 'next_step']);
        $this->createEdge($source, $unqualified, [
            'relation_type' => 'supporting_evidence',
            'target_publication_eligible' => false,
        ]);
        $this->createEdge($source, $mismatch, [
            'relation_type' => 'take_assessment',
            'target_canonical' => 'https://fermatmind.com/en/topics/not-the-target',
        ]);
        $this->createEdge($source, $noindex, ['relation_type' => 'related']);

        $this->getJson($this->endpoint($source))->assertJsonCount(1, 'items');

        $stale->forceFill(['is_indexable' => false])->save();

        $this->getJson($this->endpoint($source))
            ->assertOk()
            ->assertJsonPath('authority.eligible_item_count', 0)
            ->assertJsonCount(0, 'items');
    }

    public function test_edge_write_invalidates_candidate_cache(): void
    {
        $source = $this->createTopic('source', 'en', 'https://fermatmind.com/en/topics/source');
        $target = $this->createTopic('target', 'en', 'https://fermatmind.com/en/topics/target');

        $this->getJson($this->endpoint($source))->assertJsonCount(0, 'items');
        $this->createEdge($source, $target);

        $this->getJson($this->endpoint($source))
            ->assertOk()
            ->assertJsonCount(1, 'items');
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createTopic(string $code, string $locale, string $canonical, array $overrides = []): TopicProfile
    {
        $profile = TopicProfile::query()->create(array_merge([
            'org_id' => 0,
            'topic_code' => $code,
            'slug' => $code,
            'locale' => $locale,
            'title' => ucfirst($code),
            'status' => TopicProfile::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => 'v1',
        ], $overrides));

        TopicProfileSeoMeta::query()->create([
            'profile_id' => (int) $profile->id,
            'canonical_url' => $canonical,
            'robots' => 'index,follow',
        ]);

        return $profile->fresh('seoMeta') ?? $profile;
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createEdge(TopicProfile $source, TopicProfile $target, array $overrides = []): PublicTopicEdge
    {
        return PublicTopicEdge::query()->create(array_merge([
            'org_id' => 0,
            'source_type' => 'topic',
            'source_id' => (int) $source->id,
            'source_locale' => (string) $source->locale,
            'relation_type' => 'related',
            'target_type' => 'topic',
            'target_id' => (int) $target->id,
            'target_locale' => (string) $target->locale,
            'visible_label' => 'Related topic',
            'position' => 100,
            'active' => true,
            'proposed_active_state' => true,
            'publication_allowed' => true,
            'review_state' => PublicTopicEdge::REVIEW_APPROVED,
            'evidence_refs' => ['window6:G03'],
            'version' => 'window6-v1',
            'created_by_admin_user_id' => 1,
            'updated_by_admin_user_id' => 1,
            'target_publication_eligible' => true,
            'target_canonical' => (string) $target->seoMeta?->canonical_url,
        ], $overrides));
    }

    private function endpoint(TopicProfile $source): string
    {
        return sprintf(
            '/api/v0.5/public-topic-edges?source_type=topic&source_id=%d&source_locale=%s',
            (int) $source->id,
            (string) $source->locale,
        );
    }
}
