<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\TopicProfile;
use App\Models\TopicProfileRevision;
use App\Services\BigFive\AuthorityV2\TopicAuthority\BigFiveTopicAuthorityDraftPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

final class BigFiveAuthorityV246Test extends TestCase
{
    use RefreshDatabase;

    private const PACKAGE = '../generated/big-five-authority-v2/big5-authority-v2-topic-authority-46/topic-draft-revision-package.json';

    public function test_package_only_preflight_locks_two_fail_closed_topic_revision_candidates(): void
    {
        $result = app(BigFiveTopicAuthorityDraftPreflight::class)->preflight(self::PACKAGE, false);

        $this->assertTrue($result['ok']);
        $this->assertSame('PASS_PACKAGE_ONLY_TOPIC_DRAFT_PREFLIGHT', $result['status']);
        $this->assertSame('working_revision_candidates_zero_write', $result['mode']);
        $this->assertSame([
            'topic_candidates' => 2,
            'working_revision_candidates' => 2,
            'promotion_eligible' => 0,
            'blocked' => 2,
        ], $result['counts']);
        $this->assertSame([
            'en' => '/en/tests/big-five-personality-test-ocean-model',
            'zh-CN' => '/zh/tests/big-five-personality-test-ocean-model',
        ], $result['canonical_test_targets']);
        $this->assertFalse($result['canonical_registry']['checked']);
        $this->assertContains('manual_review_missing', $result['blockers']);
        $this->assertContains('visible_dates_unresolved', $result['blockers']);
        $this->assertContains('approved_media_missing', $result['blockers']);
        $this->assertSame(0, array_sum($result['actions']));
    }

    public function test_registry_preflight_requires_public_active_big_five_canonical_authority_and_writes_nothing(): void
    {
        $this->seedCanonicalScale();
        $before = [
            'topics' => TopicProfile::query()->withoutGlobalScopes()->count(),
            'revisions' => TopicProfileRevision::query()->count(),
        ];

        $result = app(BigFiveTopicAuthorityDraftPreflight::class)->preflight(self::PACKAGE);

        $this->assertSame('PASS_READ_ONLY_TOPIC_DRAFT_PREFLIGHT', $result['status']);
        $this->assertSame([
            'checked' => true,
            'status' => 'pass',
            'scale_code' => 'BIG5_OCEAN',
            'primary_slug' => 'big-five-personality-test-ocean-model',
            'is_public' => true,
            'is_active' => true,
        ], $result['canonical_registry']);
        $this->assertSame($before, [
            'topics' => TopicProfile::query()->withoutGlobalScopes()->count(),
            'revisions' => TopicProfileRevision::query()->count(),
        ]);
        $this->assertSame(0, array_sum($result['actions']));
    }

    public function test_preflight_fails_closed_when_big_five_registry_target_drifts_or_is_private(): void
    {
        $this->seedCanonicalScale(primarySlug: 'mbti-personality-test-16-personality-types');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('canonical authority is missing, inactive, private, or drifted');

        app(BigFiveTopicAuthorityDraftPreflight::class)->preflight(self::PACKAGE);
    }

    public function test_package_visible_copy_removes_internal_operations_and_keeps_career_supplementary_only(): void
    {
        $package = json_decode(File::get(base_path(self::PACKAGE)), true, 512, JSON_THROW_ON_ERROR);
        $visible = [];

        foreach ($package['topics'] as $topic) {
            $profile = $topic['snapshot']['profile'];
            $sections = $topic['snapshot']['sections'];
            $entry = $topic['snapshot']['entries'][0];
            $authority = $topic['snapshot']['authority'];

            $this->assertSame('tests', $entry['group_key']);
            $this->assertSame('BIG5_OCEAN', $entry['target_key']);
            $this->assertNull($entry['target_url_override']);
            $this->assertSame('supplementary_explanation_only', $sections[2]['payload_json']['career_claim_mode']);
            $this->assertFalse($sections[2]['payload_json']['recommendation_authority']);
            $this->assertFalse($authority['recommendation_authority']);
            $this->assertFalse($authority['diagnostic_authority']);
            $this->assertFalse($authority['outcome_prediction_authority']);
            $this->assertNull($authority['visible_provenance']['author']);
            $this->assertNull($authority['visible_provenance']['reviewer']);
            $this->assertCount(2, $authority['visible_provenance']['sources']);
            $this->assertNull($authority['visible_dates']['published_at']);
            $this->assertFalse($authority['media']['media_eligible']);

            $visible[] = implode("\n", array_filter([
                $profile['title'],
                $profile['subtitle'],
                $profile['excerpt'],
                ...array_merge(...array_map(static fn (array $section): array => [
                    $section['title'],
                    $section['body_md'],
                ], $sections)),
                $entry['title_override'],
                $entry['excerpt_override'],
            ]));
        }

        $copy = mb_strtolower(implode("\n", $visible));
        foreach (['seo cluster', 'trait-based recommendation', 'career recommendation', 'career matcher', '职业推荐', '职业匹配', '特质推荐', 'seo 主题簇', 'mbti'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $copy);
        }
    }

    public function test_console_command_is_package_only_by_explicit_option_and_has_no_write_mode(): void
    {
        $this->artisan('personality:big-five-authority-v2-topic-draft-preflight', [
            '--package' => self::PACKAGE,
            '--package-only' => true,
        ])
            ->expectsOutputToContain('PASS_PACKAGE_ONLY_TOPIC_DRAFT_PREFLIGHT')
            ->expectsOutputToContain('database_writes=0')
            ->expectsOutputToContain('revision_writes=0')
            ->expectsOutputToContain('promotion_eligible=0')
            ->assertExitCode(0);

        $this->assertSame(0, TopicProfile::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, TopicProfileRevision::query()->count());
    }

    private function seedCanonicalScale(string $primarySlug = 'big-five-personality-test-ocean-model'): void
    {
        DB::table('scales_registry')->delete();
        DB::table('scales_registry')->insert([
            'code' => 'BIG5_OCEAN',
            'org_id' => 0,
            'primary_slug' => $primarySlug,
            'slugs_json' => json_encode([$primarySlug], JSON_THROW_ON_ERROR),
            'driver_type' => 'BIG5_OCEAN',
            'default_pack_id' => null,
            'default_region' => null,
            'default_locale' => null,
            'default_dir_version' => null,
            'capabilities_json' => null,
            'view_policy_json' => null,
            'commercial_json' => null,
            'seo_schema_json' => null,
            'is_public' => 1,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
