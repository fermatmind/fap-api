<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerExportPublicTrustTaxonomyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_limited_export_bounds_aliases_and_marks_reconciliation_unsuitable(): void
    {
        $familyId = (string) Str::uuid();
        $alphaId = $this->insertOccupation($familyId, 'alpha-career');
        $betaId = $this->insertOccupation($familyId, 'beta-career');
        $this->insertAlias($alphaId, $familyId, 'alpha career', 'alpha-career');
        $this->insertAlias($alphaId, $familyId, 'alpha role', 'alpha-role');
        $this->insertAlias($betaId, $familyId, 'beta career', 'beta-career');
        $this->insertIndexState($alphaId, true);
        $this->insertIndexState($betaId, true);
        $this->insertDisplayAsset($alphaId, 'alpha-career', 'draft-v2', 'draft', '2026-05-18 02:00:00');
        $this->insertDisplayAsset($alphaId, 'alpha-career', 'public-v1', 'ready_for_pilot', '2026-05-18 01:00:00');
        $this->insertDisplayAsset($betaId, 'beta-career', 'public-v1', 'ready_for_pilot', '2026-05-18 01:00:00');

        $output = storage_path('framework/testing/career-taxonomy-limit1.json');
        File::delete($output);

        $exitCode = Artisan::call('career:export-public-trust-taxonomy', [
            '--output' => $output,
            '--limit' => 1,
            '--json' => true,
        ]);
        $commandPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $artifact = json_decode((string) File::get($output), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('limited', $artifact['exportScope']['mode']);
        $this->assertTrue((bool) $artifact['exportScope']['limitedExport']);
        $this->assertFalse((bool) $artifact['exportScope']['suitableForFullCountReconciliation']);
        $this->assertSame('related_to_limited_occupation_rows_only', $artifact['exportScope']['aliasSelectionStrategy']);
        $this->assertSame($artifact['exportScope'], $commandPayload['exportScope']);
        $this->assertSame(1, (int) $artifact['counts']['backendOccupationRows']);
        $this->assertSame(2, (int) $artifact['counts']['backendAliasRows']);
        $this->assertSame(2, (int) $artifact['counts']['aliasOnlyAssets']);
        $this->assertNotContains('beta-career', $artifact['classificationBuckets']['aliasOnlyAssets']);

        $canonicalAlpha = $this->firstItemBySlug($artifact['items'], 'alpha-career');
        $this->assertSame('ready_for_pilot', $canonicalAlpha['routeEvidence']['display_asset_status']);
        $this->assertSame('public-v1', $canonicalAlpha['routeEvidence']['asset_version']);
        $this->assertTrue((bool) $canonicalAlpha['publicRouteAvailable']);
    }

    private function insertOccupation(string $familyId, string $slug): string
    {
        if (! DB::table('occupation_families')->where('id', $familyId)->exists()) {
            DB::table('occupation_families')->insert([
                'id' => $familyId,
                'canonical_slug' => 'engineering',
                'title_en' => 'Engineering',
                'title_zh' => '工程',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $id = (string) Str::uuid();
        DB::table('occupations')->insert([
            'id' => $id,
            'family_id' => $familyId,
            'parent_id' => null,
            'canonical_slug' => $slug,
            'entity_level' => 'occupation',
            'truth_market' => 'US',
            'display_market' => 'global',
            'crosswalk_mode' => 'canonical',
            'canonical_title_en' => Str::title(str_replace('-', ' ', $slug)),
            'canonical_title_zh' => $slug,
            'search_h1_zh' => $slug,
            'trust_inheritance_scope' => json_encode(['status' => 'approved'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertAlias(string $occupationId, string $familyId, string $alias, string $normalized): void
    {
        DB::table('occupation_aliases')->insert([
            'id' => (string) Str::uuid(),
            'occupation_id' => $occupationId,
            'family_id' => $familyId,
            'alias' => $alias,
            'normalized' => $normalized,
            'lang' => 'en',
            'register' => 'alias',
            'intent_scope' => 'career',
            'target_kind' => 'occupation',
            'precision_score' => 0.9,
            'confidence_score' => 0.9,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertIndexState(string $occupationId, bool $eligible): void
    {
        DB::table('index_states')->insert([
            'id' => (string) Str::uuid(),
            'occupation_id' => $occupationId,
            'index_state' => $eligible ? 'indexable' : 'noindex',
            'index_eligible' => $eligible,
            'canonical_path' => '/career/jobs/example',
            'canonical_target' => null,
            'reason_codes' => json_encode(['test_fixture'], JSON_THROW_ON_ERROR),
            'changed_at' => '2026-05-18 00:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertDisplayAsset(string $occupationId, string $slug, string $version, string $status, string $updatedAt): void
    {
        DB::table('career_job_display_assets')->insert([
            'id' => (string) Str::uuid(),
            'occupation_id' => $occupationId,
            'canonical_slug' => $slug,
            'surface_version' => 'display.surface.v1',
            'asset_version' => $version,
            'template_version' => 'v4.2',
            'asset_type' => 'career_job_public_display',
            'asset_role' => 'formal_pilot_master',
            'status' => $status,
            'component_order_json' => json_encode([], JSON_THROW_ON_ERROR),
            'page_payload_json' => json_encode(['faq' => []], JSON_THROW_ON_ERROR),
            'seo_payload_json' => json_encode(['title' => $slug], JSON_THROW_ON_ERROR),
            'sources_json' => json_encode(['sources' => []], JSON_THROW_ON_ERROR),
            'structured_data_json' => json_encode(['@type' => 'BreadcrumbList'], JSON_THROW_ON_ERROR),
            'implementation_contract_json' => json_encode([], JSON_THROW_ON_ERROR),
            'metadata_json' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function firstItemBySlug(array $items, string $slug): array
    {
        foreach ($items as $item) {
            if (($item['slug'] ?? null) === $slug) {
                return $item;
            }
        }

        $this->fail("Item {$slug} not found.");
    }
}
