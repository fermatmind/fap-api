<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Services\BigFive\AuthorityV3\ReadOnly\BigFiveEnglishDraftInventory;
use App\Services\BigFive\AuthorityV3\Release\BigFiveEn52PackageCompiler;
use App\Services\BigFive\AuthorityV3\Release\BigFiveEn52Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PersonalityBigFiveEnglishDraftInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_authority_emits_exact_historical_slots_fail_closed_without_writes(): void
    {
        $before = $this->fingerprint();

        $result = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();

        $this->assertFalse($result['ok']);
        $this->assertSame(
            'en-parity-w2-big-five-runtime-draft-inventory.v1',
            $result['schema_version'],
        );
        $this->assertNotSame(
            'en-parity-w2-big-five-draft-inventory.v1',
            $result['schema_version'],
        );
        $this->assertSame('BLOCKED_BIG_FIVE_ENGLISH_DRAFT_INVENTORY_ZERO_WRITE', $result['status']);
        $this->assertSame(50, $result['counts']['historical_slots']);
        $this->assertSame(
            '50 registered historical slot identities from the 52-page EN52 canonical catalog, excluding model hub and facet hub',
            $result['cohort_definition'],
        );
        $this->assertSame(0, $result['counts']['observed_slot_assets']);
        $this->assertSame(50, $result['counts']['blocking_rows']);
        $this->assertSame(['blocked_authority_unknown' => 50], $result['disposition_totals']);
        $this->assertCount(50, $result['rows']);
        $this->assertTrue($result['database_snapshot_unchanged']);
        $this->assertFalse($result['writes_committed']);
        $this->assertSame($before, $this->fingerprint());
    }

    public function test_output_redacts_private_snapshot_values_and_marks_prohibited_content(): void
    {
        $asset = PersonalityPublicContentAsset::query()->create([
            'org_id' => 0,
            'framework' => 'big_five',
            'entity_type' => 'domain',
            'entity_key' => 'openness',
            'slug' => 'big-five/openness',
            'locale' => 'en',
            'title' => 'Openness',
            'summary' => 'A dimensional summary.',
            'content_sections_json' => [],
            'seo_json' => [],
            'robots' => 'index,follow',
            'canonical_json' => ['path' => '/en/personality/big-five/openness'],
            'hreflang_json' => [],
            'faq_json' => [],
            'schema_json' => [],
            'method_boundary_json' => [],
            'evidence_notes_json' => [],
            'authority_json' => ['translation_group_id' => 'big-five:domain:openness'],
            'internal_links_json' => [],
            'is_public' => true,
            'index_eligible' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'launch_state' => 'published',
            'review_state' => 'published',
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
        ]);
        $revision = PersonalityPublicContentAssetRevision::query()->create([
            'asset_id' => $asset->id,
            'revision_no' => 1,
            'authority_asset_key' => 'big-five:domain:openness:en',
            'source_package' => 'test-only',
            'source_hash' => str_repeat('a', 64),
            'authority_package_sha256' => str_repeat('b', 64),
            'workflow_state' => 'draft',
            'public_runtime_fingerprint_before' => str_repeat('c', 64),
            'snapshot_json' => [
                'title' => 'Openness',
                'summary' => 'A dimensional summary.',
                'content_sections_json' => [],
                'faq_json' => [],
                'attempt_id' => 'must-never-appear',
            ],
        ]);
        $asset->forceFill([
            'working_revision_id' => $revision->id,
            'published_revision_id' => $revision->id,
        ])->saveQuietly();
        $historical = $this->createRevision(
            $asset,
            2,
            'big5-authority-v2-domains-08',
            $this->completeSnapshot('Historical openness'),
        );

        $result = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $row = collect($result['rows'])->firstWhere('logical_identity', 'domain:openness');
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);

        $this->assertIsArray($row);
        $this->assertTrue($row['private_result_leakage']);
        $this->assertSame('prohibited_content', $row['recommended_disposition']);
        $this->assertSame($historical->id, $row['historical_draft_revision_id']);
        $this->assertStringNotContainsString('must-never-appear', $encoded);
        $this->assertSame(
            BigFiveEnglishDraftInventory::DISPOSITIONS,
            array_values(array_unique([...BigFiveEnglishDraftInventory::DISPOSITIONS])),
        );
    }

    public function test_inventory_uses_only_org_zero_authority(): void
    {
        $orgZero = $this->createAsset();
        $this->createAsset(['org_id' => 91]);

        $result = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $row = collect($result['rows'])->firstWhere('logical_identity', 'domain:openness');

        $this->assertSame($orgZero->id, $row['backend_resource_id']);
        $this->assertSame(1, $result['counts']['observed_canonical_assets']);
        $this->assertSame(0, $result['counts']['redirect_only_alias_rows']);
    }

    public function test_pointer_equality_uses_revision_ids_while_content_equivalence_remains_separate(): void
    {
        $asset = $this->createAsset();
        $snapshot = $this->completeSnapshot();
        $published = $this->createRevision($asset, 1, BigFiveEn52PackageCompiler::RELEASE_ID, $snapshot);
        $working = $this->createRevision($asset, 2, 'working-copy', $snapshot);
        $asset->forceFill([
            'working_revision_id' => $working->id,
            'published_revision_id' => $published->id,
        ])->saveQuietly();

        $result = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $row = collect($result['rows'])->firstWhere('logical_identity', 'domain:openness');

        $this->assertFalse($row['draft_equals_published']);
        $this->assertTrue($row['draft_content_equals_published']);
        $this->assertSame(1, $result['counts']['independent_working_revisions']);
        $this->assertSame('blocked_authority_unknown', $row['recommended_disposition']);
        $this->assertSame('registered_historical_slot_revision_missing', $row['blocker']);
    }

    public function test_future_scheduled_asset_is_not_counted_as_a_public_projection(): void
    {
        $asset = $this->createAsset(['published_at' => now()->addDay()]);
        $revision = $this->createRevision($asset, 1, 'release-one', $this->completeSnapshot());
        $asset->forceFill([
            'working_revision_id' => $revision->id,
            'published_revision_id' => $revision->id,
        ])->saveQuietly();

        $result = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $row = collect($result['rows'])->firstWhere('logical_identity', 'domain:openness');

        $this->assertFalse($row['published_projection_exists']);
        $this->assertFalse($row['public_page_accessible']);
        $this->assertSame(0, $result['counts']['public_projections']);
    }

    public function test_registered_historical_revision_survives_later_unreferenced_revisions(): void
    {
        $asset = $this->createAsset();
        $historical = $this->createRevision(
            $asset,
            1,
            'big5-authority-v2-domains-08',
            $this->completeSnapshot('Historical'),
        );
        $this->createRevision($asset, 2, 'previous-en52-release', $this->completeSnapshot('Old publish'));
        $current = $this->createRevision(
            $asset,
            3,
            BigFiveEn52PackageCompiler::RELEASE_ID,
            $this->completeSnapshot('Current'),
        );
        $asset->forceFill([
            'working_revision_id' => $current->id,
            'published_revision_id' => $current->id,
        ])->saveQuietly();

        $result = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $row = collect($result['rows'])->firstWhere('logical_identity', 'domain:openness');

        $this->assertSame($historical->id, $row['historical_draft_revision_id']);
        $this->assertSame('big5-authority-v2-domains-08', $row['historical_source_package']);
        $this->assertSame(1, $result['counts']['historical_revision_rows']);
    }

    public function test_revision_number_determines_lineage_order_instead_of_timestamps(): void
    {
        $asset = $this->createAsset();
        $published = $this->createRevision(
            $asset,
            1,
            BigFiveEn52PackageCompiler::RELEASE_ID,
            $this->completeSnapshot('Published'),
        );
        $working = $this->createRevision($asset, 2, 'working-two', $this->completeSnapshot('Working'));
        $this->createRevision(
            $asset,
            3,
            'big5-authority-v2-domains-08',
            $this->completeSnapshot('Historical'),
        );
        DB::table('personality_public_content_asset_revisions')->whereKey($published->id)->update([
            'updated_at' => now()->addDay(),
        ]);
        DB::table('personality_public_content_asset_revisions')->whereKey($working->id)->update([
            'updated_at' => now()->subDay(),
        ]);
        $asset->forceFill([
            'working_revision_id' => $working->id,
            'published_revision_id' => $published->id,
        ])->saveQuietly();

        $result = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $row = collect($result['rows'])->firstWhere('logical_identity', 'domain:openness');

        $this->assertTrue($row['draft_newer_than_published']);
        $this->assertSame('valid_unpublished_candidate', $row['recommended_disposition']);
        $this->assertNull($row['blocker']);
    }

    public function test_alias_count_excludes_unknown_authority_drift(): void
    {
        $this->createAsset([
            'entity_type' => PersonalityPublicContentAsset::ENTITY_POLARITY,
            'entity_key' => 'high-openness',
            'slug' => 'big-five/high-openness',
            'canonical_json' => ['path' => '/en/personality/big-five/high-openness'],
        ]);
        $this->createAsset([
            'canonical_json' => ['path' => '/en/personality/big-five/openness-drifted'],
        ]);

        $result = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();

        $this->assertSame(1, $result['counts']['redirect_only_alias_rows']);
        $this->assertSame(1, $result['counts']['unknown_authority_rows']);
        $this->assertFalse($result['ok']);
    }

    public function test_missing_or_wrong_registered_historical_slot_fails_closed(): void
    {
        $asset = $this->createAsset();
        $current = $this->createRevision(
            $asset,
            3,
            BigFiveEn52PackageCompiler::RELEASE_ID,
            $this->completeSnapshot(),
        );
        $asset->forceFill([
            'working_revision_id' => $current->id,
            'published_revision_id' => $current->id,
        ])->saveQuietly();

        $missing = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $missingRow = collect($missing['rows'])->firstWhere('logical_identity', 'domain:openness');

        $this->assertSame('missing', $missingRow['historical_slot_resolution']);
        $this->assertSame('blocked_authority_unknown', $missingRow['recommended_disposition']);
        $this->assertSame('registered_historical_slot_revision_missing', $missingRow['blocker']);
        $this->assertFalse($missing['ok']);

        $this->createRevision($asset, 1, 'big5-authority-v2-domains-09', $this->completeSnapshot('Wrong package'));

        $wrong = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $wrongRow = collect($wrong['rows'])->firstWhere('logical_identity', 'domain:openness');

        $this->assertSame('missing', $wrongRow['historical_slot_resolution']);
        $this->assertSame('blocked_authority_unknown', $wrongRow['recommended_disposition']);
        $this->assertSame('registered_historical_slot_revision_missing', $wrongRow['blocker']);
        $this->assertFalse($wrong['ok']);
    }

    public function test_historical_snapshot_leakage_is_sanitized_and_blocks_stale_classification(): void
    {
        $asset = $this->createAsset();
        $historical = $this->createRevision(
            $asset,
            1,
            'big5-authority-v2-domains-08',
            [
                ...$this->completeSnapshot('历史'),
                'answers' => ['private-answer-must-not-appear'],
                'attempt_uuid' => 'private-attempt-must-not-appear',
                'report_url' => 'https://private.invalid/report',
                'user_id' => 'private-user-must-not-appear',
                'raw_scores' => ['private-score-must-not-appear'],
                'facet_vector' => ['private-facet-must-not-appear'],
                'body' => '<picture><source srcset="https://private.invalid/image.webp"></picture>',
            ],
        );
        $current = $this->createRevision(
            $asset,
            2,
            BigFiveEn52PackageCompiler::RELEASE_ID,
            $this->completeSnapshot(),
        );
        $asset->forceFill([
            'working_revision_id' => $current->id,
            'published_revision_id' => $current->id,
        ])->saveQuietly();

        $result = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $row = collect($result['rows'])->firstWhere('logical_identity', 'domain:openness');
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);

        $this->assertSame($historical->id, $row['historical_draft_revision_id']);
        $this->assertTrue($row['historical_private_result_leakage']);
        $this->assertTrue($row['historical_media_reference']);
        $this->assertTrue($row['historical_chinese_leakage']);
        $this->assertSame('prohibited_content', $row['recommended_disposition']);
        $this->assertSame('historical_draft_prohibited_content', $row['blocker']);
        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString('private-attempt-must-not-appear', $encoded);
        $this->assertStringNotContainsString('private.invalid', $encoded);
        $this->assertStringNotContainsString('private-answer-must-not-appear', $encoded);
        $this->assertStringNotContainsString('private-user-must-not-appear', $encoded);
        $this->assertStringNotContainsString('private-score-must-not-appear', $encoded);
        $this->assertStringNotContainsString('private-facet-must-not-appear', $encoded);
    }

    public function test_non_en52_published_pointer_and_wrong_slot_family_fail_closed(): void
    {
        $asset = $this->createAsset();
        $historical = $this->createRevision(
            $asset,
            1,
            'big5-authority-v2-domains-08',
            $this->completeSnapshot('Historical'),
        );
        $unlocked = $this->createRevision($asset, 2, 'not-the-en52-release', $this->completeSnapshot());
        $asset->forceFill([
            'working_revision_id' => $unlocked->id,
            'published_revision_id' => $unlocked->id,
        ])->saveQuietly();

        $result = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $row = collect($result['rows'])->firstWhere('logical_identity', 'domain:openness');

        $this->assertSame($historical->id, $row['historical_draft_revision_id']);
        $this->assertFalse($row['published_en52_lineage_locked']);
        $this->assertSame('blocked_authority_unknown', $row['recommended_disposition']);
        $this->assertSame('current_published_revision_not_locked_en52_authority', $row['blocker']);
        $this->assertFalse($result['ok']);

        $facet = $this->createAsset([
            'entity_type' => PersonalityPublicContentAsset::ENTITY_FACET_DETAIL,
            'entity_key' => 'imagination',
            'slug' => 'big-five/facets/imagination',
            'canonical_json' => ['path' => '/en/personality/big-five/facets/imagination'],
        ]);
        $wrongFamily = $this->createRevision(
            $facet,
            1,
            'big5-authority-v2-domains-08',
            $this->completeSnapshot('Wrong family'),
        );
        $facetCurrent = $this->createRevision(
            $facet,
            2,
            BigFiveEn52PackageCompiler::RELEASE_ID,
            $this->completeSnapshot('Imagination'),
        );
        $facet->forceFill([
            'working_revision_id' => $facetCurrent->id,
            'published_revision_id' => $facetCurrent->id,
        ])->saveQuietly();

        $wrongFamilyResult = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $facetRow = collect($wrongFamilyResult['rows'])->firstWhere('logical_identity', 'facet_detail:imagination');

        $this->assertNotSame($wrongFamily->id, $facetRow['historical_draft_revision_id']);
        $this->assertSame('missing', $facetRow['historical_slot_resolution']);
        $this->assertSame('registered_historical_slot_revision_missing', $facetRow['blocker']);
    }

    /** @param array<string,mixed> $overrides */
    private function createAsset(array $overrides = []): PersonalityPublicContentAsset
    {
        return PersonalityPublicContentAsset::query()->create(array_replace([
            'org_id' => 0,
            'framework' => 'big_five',
            'entity_type' => 'domain',
            'entity_key' => 'openness',
            'slug' => 'big-five/openness',
            'locale' => 'en',
            'title' => 'Openness',
            'summary' => 'A dimensional summary.',
            'content_sections_json' => [],
            'seo_json' => [],
            'robots' => 'index,follow',
            'canonical_json' => ['path' => '/en/personality/big-five/openness'],
            'hreflang_json' => [],
            'faq_json' => [],
            'schema_json' => [],
            'method_boundary_json' => [],
            'evidence_notes_json' => [],
            'authority_json' => ['translation_group_id' => 'big-five:domain:openness'],
            'internal_links_json' => [],
            'is_public' => true,
            'index_eligible' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'launch_state' => 'published',
            'review_state' => 'published',
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V2,
        ], $overrides));
    }

    /** @param array<string,mixed> $snapshot */
    private function createRevision(
        PersonalityPublicContentAsset $asset,
        int $revisionNo,
        string $sourcePackage,
        array $snapshot,
    ): PersonalityPublicContentAssetRevision {
        $historical = str_starts_with($sourcePackage, 'big5-authority-v2-');
        $en52 = $sourcePackage === BigFiveEn52PackageCompiler::RELEASE_ID;
        $domain = match ((string) $asset->entity_type) {
            PersonalityPublicContentAsset::ENTITY_DOMAIN => (string) $asset->entity_key,
            PersonalityPublicContentAsset::ENTITY_POLARITY => explode('-', (string) $asset->entity_key, 2)[0],
            PersonalityPublicContentAsset::ENTITY_FACET_DETAIL => 'openness',
            default => '',
        };
        $authorityAssetKey = match ((string) $asset->entity_type) {
            PersonalityPublicContentAsset::ENTITY_DOMAIN => 'domain:'.$asset->entity_key,
            PersonalityPublicContentAsset::ENTITY_POLARITY => 'range:'.$domain.':'
                .(explode('-', (string) $asset->entity_key, 2)[1] ?? ''),
            PersonalityPublicContentAsset::ENTITY_FACET_DETAIL => 'facet:'.$domain.':'.$asset->entity_key,
            default => (string) $asset->entity_key,
        };

        return PersonalityPublicContentAssetRevision::query()->create([
            'asset_id' => $asset->id,
            'revision_no' => $revisionNo,
            'authority_asset_key' => $historical
                ? $authorityAssetKey
                : ($en52 && $asset->entity_type === PersonalityPublicContentAsset::ENTITY_FACET_DETAIL
                    ? 'O1-imagination'
                    : (string) $asset->entity_key),
            'source_package' => $sourcePackage,
            'source_hash' => hash('sha256', $sourcePackage.':'.$revisionNo),
            'authority_package_sha256' => $historical
                ? 'fb67edc033e679da3f134b34db30901465c7b44e0585818b23613fab83bf9162'
                : ($en52
                    ? BigFiveEn52Publisher::PACKAGE_FILE_SHA256
                    : hash('sha256', 'authority:'.$sourcePackage.':'.$revisionNo)),
            'workflow_state' => $en52 ? BigFiveEn52Publisher::WORKFLOW_STATE : 'draft',
            'public_runtime_fingerprint_before' => str_repeat('c', 64),
            'snapshot_json' => $snapshot,
        ]);
    }

    /** @return array<string,mixed> */
    private function completeSnapshot(string $title = 'Openness'): array
    {
        return [
            'title' => $title,
            'summary' => 'A dimensional summary.',
            'content_sections_json' => [],
            'faq_json' => [],
        ];
    }

    private function fingerprint(): string
    {
        return hash('sha256', json_encode([
            DB::table('personality_public_content_assets')->orderBy('id')->get(),
            DB::table('personality_public_content_asset_revisions')->orderBy('id')->get(),
        ], JSON_THROW_ON_ERROR));
    }
}
