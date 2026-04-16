<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Operations\CareerEditorialPatchMutationService;
use App\Models\EditorialPatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerCrosswalkPatchMutationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_patch_and_preserves_version_chain(): void
    {
        $fixture = CareerFoundationFixture::seedMinimalChain();
        $slug = (string) $fixture['occupation']->canonical_slug;

        $service = app(CareerEditorialPatchMutationService::class);
        $created = $service->create([
            'subject_slug' => $slug,
            'target_kind' => 'occupation',
            'target_slug' => $slug,
            'crosswalk_mode_override' => 'exact',
            'review_notes' => 'initial patch',
            'created_by' => 'ops-admin-1',
        ]);

        $this->assertSame('draft', $created['patch_status']);
        $this->assertSame('v1', $created['patch_version']);
        $this->assertDatabaseHas('editorial_patches', [
            'id' => $created['patch_key'],
            'status' => 'draft',
            'occupation_id' => (string) $fixture['occupation']->id,
        ]);
    }

    public function test_it_approves_and_supersedes_previous_approved_patch(): void
    {
        $fixture = CareerFoundationFixture::seedMinimalChain();
        $slug = (string) $fixture['occupation']->canonical_slug;

        $first = EditorialPatch::query()->create([
            'id' => (string) Str::uuid(),
            'occupation_id' => (string) $fixture['occupation']->id,
            'required' => true,
            'status' => 'approved',
            'patch_version' => 'v1',
            'notes' => [
                'target_kind' => 'occupation',
                'target_slug' => $slug,
                'crosswalk_mode_override' => 'exact',
            ],
        ]);

        $service = app(CareerEditorialPatchMutationService::class);
        $created = $service->create([
            'subject_slug' => $slug,
            'target_kind' => 'family',
            'target_slug' => (string) $fixture['family']->canonical_slug,
            'crosswalk_mode_override' => 'trust_inheritance',
            'review_notes' => 'new approved patch',
            'created_by' => 'ops-admin-2',
        ]);

        $approved = $service->approve($created['patch_key'], 'approve patch', 'ops-reviewer-1');
        $this->assertSame('approved', $approved['patch_status']);

        $this->assertDatabaseHas('editorial_patches', [
            'id' => (string) $first->id,
            'status' => 'superseded',
        ]);
    }

    public function test_it_rejects_patch(): void
    {
        $fixture = CareerFoundationFixture::seedMinimalChain();
        $slug = (string) $fixture['occupation']->canonical_slug;
        $service = app(CareerEditorialPatchMutationService::class);

        $created = $service->create([
            'subject_slug' => $slug,
            'target_kind' => 'occupation',
            'target_slug' => $slug,
            'crosswalk_mode_override' => 'exact',
            'review_notes' => 'reject-me',
            'created_by' => 'ops-admin-3',
        ]);

        $rejected = $service->reject($created['patch_key'], 'not enough evidence', 'ops-reviewer-2');
        $this->assertSame('rejected', $rejected['patch_status']);
        $this->assertDatabaseHas('editorial_patches', [
            'id' => $created['patch_key'],
            'status' => 'rejected',
        ]);
    }
}
