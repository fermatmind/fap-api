<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\TopicProfile;
use App\Models\TopicProfileRevision;
use App\Models\TopicProfileSection;
use App\Services\ContentPromotion\IqEqTopicPackage;
use App\Services\ContentPromotion\PromotionContextFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class IqEqTopicFaqProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_actual_bilingual_http_answer_surface_keeps_all_seven_reviewed_faqs(): void
    {
        foreach (app(IqEqTopicPackage::class)->read(base_path(), IqEqTopicPackage::SHA256) as $row) {
            $profile = $this->profile($row);
            $response = $this->getJson('/api/v0.5/topics/iq-eq?org_id=0&locale='.$row['identity']['locale']);
            $response->assertOk()->assertJsonCount(7, 'answer_surface_v1.faq_blocks');
            self::assertSame($row['snapshot']['section_candidates'][1]['payload_json']['items'], array_map(
                static fn (array $faq): array => ['question' => $faq['question'], 'answer' => $faq['answer']],
                $response->json('answer_surface_v1.faq_blocks')
            ));
            // A drifted published pointer cannot grant the enlarged projection.
            $profile->forceFill(['published_revision_id' => null])->saveQuietly();
            $this->getJson('/api/v0.5/topics/iq-eq?org_id=0&locale='.$row['identity']['locale'])
                ->assertOk()->assertJsonCount(4, 'answer_surface_v1.faq_blocks');
        }
    }

    public function test_wrong_package_or_faq_answer_keeps_default_limit(): void
    {
        $row = app(IqEqTopicPackage::class)->read(base_path(), IqEqTopicPackage::SHA256)[0];
        $profile = $this->profile($row);
        TopicProfileRevision::query()->whereKey($profile->published_revision_id)->update(['authority_package_sha256' => str_repeat('a', 64)]);
        $this->getJson('/api/v0.5/topics/iq-eq?org_id=0&locale=zh-CN')->assertOk()->assertJsonCount(4, 'answer_surface_v1.faq_blocks');
        TopicProfileRevision::query()->whereKey($profile->published_revision_id)->update(['authority_package_sha256' => IqEqTopicPackage::SHA256]);
        $section = $profile->sections()->where('section_key', 'faq')->firstOrFail();
        $payload = $section->payload_json;
        $payload['items'][6]['answer'] = 'Altered answer';
        $section->forceFill(['payload_json' => $payload])->saveQuietly();
        $this->getJson('/api/v0.5/topics/iq-eq?org_id=0&locale=zh-CN')->assertOk()->assertJsonCount(4, 'answer_surface_v1.faq_blocks');
    }

    public function test_other_retained_faq_variants_cannot_displace_the_fixed_bilingual_answers(): void
    {
        foreach (app(IqEqTopicPackage::class)->read(base_path(), IqEqTopicPackage::SHA256) as $row) {
            $profile = $this->profile($row);
            $other = TopicProfileSection::query()->create([
                'profile_id' => $profile->id, 'section_key' => 'why_it_matters', 'render_variant' => 'faq', 'sort_order' => 0,
                'is_enabled' => true, 'payload_json' => ['items' => [['question' => 'Retained operator FAQ', 'answer' => 'Retained operator answer']]],
            ]);
            foreach ([0, 100] as $order) {
                $other->forceFill(['sort_order' => $order])->saveQuietly();
                $response = $this->getJson('/api/v0.5/topics/iq-eq?org_id=0&locale='.$row['identity']['locale']);
                $response->assertOk()->assertJsonCount(7, 'answer_surface_v1.faq_blocks');
                self::assertSame($row['snapshot']['section_candidates'][1]['payload_json']['items'], array_map(
                    static fn (array $faq): array => ['question' => $faq['question'], 'answer' => $faq['answer']], $response->json('answer_surface_v1.faq_blocks')));
                self::assertSame('Retained operator FAQ', $other->fresh()->payload_json['items'][0]['question']);
            }
        }
    }

    private function profile(array $row): TopicProfile
    {
        $profile = TopicProfile::query()->withoutGlobalScopes()->create([
            ...$row['identity'], ...$row['snapshot']['profile_patch'], 'topic_code' => 'iq-eq',
            'status' => 'published', 'is_public' => true, 'is_indexable' => false, 'published_at' => now()->subMinute(),
        ]);
        foreach ($row['snapshot']['section_candidates'] as $section) {
            TopicProfileSection::query()->create(['profile_id' => $profile->id, ...$section, 'is_enabled' => true, 'sort_order' => 10]);
        }
        $revision = TopicProfileRevision::query()->create([
            'profile_id' => $profile->id, 'revision_no' => 1, 'authority_asset_key' => 'SH-01:'.$row['identity']['locale'],
            'source_package' => IqEqTopicPackage::PACKAGE, 'authority_package_sha256' => IqEqTopicPackage::SHA256,
            'workflow_state' => 'published', 'snapshot_json' => $row['snapshot'], 'created_at' => now(),
            'source_hash' => hash('sha256', PromotionContextFactory::canonicalJson($row['snapshot'])),
        ]);
        $profile->forceFill(['published_revision_id' => $revision->id])->saveQuietly();

        return $profile;
    }
}
