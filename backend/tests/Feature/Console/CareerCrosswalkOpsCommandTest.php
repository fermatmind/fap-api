<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\EditorialPatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerCrosswalkOpsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_mode_outputs_internal_queue_validation_and_override_summary(): void
    {
        $fixture = CareerFoundationFixture::seedMinimalChain();

        $fixture['editorialPatch']->forceFill([
            'status' => 'approved',
            'patch_version' => 'v1',
            'notes' => [
                'target_kind' => 'occupation',
                'target_slug' => $fixture['occupation']->canonical_slug,
                'crosswalk_mode_override' => 'exact',
            ],
        ])->save();

        $exitCode = Artisan::call('career:crosswalk-ops', [
            '--mode' => 'snapshot',
            '--json' => true,
        ]);
        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertIsArray($payload);
        $this->assertSame('snapshot', $payload['mode'] ?? null);
        $this->assertSame('ok', $payload['status'] ?? null);
        $this->assertArrayHasKey('patch_authority', $payload);
        $this->assertArrayHasKey('patch_validation', $payload);
        $this->assertArrayHasKey('review_queue', $payload);
        $this->assertArrayHasKey('resolved_crosswalk', $payload);
        $this->assertTrue((bool) data_get($payload, 'patch_validation.passed'));
    }

    public function test_validate_patches_mode_returns_failure_when_patch_contract_is_invalid(): void
    {
        $fixture = CareerFoundationFixture::seedMinimalChain();

        $subjectSlug = (string) $fixture['occupation']->canonical_slug;
        $occupationId = (string) $fixture['occupation']->id;

        EditorialPatch::query()->create([
            'occupation_id' => $occupationId,
            'required' => true,
            'status' => 'approved',
            'patch_version' => 'v1',
            'notes' => [
                'target_kind' => 'occupation',
                'target_slug' => $subjectSlug,
                'crosswalk_mode_override' => 'exact',
            ],
        ]);

        EditorialPatch::query()->create([
            'occupation_id' => $occupationId,
            'required' => true,
            'status' => 'approved',
            'patch_version' => 'v2',
            'notes' => [
                'target_kind' => 'occupation',
                'target_slug' => $subjectSlug,
                'crosswalk_mode_override' => 'exact',
            ],
        ]);

        $exitCode = Artisan::call('career:crosswalk-ops', [
            '--mode' => 'validate-patches',
            '--json' => true,
        ]);
        $payload = json_decode(trim((string) Artisan::output()), true);

        $this->assertSame(1, $exitCode, Artisan::output());
        $this->assertIsArray($payload);
        $this->assertSame('failed', $payload['status'] ?? null);
        $this->assertFalse((bool) data_get($payload, 'patch_validation.passed'));
    }
}
