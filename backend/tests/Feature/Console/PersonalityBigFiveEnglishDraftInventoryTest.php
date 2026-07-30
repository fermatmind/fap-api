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

        $result = $this->app->make(BigFiveEnglishDraftInventory::class)->inspect();
        $row = collect($result['rows'])->firstWhere('logical_identity', 'domain:openness');
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);

        $this->assertIsArray($row);
        $this->assertTrue($row['private_result_leakage']);
        $this->assertSame('prohibited_content', $row['recommended_disposition']);
        $this->assertStringNotContainsString('must-never-appear', $encoded);
        $this->assertSame(
            BigFiveEnglishDraftInventory::DISPOSITIONS,
            array_values(array_unique([...BigFiveEnglishDraftInventory::DISPOSITIONS])),
        );
    }

    private function fingerprint(): string
    {
        return hash('sha256', json_encode([
            DB::table('personality_public_content_assets')->orderBy('id')->get(),
            DB::table('personality_public_content_asset_revisions')->orderBy('id')->get(),
        ], JSON_THROW_ON_ERROR));
    }
}
