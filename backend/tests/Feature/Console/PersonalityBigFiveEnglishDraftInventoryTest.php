<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Services\BigFive\AuthorityV2\ReleaseGate\BigFiveAuthorityV2DraftImportWriter;
use App\Services\BigFive\AuthorityV3\ReadOnly\BigFiveEnglishDraftInventory;
use App\Services\BigFive\AuthorityV3\Release\BigFiveEn52PackageCompiler;
use App\Services\BigFive\AuthorityV3\Release\BigFiveEn52Publisher;
use App\Services\Cms\PersonalityPublicContentAssetContract;
use App\Services\SEO\BigFiveCanonicalRouteCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PersonalityBigFiveEnglishDraftInventoryTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,array{authority_asset_key:string,source_hash:string,attributes:array<string,mixed>,snapshot:array<string,mixed>}>|null */
    private static ?array $en52Descriptors = null;

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $historicalSnapshots = null;

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
        $this->assertFalse($result['canonical_cohort_complete']);
        $this->assertFalse($result['excluded_hub_authority_complete']);
        $this->assertCount(2, $result['excluded_hub_rows']);
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

    public function test_excluded_hubs_require_locked_en52_authority_and_public_projection(): void
    {
        $hubs = [
            [
                'entity_type' => PersonalityPublicContentAsset::ENTITY_HUB,
                'entity_key' => 'big-five',
                'slug' => 'big-five',
                'canonical_json' => ['path' => '/en/personality/big-five'],
            ],
            [
                'entity_type' => PersonalityPublicContentAsset::ENTITY_FACET_HUB,
                'entity_key' => 'facets',
                'slug' => 'big-five/facets',
                'canonical_json' => ['path' => '/en/personality/big-five/facets'],
            ],
        ];
        $revisions = [];
        foreach ($hubs as $overrides) {
            $asset = $this->createAsset($overrides);
            $revision = $this->createRevision(
                $asset,
                1,
                BigFiveEn52PackageCompiler::RELEASE_ID,
                $this->completeSnapshot((string) $asset->entity_key),
            );
            $asset->forceFill([
                'working_revision_id' => $revision->id,
                'published_revision_id' => $revision->id,
            ])->saveQuietly();
            $revisions[] = $revision;
        }

        $valid = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();

        $this->assertTrue($valid['excluded_hub_authority_complete']);
        $this->assertSame(2, $valid['counts']['validated_excluded_hub_assets']);
        $this->assertTrue(collect($valid['excluded_hub_rows'])->every(
            fn (array $row): bool => $row['published_en52_lineage_locked'] === true
                && $row['published_projection_exists'] === true,
        ));

        $revisions[0]->forceFill(['source_package' => 'unlocked-hub-release'])->saveQuietly();
        $invalid = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $modelHub = collect($invalid['excluded_hub_rows'])->firstWhere('logical_identity', 'hub:big-five');

        $this->assertFalse($invalid['excluded_hub_authority_complete']);
        $this->assertFalse($invalid['canonical_cohort_complete']);
        $this->assertSame('blocked_authority_unknown', $modelHub['recommended_disposition']);
        $this->assertSame('current_published_revision_not_locked_en52_authority', $modelHub['blocker']);
    }

    public function test_pointer_equality_uses_revision_ids_while_content_equivalence_remains_separate(): void
    {
        $asset = $this->createAsset();
        $snapshot = $this->completeSnapshot();
        $published = $this->createRevision($asset, 1, BigFiveEn52PackageCompiler::RELEASE_ID, $snapshot);
        $working = $this->createRevision(
            $asset,
            2,
            'working-copy',
            (array) data_get($published->snapshot_json, 'attributes'),
        );
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

    public function test_working_envelope_private_fields_and_complete_markdown_image_syntax_fail_closed(): void
    {
        $asset = $this->createAsset();
        $published = $this->createRevision(
            $asset,
            1,
            BigFiveEn52PackageCompiler::RELEASE_ID,
            $this->completeSnapshot(),
        );
        $working = $this->createRevision($asset, 2, 'working-copy', [
            'attributes' => $this->completeSnapshot('Candidate'),
            'attempt_id' => 'private-envelope-attempt',
            'body' => '![alt text] (https://private.invalid/image.webp)',
        ]);
        $asset->forceFill([
            'working_revision_id' => $working->id,
            'published_revision_id' => $published->id,
        ])->saveQuietly();

        $prohibited = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $row = collect($prohibited['rows'])->firstWhere('logical_identity', 'domain:openness');
        $encoded = json_encode($prohibited, JSON_THROW_ON_ERROR);

        $this->assertTrue($row['private_result_leakage']);
        $this->assertFalse($row['claim_boundary_compliant']);
        $this->assertFalse($row['text_only_compliant']);
        $this->assertSame('prohibited_content', $row['recommended_disposition']);
        $this->assertStringNotContainsString('private-envelope-attempt', $encoded);
        $this->assertStringNotContainsString('private.invalid', $encoded);

        $working->forceFill(['snapshot_json' => [
            'attributes' => $this->completeSnapshot('Candidate'),
            'body' => '![]()',
        ]])->saveQuietly();
        $emptyDestination = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $emptyDestinationRow = collect($emptyDestination['rows'])
            ->firstWhere('logical_identity', 'domain:openness');

        $this->assertFalse($emptyDestinationRow['text_only_compliant']);
        $this->assertSame('prohibited_content', $emptyDestinationRow['recommended_disposition']);

        $working->forceFill(['snapshot_json' => [
            'attributes' => [
                ...$this->completeSnapshot('Candidate'),
                'summary' => "Supplementary CJK \u{20000}",
            ],
        ]])->saveQuietly();
        $supplementaryCjk = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $supplementaryCjkRow = collect($supplementaryCjk['rows'])
            ->firstWhere('logical_identity', 'domain:openness');

        $this->assertTrue($supplementaryCjkRow['chinese_leakage']);
        $this->assertSame('prohibited_content', $supplementaryCjkRow['recommended_disposition']);
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

    public function test_locked_en52_projection_requires_non_null_publication_timestamp(): void
    {
        $asset = $this->createAsset(['published_at' => null]);
        $revision = $this->createRevision(
            $asset,
            1,
            BigFiveEn52PackageCompiler::RELEASE_ID,
            $this->completeSnapshot(),
        );
        $asset->forceFill([
            'working_revision_id' => $revision->id,
            'published_revision_id' => $revision->id,
        ])->saveQuietly();

        $result = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $row = collect($result['rows'])->firstWhere('logical_identity', 'domain:openness');

        $this->assertTrue($row['published_en52_lineage_locked']);
        $this->assertFalse($row['published_en52_projection_locked']);
        $this->assertFalse($row['published_projection_exists']);
        $this->assertSame('live_asset_not_locked_en52_projection', $row['blocker']);
    }

    public function test_locked_en52_package_revision_set_rejects_an_extra_revision(): void
    {
        $asset = $this->createAsset();
        $current = $this->createRevision(
            $asset,
            1,
            BigFiveEn52PackageCompiler::RELEASE_ID,
            $this->completeSnapshot(),
        );
        $asset->forceFill([
            'working_revision_id' => $current->id,
            'published_revision_id' => $current->id,
        ])->saveQuietly();

        $keys = array_keys((new \ReflectionClass(BigFiveEnglishDraftInventory::class))
            ->getConstant('EN52_DESCRIPTOR_LOCKS'));
        $revisionNo = 2;
        foreach (array_diff($keys, ['openness']) as $key) {
            PersonalityPublicContentAssetRevision::query()->create([
                'asset_id' => $asset->id,
                'revision_no' => $revisionNo++,
                'authority_asset_key' => $key,
                'source_package' => BigFiveEn52PackageCompiler::RELEASE_ID,
                'source_hash' => str_repeat('a', 64),
                'authority_package_sha256' => BigFiveEn52Publisher::PACKAGE_FILE_SHA256,
                'workflow_state' => BigFiveEn52Publisher::WORKFLOW_STATE,
                'snapshot_json' => [],
                'public_runtime_fingerprint_before' => str_repeat('b', 64),
                'created_by_admin_user_id' => BigFiveEn52Publisher::OPERATOR_ADMIN_USER_ID,
            ]);
        }

        $complete = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $this->assertTrue($complete['en52_package_revision_set_complete']);
        $this->assertSame(52, $complete['counts']['observed_en52_package_revisions']);

        PersonalityPublicContentAssetRevision::query()->create([
            'asset_id' => $asset->id,
            'revision_no' => $revisionNo,
            'authority_asset_key' => 'unexpected-extra-en52-revision',
            'source_package' => BigFiveEn52PackageCompiler::RELEASE_ID,
            'source_hash' => str_repeat('c', 64),
            'authority_package_sha256' => BigFiveEn52Publisher::PACKAGE_FILE_SHA256,
            'workflow_state' => BigFiveEn52Publisher::WORKFLOW_STATE,
            'snapshot_json' => [],
            'public_runtime_fingerprint_before' => str_repeat('d', 64),
            'created_by_admin_user_id' => BigFiveEn52Publisher::OPERATOR_ADMIN_USER_ID,
        ]);

        $extra = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $this->assertFalse($extra['en52_package_revision_set_complete']);
        $this->assertFalse($extra['canonical_cohort_complete']);
        $this->assertFalse($extra['ok']);
        $this->assertSame(53, $extra['counts']['observed_en52_package_revisions']);
    }

    public function test_live_asset_drift_from_locked_en52_projection_fails_closed(): void
    {
        $asset = $this->createAsset();
        $historical = $this->createRevision(
            $asset,
            1,
            'big5-authority-v2-domains-08',
            $this->completeSnapshot('Historical'),
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
            'title' => 'Drifted live title',
            'llms_eligible' => false,
        ])->saveQuietly();

        $result = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $row = collect($result['rows'])->firstWhere('logical_identity', 'domain:openness');

        $this->assertSame($historical->id, $row['historical_draft_revision_id']);
        $this->assertTrue($row['published_en52_lineage_locked']);
        $this->assertFalse($row['published_en52_projection_locked']);
        $this->assertFalse($row['published_projection_exists']);
        $this->assertSame('blocked_authority_unknown', $row['recommended_disposition']);
        $this->assertSame('live_asset_not_locked_en52_projection', $row['blocker']);
        $this->assertFalse($result['ok']);
    }

    public function test_revision_and_live_asset_cannot_self_attest_outside_compiled_en52_descriptor(): void
    {
        $asset = $this->createAsset();
        $this->createRevision(
            $asset,
            1,
            'big5-authority-v2-domains-08',
            $this->completeSnapshot('Historical'),
        );
        $current = $this->createRevision(
            $asset,
            2,
            BigFiveEn52PackageCompiler::RELEASE_ID,
            $this->completeSnapshot(),
        );
        $substitutedHash = str_repeat('f', 64);
        $substitutedSnapshot = $current->snapshot_json;
        data_set($substitutedSnapshot, 'attributes.source_hash', $substitutedHash);
        $current->forceFill([
            'source_hash' => $substitutedHash,
            'snapshot_json' => $substitutedSnapshot,
        ])->saveQuietly();
        $asset->forceFill([
            'working_revision_id' => $current->id,
            'published_revision_id' => $current->id,
            'source_hash' => $substitutedHash,
        ])->saveQuietly();

        $result = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $row = collect($result['rows'])->firstWhere('logical_identity', 'domain:openness');

        $this->assertFalse($row['published_en52_lineage_locked']);
        $this->assertFalse($row['published_en52_projection_locked']);
        $this->assertFalse($row['published_projection_exists']);
        $this->assertSame('blocked_authority_unknown', $row['recommended_disposition']);
        $this->assertSame('current_published_revision_not_locked_en52_authority', $row['blocker']);
    }

    public function test_locked_en52_revision_requires_exact_publisher_operator(): void
    {
        $asset = $this->createAsset();
        $current = $this->createRevision(
            $asset,
            2,
            BigFiveEn52PackageCompiler::RELEASE_ID,
            $this->completeSnapshot(),
        );
        $current->forceFill(['created_by_admin_user_id' => null])->saveQuietly();
        $asset->forceFill([
            'working_revision_id' => $current->id,
            'published_revision_id' => $current->id,
        ])->saveQuietly();

        $result = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $row = collect($result['rows'])->firstWhere('logical_identity', 'domain:openness');

        $this->assertFalse($row['published_en52_lineage_locked']);
        $this->assertFalse($row['published_projection_exists']);
        $this->assertSame('blocked_authority_unknown', $row['recommended_disposition']);
        $this->assertSame('current_published_revision_not_locked_en52_authority', $row['blocker']);
    }

    public function test_historical_slot_requires_exact_draft_import_source_hash(): void
    {
        $asset = $this->createAsset();
        $historical = $this->createRevision(
            $asset,
            1,
            'big5-authority-v2-domains-08',
            $this->completeSnapshot('Historical'),
        );
        $historical->forceFill(['source_hash' => str_repeat('e', 64)])->saveQuietly();
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

        $this->assertSame('missing', $row['historical_slot_resolution']);
        $this->assertNull($row['historical_draft_revision_id']);
        $this->assertSame('blocked_authority_unknown', $row['recommended_disposition']);
        $this->assertSame('registered_historical_slot_revision_missing', $row['blocker']);
    }

    public function test_historical_slot_requires_exact_transformed_snapshot(): void
    {
        $asset = $this->createAsset();
        $historical = $this->createRevision(
            $asset,
            1,
            'big5-authority-v2-domains-08',
            [
                ...$this->completeSnapshot('Substituted clean snapshot'),
                'clean_note' => 'not part of the registered Authority V2 snapshot',
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

        $this->assertSame($historical->id, $row['historical_draft_revision_id']);
        $this->assertFalse($row['historical_snapshot_locked']);
        $this->assertSame('snapshot_mismatch', $row['historical_slot_resolution']);
        $this->assertSame('blocked_authority_unknown', $row['recommended_disposition']);
        $this->assertSame('registered_historical_slot_snapshot_mismatch', $row['blocker']);
    }

    public function test_historical_slot_requires_draft_workflow_state(): void
    {
        $asset = $this->createAsset();
        $historical = $this->createRevision(
            $asset,
            1,
            'big5-authority-v2-domains-08',
            $this->completeSnapshot('Historical'),
        );
        $historical->forceFill(['workflow_state' => 'pending_manual_review'])->saveQuietly();
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

        $this->assertSame('missing', $row['historical_slot_resolution']);
        $this->assertNull($row['historical_draft_revision_id']);
        $this->assertSame('registered_historical_slot_revision_missing', $row['blocker']);
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

    public function test_independent_working_candidate_requires_draft_workflow_state(): void
    {
        $asset = $this->createAsset();
        $published = $this->createRevision(
            $asset,
            1,
            BigFiveEn52PackageCompiler::RELEASE_ID,
            $this->completeSnapshot('Published'),
        );
        $working = $this->createRevision($asset, 2, 'working-two', $this->completeSnapshot('Working'));
        $working->forceFill(['workflow_state' => 'pending_manual_review'])->saveQuietly();
        $asset->forceFill([
            'working_revision_id' => $working->id,
            'published_revision_id' => $published->id,
        ])->saveQuietly();

        $result = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $row = collect($result['rows'])->firstWhere('logical_identity', 'domain:openness');

        $this->assertFalse($row['candidate_workflow_draft']);
        $this->assertSame('schema_repair_required', $row['recommended_disposition']);
        $this->assertFalse($result['ok']);
    }

    public function test_candidate_title_and_summary_must_be_non_empty_strings(): void
    {
        $asset = $this->createAsset();
        $workingSnapshot = $this->completeSnapshot('Working');
        $workingSnapshot['title'] = ['not-a-scalar'];
        $workingSnapshot['summary'] = ['not-a-scalar'];
        $working = $this->createRevision($asset, 1, 'working-one', $workingSnapshot);
        $asset->forceFill(['working_revision_id' => $working->id])->saveQuietly();

        $result = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $row = collect($result['rows'])->firstWhere('logical_identity', 'domain:openness');

        $this->assertFalse($row['title_complete']);
        $this->assertFalse($row['summary_complete']);
        $this->assertFalse($row['schema_complete']);
        $this->assertSame('schema_repair_required', $row['recommended_disposition']);
        $this->assertFalse($result['ok']);
    }

    public function test_prohibited_history_blocks_without_erasing_current_candidate_classification(): void
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
            [
                ...$this->completeSnapshot('Historical'),
                'private_path' => 'private-history-must-not-appear',
            ],
        );
        $asset->forceFill([
            'working_revision_id' => $working->id,
            'published_revision_id' => $published->id,
        ])->saveQuietly();

        $result = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $row = collect($result['rows'])->firstWhere('logical_identity', 'domain:openness');
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);

        $this->assertSame('valid_unpublished_candidate', $row['current_revision_disposition']);
        $this->assertSame('prohibited_content', $row['recommended_disposition']);
        $this->assertSame('historical_draft_prohibited_content', $row['blocker']);
        $this->assertTrue($row['historical_private_result_leakage']);
        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString('private-history-must-not-appear', $encoded);
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
        $this->assertFalse($result['redirect_only_aliases_absent']);
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

    public function test_registered_historical_revision_is_preserved_when_pointer_active(): void
    {
        $asset = $this->createAsset();
        $historical = $this->createRevision(
            $asset,
            1,
            'big5-authority-v2-domains-08',
            $this->completeSnapshot('Historical active'),
        );
        $asset->forceFill([
            'working_revision_id' => $historical->id,
            'published_revision_id' => $historical->id,
        ])->saveQuietly();

        $result = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $row = collect($result['rows'])->firstWhere('logical_identity', 'domain:openness');

        $this->assertSame($historical->id, $row['historical_draft_revision_id']);
        $this->assertSame('resolved', $row['historical_slot_resolution']);
        $this->assertTrue($row['historical_draft_pointer_active']);
        $this->assertTrue($row['historical_working_pointer_active']);
        $this->assertTrue($row['historical_published_pointer_active']);
        $this->assertSame('blocked_authority_unknown', $row['recommended_disposition']);
        $this->assertSame('current_published_revision_not_locked_en52_authority', $row['blocker']);
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
                'domain_vector' => ['private-domain-must-not-appear'],
                'private_path' => 'private-path-must-not-appear',
                'images' => ['https://private.invalid/plural-image.webp'],
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
        $this->assertStringNotContainsString('private-domain-must-not-appear', $encoded);
        $this->assertStringNotContainsString('private-path-must-not-appear', $encoded);
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
        $entityType = (string) ($overrides['entity_type'] ?? PersonalityPublicContentAsset::ENTITY_DOMAIN);
        $entityKey = (string) ($overrides['entity_key'] ?? 'openness');
        $descriptor = $this->en52Descriptor($entityType, $entityKey);
        $attributes = $descriptor['attributes'] ?? [
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
            'source_package' => BigFiveEn52PackageCompiler::RELEASE_ID,
            'source_hash' => hash('sha256', 'test-en52-openness'),
            'created_by_admin_user_id' => BigFiveEn52Publisher::OPERATOR_ADMIN_USER_ID,
            'updated_by_admin_user_id' => BigFiveEn52Publisher::OPERATOR_ADMIN_USER_ID,
        ];
        $attributes['published_at'] ??= now()->subDay();

        return PersonalityPublicContentAsset::query()->create(array_replace($attributes, $overrides));
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
        $en52AuthorityAssetKey = match (true) {
            $asset->entity_type === PersonalityPublicContentAsset::ENTITY_HUB => 'big-five-hub',
            $asset->entity_type === PersonalityPublicContentAsset::ENTITY_FACET_DETAIL => 'O1-imagination',
            default => (string) $asset->entity_key,
        };
        if ($en52) {
            $descriptor = $this->en52Descriptor(
                (string) $asset->entity_type,
                (string) $asset->entity_key,
            );
            $snapshot = $descriptor['snapshot'] ?? [];
        } elseif ($historical && array_keys($snapshot) === [
            'title',
            'summary',
            'content_sections_json',
            'faq_json',
        ]) {
            $snapshot = $this->historicalSnapshot(
                (string) $asset->entity_type,
                (string) $asset->entity_key,
            ) ?? $snapshot;
        }

        return PersonalityPublicContentAssetRevision::query()->create([
            'asset_id' => $asset->id,
            'revision_no' => $revisionNo,
            'authority_asset_key' => $historical
                ? $authorityAssetKey
                : match (true) {
                    $en52 => $en52AuthorityAssetKey,
                    default => (string) $asset->entity_key,
                },
            'source_package' => $sourcePackage,
            'source_hash' => match (true) {
                $en52 => (string) ($descriptor['source_hash'] ?? ''),
                $historical => $this->historicalSourceHash($asset),
                default => hash('sha256', $sourcePackage.':'.$revisionNo),
            },
            'authority_package_sha256' => $historical
                ? 'fb67edc033e679da3f134b34db30901465c7b44e0585818b23613fab83bf9162'
                : ($en52
                    ? BigFiveEn52Publisher::PACKAGE_FILE_SHA256
                    : hash('sha256', 'authority:'.$sourcePackage.':'.$revisionNo)),
            'workflow_state' => $en52 ? BigFiveEn52Publisher::WORKFLOW_STATE : 'draft',
            'public_runtime_fingerprint_before' => str_repeat('c', 64),
            'snapshot_json' => $snapshot,
            'created_by_admin_user_id' => $en52 ? BigFiveEn52Publisher::OPERATOR_ADMIN_USER_ID : null,
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

    /**
     * @return array{authority_asset_key:string,source_hash:string,attributes:array<string,mixed>,snapshot:array<string,mixed>}|null
     */
    private function en52Descriptor(string $entityType, string $entityKey): ?array
    {
        if (self::$en52Descriptors === null) {
            $path = dirname(__DIR__, 4).'/generated/big-five-en52-release/release-package.json';
            $package = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $wanted = [
                PersonalityPublicContentAsset::ENTITY_HUB.':big-five' => true,
                PersonalityPublicContentAsset::ENTITY_FACET_HUB.':facets' => true,
                PersonalityPublicContentAsset::ENTITY_DOMAIN.':openness' => true,
                PersonalityPublicContentAsset::ENTITY_FACET_DETAIL.':imagination' => true,
            ];
            $descriptors = [];
            $contract = $this->app->make(PersonalityPublicContentAssetContract::class);
            foreach ($package['assets'] as $entry) {
                $identity = data_get($entry, 'asset.entity_type').':'.data_get($entry, 'asset.entity_key');
                if (! isset($wanted[$identity])) {
                    continue;
                }
                $data = $contract->validateAsset($entry['asset']);
                $attributes = $data->toModelAttributes();
                $attributes['hreflang_json'] = [
                    'en' => BigFiveCanonicalRouteCatalog::expectedPath('en', $data->entityType, $data->entityKey),
                    'zh-CN' => BigFiveCanonicalRouteCatalog::expectedPath('zh-CN', $data->entityType, $data->entityKey),
                ];
                $attributes['created_by_admin_user_id'] = BigFiveEn52Publisher::OPERATOR_ADMIN_USER_ID;
                $attributes['updated_by_admin_user_id'] = BigFiveEn52Publisher::OPERATOR_ADMIN_USER_ID;
                $descriptors[$identity] = [
                    'authority_asset_key' => (string) $entry['authority_asset_key'],
                    'source_hash' => (string) $entry['runtime_projection_sha256'],
                    'attributes' => $attributes,
                    'snapshot' => [
                        'schema_version' => BigFiveEn52PackageCompiler::SCHEMA_VERSION,
                        'release_id' => BigFiveEn52PackageCompiler::RELEASE_ID,
                        'authority_asset_key' => (string) $entry['authority_asset_key'],
                        'source_content_sha256' => BigFiveEn52PackageCompiler::SOURCE_CONTENT_SHA256,
                        'package_file_sha256' => BigFiveEn52Publisher::PACKAGE_FILE_SHA256,
                        'attributes' => $attributes,
                        'evidence_claims' => array_values($entry['evidence_claims'] ?? []),
                    ],
                ];
            }
            unset($package);
            self::$en52Descriptors = $descriptors;
        }

        return self::$en52Descriptors[$entityType.':'.$entityKey] ?? null;
    }

    private function historicalSourceHash(PersonalityPublicContentAsset $asset): string
    {
        return match ((string) $asset->entity_type.':'.(string) $asset->entity_key) {
            'domain:openness' => 'ce30596ef26b1c630607b4a24cd3e953e6128406932573fd475e3df350b2d9f1',
            'facet_detail:imagination' => '13c2d7135423a730f1a929f14f53b6e6e5443fbf154ec13d5aae11f0cc7f7365',
            default => hash('sha256', 'historical-test:'.$asset->entity_type.':'.$asset->entity_key),
        };
    }

    /** @return array<string,mixed>|null */
    private function historicalSnapshot(string $entityType, string $entityKey): ?array
    {
        if (self::$historicalSnapshots === null) {
            $path = dirname(__DIR__, 4)
                .'/generated/big-five-authority-v2/big5-authority-v2-release-gate-37/draft-import-package.json';
            $package = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $wanted = [
                PersonalityPublicContentAsset::ENTITY_DOMAIN.':openness' => true,
                PersonalityPublicContentAsset::ENTITY_FACET_DETAIL.':imagination' => true,
            ];
            $writer = new BigFiveAuthorityV2DraftImportWriter;
            $method = (new \ReflectionClass($writer))->getMethod('descriptor');
            $snapshots = [];
            foreach ($package['assets'] as $entry) {
                if (($entry['authority_surface'] ?? null) !== 'CMS personality_public_content_assets') {
                    continue;
                }
                $descriptor = $method->invoke($writer, $entry);
                $attributes = $descriptor['attributes'];
                if (($attributes['locale'] ?? null) !== 'en') {
                    continue;
                }
                $identity = $attributes['entity_type'].':'.$attributes['entity_key'];
                if (isset($wanted[$identity])) {
                    $snapshots[$identity] = $attributes;
                }
            }
            unset($package);
            self::$historicalSnapshots = $snapshots;
        }

        return self::$historicalSnapshots[$entityType.':'.$entityKey] ?? null;
    }

    private function fingerprint(): string
    {
        return hash('sha256', json_encode([
            DB::table('personality_public_content_assets')->orderBy('id')->get(),
            DB::table('personality_public_content_asset_revisions')->orderBy('id')->get(),
        ], JSON_THROW_ON_ERROR));
    }
}
