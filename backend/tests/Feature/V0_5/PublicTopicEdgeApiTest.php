<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\PublicTopicEdge;
use App\Models\TopicProfile;
use App\Models\TopicProfileSeoMeta;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PublicTopicEdgeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_contract_rejects_unknown_sources_and_locales(): void
    {
        $this->getJson('/api/v0.5/public-topic-edges?source_type=unknown&source_id=1&locale=en')
            ->assertUnprocessable();

        $this->getJson('/api/v0.5/public-topic-edges?source_type=topic&source_id=1&locale=fr')
            ->assertUnprocessable();

        $this->getJson('/api/v0.5/public-topic-edges?source_type=topic&source_id=1&source_locale=en')
            ->assertUnprocessable();
    }

    public function test_empty_projection_keeps_explicit_authority_metadata_and_no_fallback(): void
    {
        $response = $this->getJson('/api/v0.5/public-topic-edges?source_type=topic&source_id=999&locale=en');

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
        $this->getJson('/api/v0.5/public-topic-edges?source_type=career_job&source_id=1&locale=en')
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
            'relation_type' => 'learn_more',
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
            'source_canonical' => 'https://fermatmind.com/en/topics/source',
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
            ->assertJsonPath('items.0.source_canonical', 'https://fermatmind.com/en/topics/source')
            ->assertJsonPath('items.0.evidence_refs.0', 'window6:G03')
            ->assertJsonPath('items.0.target_publication_eligible', true)
            ->assertJsonPath('items.0.target_canonical', 'https://fermatmind.com/en/topics/first')
            ->assertJsonPath('items.1.visible_label', 'Second target')
            ->assertJsonMissingPath('items.0.created_by')
            ->assertJsonMissingPath('items.0.updated_by');
    }

    public function test_inactive_unreviewed_expired_and_unapproved_locale_mismatch_fail_closed(): void
    {
        $source = $this->createTopic('source', 'en', 'https://fermatmind.com/en/topics/source');
        $inactive = $this->createTopic('inactive', 'en', 'https://fermatmind.com/en/topics/inactive');
        $draft = $this->createTopic('draft', 'en', 'https://fermatmind.com/en/topics/draft');
        $expired = $this->createTopic('expired', 'en', 'https://fermatmind.com/en/topics/expired');
        $zhTarget = $this->createTopic('target-zh', 'zh-CN', 'https://fermatmind.com/zh/topics/target-zh');

        $this->createEdge($source, $inactive, ['active' => false]);
        $this->createEdge($source, $draft, ['review_state' => 'draft']);
        $this->createEdge($source, $expired, ['valid_until' => now()->subMinute()]);
        $this->createEdge($source, $zhTarget);

        $this->getJson($this->endpoint($source))
            ->assertOk()
            ->assertJsonPath('authority.eligible_item_count', 0)
            ->assertJsonCount(0, 'items');
    }

    public function test_storage_rejects_a_relation_outside_the_governed_g03_allowlist(): void
    {
        $source = $this->createTopic('source', 'en', 'https://fermatmind.com/en/topics/source');
        $target = $this->createTopic('target', 'en', 'https://fermatmind.com/en/topics/target');

        $this->expectException(DomainException::class);
        $this->createEdge($source, $target, ['relation_type' => 'related']);
    }

    public function test_explicit_cross_locale_approval_is_required_for_projection(): void
    {
        $source = $this->createTopic('source', 'en', 'https://fermatmind.com/en/topics/source');
        $held = $this->createTopic('held-zh', 'zh-CN', 'https://fermatmind.com/zh/topics/held');
        $approved = $this->createTopic('approved-zh', 'zh-CN', 'https://fermatmind.com/zh/topics/approved');

        $this->createEdge($source, $held);
        $this->createEdge($source, $approved, ['cross_locale_approved' => true]);

        $this->getJson($this->endpoint($source))
            ->assertOk()
            ->assertJsonPath('authority.eligible_item_count', 1)
            ->assertJsonPath('items.0.cross_locale_approved', true)
            ->assertJsonPath('items.0.target_canonical', 'https://fermatmind.com/zh/topics/approved');
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

        $sourceMismatch = $this->createTopic('source-mismatch', 'en', 'https://fermatmind.com/en/topics/source-mismatch');

        $this->createEdge($source, $private, ['relation_type' => 'breadcrumb']);
        $this->createEdge($source, $stale, ['relation_type' => 'learn_more']);
        $this->createEdge($source, $unqualified, [
            'relation_type' => 'breadcrumb',
            'target_publication_eligible' => false,
        ]);
        $this->createEdge($source, $mismatch, [
            'relation_type' => 'take_assessment',
            'target_canonical' => 'https://fermatmind.com/en/topics/not-the-target',
        ]);
        $this->createEdge($source, $noindex, ['relation_type' => 'learn_more']);
        $this->createEdge($source, $sourceMismatch, [
            'relation_type' => 'breadcrumb',
            'source_canonical' => 'https://fermatmind.com/en/topics/not-the-source',
        ]);

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

    public function test_g03_fixture_binds_exact_governed_counts_relations_and_career_hold(): void
    {
        $fixture = json_decode((string) file_get_contents(
            base_path('tests/Fixtures/PublicTopicGraph/g03-governed-review.v1.json')
        ), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('5466522801be6f71d01f0af96a495a84bddd059f', $fixture['source_merge_commit']);
        self::assertSame(422, $fixture['owner_rows']);
        self::assertSame(4805, $fixture['edge_rows']);
        self::assertSame(3268, $fixture['approved_non_career_candidates']);
        self::assertSame(282, $fixture['career_blocked_candidates']);
        self::assertSame(102, $fixture['neutral_global_home_cross_locale_approvals']);
        self::assertSame(PublicTopicEdge::RELATION_TYPES, $fixture['approved_relation_allowlist']);
        self::assertSame(3268, array_sum($fixture['approved_relation_counts']));
        self::assertSame('BLOCKED_WAITING_ON_C06', $fixture['career_review_state']);
        self::assertFalse($fixture['career_proposed_active_state']);
        self::assertFalse($fixture['career_publication_allowed']);
        self::assertFalse($fixture['authority_claimed_by_fixture']);
        self::assertFalse($fixture['production_imported']);
    }

    public function test_authority_database_failure_returns_fail_closed_unavailable_envelope(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            self::assertSame(1, DB::transactionLevel());
            DB::commit();
            RefreshDatabaseState::$migrated = false;
        }
        foreach (['topic_profile_revisions', 'topic_profile_entries', 'topic_profile_seo_meta', 'topic_profile_sections', 'topic_profiles'] as $table) {
            Schema::drop($table);
        }

        $this->getJson('/api/v0.5/public-topic-edges?source_type=topic&source_id=1&locale=en')
            ->assertServiceUnavailable()
            ->assertJsonPath('authority.reason', 'AUTHORITY_UNAVAILABLE')
            ->assertJsonPath('authority.frontend_fallback_allowed', false)
            ->assertJsonCount(0, 'items');
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
            'relation_type' => 'learn_more',
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
            'source_canonical' => (string) $source->seoMeta?->canonical_url,
            'target_publication_eligible' => true,
            'target_canonical' => (string) $target->seoMeta?->canonical_url,
        ], $overrides));
    }

    private function endpoint(TopicProfile $source): string
    {
        return sprintf(
            '/api/v0.5/public-topic-edges?source_type=topic&source_id=%d&locale=%s',
            (int) $source->id,
            (string) $source->locale,
        );
    }
}
