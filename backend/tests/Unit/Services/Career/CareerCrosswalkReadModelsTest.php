<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Models\EditorialPatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerCrosswalkReadModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_queue_read_model_exposes_enriched_fields_for_high_risk_modes(): void
    {
        $localHeavy = CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'local-heavy-role',
            'crosswalk_mode' => 'local_heavy_interpretation',
        ]);
        $familyProxy = CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'family-proxy-role',
            'crosswalk_mode' => 'family_proxy',
        ]);
        CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'unmapped-role',
            'crosswalk_mode' => 'unmapped',
        ]);

        $familyProxy['editorialPatch']->forceFill([
            'status' => 'approved',
            'patch_version' => 'v1',
            'notes' => [
                'target_kind' => 'family',
                'target_slug' => $familyProxy['family']->canonical_slug,
                'crosswalk_mode_override' => 'trust_inheritance',
            ],
        ])->save();

        $payload = app(\App\Domain\Career\Operations\CareerCrosswalkReviewQueueReadModelService::class)
            ->list(['sort' => 'risk']);

        $this->assertSame('career_crosswalk_review_queue_read_model', $payload['queue_kind']);
        $this->assertGreaterThanOrEqual(3, data_get($payload, 'counts.total'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'counts.local_heavy_interpretation'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'counts.family_proxy'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'counts.unmapped'));

        $items = collect($payload['items'] ?? [])->keyBy('subject_slug');
        $localHeavyItem = $items->get('local-heavy-role');
        $this->assertIsArray($localHeavyItem);
        $this->assertSame('local_heavy_interpretation', $localHeavyItem['current_crosswalk_mode']);
        $this->assertTrue((bool) $localHeavyItem['requires_editorial_patch']);
        $this->assertContains('local_heavy_requires_editorial_patch', $localHeavyItem['queue_reason']);

        $familyItem = $items->get('family-proxy-role');
        $this->assertIsArray($familyItem);
        $this->assertSame('family_proxy', $familyItem['current_crosswalk_mode']);
        $this->assertTrue((bool) $familyItem['has_approved_patch']);
        $this->assertSame('approved', $familyItem['latest_patch_status']);
        $this->assertSame('family', $familyItem['candidate_target_kind']);

        $unmappedItem = $items->get('unmapped-role');
        $this->assertIsArray($unmappedItem);
        $this->assertContains('unmapped_requires_editorial_patch', $unmappedItem['queue_reason']);
    }

    public function test_patch_history_read_model_returns_version_chain_and_latest_patch(): void
    {
        $fixture = CareerFoundationFixture::seedMinimalChain();
        $slug = (string) $fixture['occupation']->canonical_slug;

        EditorialPatch::query()->create([
            'id' => (string) Str::uuid(),
            'occupation_id' => (string) $fixture['occupation']->id,
            'required' => true,
            'status' => 'rejected',
            'patch_version' => 'v1',
            'notes' => [
                'target_kind' => 'occupation',
                'target_slug' => $slug,
                'crosswalk_mode_override' => 'exact',
            ],
        ]);

        EditorialPatch::query()->create([
            'id' => (string) Str::uuid(),
            'occupation_id' => (string) $fixture['occupation']->id,
            'required' => true,
            'status' => 'approved',
            'patch_version' => 'v2',
            'notes' => [
                'target_kind' => 'occupation',
                'target_slug' => $slug,
                'crosswalk_mode_override' => 'trust_inheritance',
            ],
        ]);

        $payload = app(\App\Domain\Career\Operations\CareerEditorialPatchHistoryReadModelService::class)
            ->forSubject($slug);

        $this->assertSame('career_editorial_patch_history', $payload['history_kind']);
        $this->assertSame(3, $payload['count']); // includes fixture patch row
        $this->assertIsArray($payload['latest_patch']);
        $this->assertSame('approved', data_get($payload, 'latest_patch.patch_status'));
        $this->assertSame('v2', data_get($payload, 'latest_patch.patch_version'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'status_counts.rejected'));
    }

    public function test_override_read_model_reflects_approved_patch_resolution(): void
    {
        $fixture = CareerFoundationFixture::seedHighTrustCompleteChain([
            'slug' => 'override-read-role',
            'crosswalk_mode' => 'local_heavy_interpretation',
        ]);

        $fixture['editorialPatch']->forceFill([
            'status' => 'approved',
            'patch_version' => 'v1',
            'notes' => [
                'target_kind' => 'occupation',
                'target_slug' => 'override-read-role',
                'crosswalk_mode_override' => 'exact',
            ],
        ])->save();

        $payload = app(\App\Domain\Career\Operations\CareerCrosswalkOverrideReadModelService::class)
            ->forSubject('override-read-role');

        $this->assertIsArray($payload);
        $this->assertSame('career_crosswalk_override_read_model', $payload['override_kind']);
        $this->assertTrue((bool) $payload['override_applied']);
        $this->assertSame('local_heavy_interpretation', $payload['original_crosswalk_mode']);
        $this->assertSame('exact', $payload['resolved_crosswalk_mode']);
    }
}
