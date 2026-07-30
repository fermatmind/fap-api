<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Services\BigFive\AuthorityV3\ReadOnly\BigFiveEnglishDraftInventory;
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

        $this->assertTrue($result['ok']);
        $this->assertSame(50, $result['counts']['historical_slots']);
        $this->assertSame(
            '50 registered historical slot identities from the 52-page EN52 canonical catalog, excluding model hub and facet hub',
            $result['cohort_definition'],
        );
        $this->assertSame(0, $result['counts']['observed_slot_assets']);
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
        $published = $this->createRevision($asset, 1, 'release-one', $snapshot);
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
        $this->assertSame('duplicate_of_published', $row['recommended_disposition']);
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
        $this->createRevision($asset, 2, 'big-five-en52-52-page-release-20260719', $this->completeSnapshot('Old publish'));
        $current = $this->createRevision($asset, 3, 'later-release', $this->completeSnapshot('Current'));
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
        $published = $this->createRevision($asset, 1, 'release-one', $this->completeSnapshot('Published'));
        $working = $this->createRevision($asset, 2, 'working-two', $this->completeSnapshot('Working'));
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
        $this->assertSame('verify_only_no_action', $row['recommended_disposition']);
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
        return PersonalityPublicContentAssetRevision::query()->create([
            'asset_id' => $asset->id,
            'revision_no' => $revisionNo,
            'authority_asset_key' => 'big-five:domain:openness:en',
            'source_package' => $sourcePackage,
            'source_hash' => hash('sha256', $sourcePackage.':'.$revisionNo),
            'authority_package_sha256' => hash('sha256', 'authority:'.$sourcePackage.':'.$revisionNo),
            'workflow_state' => 'draft',
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
