<?php

namespace Tests\Feature\V0_3;

use App\Support\StableBucket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExperimentStickyAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_sticky_assignment_via_boot(): void
    {
        $this->artisan('migrate', ['--force' => true]);

        $orgId = 0;
        [$anonA, $anonB, $expectedA, $expectedB] = $this->findTwoAnonIdsWithDifferentVariants($orgId);

        $respA = $this->withHeaders([
            'X-Org-Id' => (string) $orgId,
            'X-Anon-Id' => $anonA,
        ])->getJson('/api/v0.3/boot');

        $respA->assertStatus(200)->assertJson(['ok' => true]);
        $variantA = $respA->json('experiments.PR23_STICKY_BUCKET');

        $respB = $this->withHeaders([
            'X-Org-Id' => (string) $orgId,
            'X-Anon-Id' => $anonB,
        ])->getJson('/api/v0.3/boot');

        $respB->assertStatus(200)->assertJson(['ok' => true]);
        $variantB = $respB->json('experiments.PR23_STICKY_BUCKET');

        $respA2 = $this->withHeaders([
            'X-Org-Id' => (string) $orgId,
            'X-Anon-Id' => $anonA,
        ])->getJson('/api/v0.3/boot');

        $respA2->assertStatus(200)->assertJson(['ok' => true]);
        $variantA2 = $respA2->json('experiments.PR23_STICKY_BUCKET');

        $this->assertSame($expectedA, $variantA);
        $this->assertSame($expectedB, $variantB);
        $this->assertNotSame($variantA, $variantB);
        $this->assertSame($variantA, $variantA2);

        $rows = DB::table('experiment_assignments')
            ->where('org_id', $orgId)
            ->where('experiment_key', 'PR23_STICKY_BUCKET')
            ->get();

        $this->assertCount(2, $rows);
        $expectedAnons = collect([$anonA, $anonB])->sort()->values()->all();
        $this->assertSame($expectedAnons, $rows->pluck('anon_id')->sort()->values()->all());
    }

    private function findTwoAnonIdsWithDifferentVariants(int $orgId): array
    {
        $base = 'pr23_anon_';
        $first = null;
        $firstVariant = null;

        for ($i = 0; $i < 200; $i++) {
            $anonId = $base . $i;
            $variant = $this->variantForAnon($anonId, $orgId);
            if ($first === null) {
                $first = $anonId;
                $firstVariant = $variant;
                continue;
            }
            if ($variant !== $firstVariant) {
                return [$first, $anonId, $firstVariant, $variant];
            }
        }

        return [$base . '0', $base . '1', 'A', 'B'];
    }

    private function variantForAnon(string $anonId, int $orgId): string
    {
        $salt = (string) config('fap_experiments.salt', '');
        $subjectKey = 'anon:' . $anonId;
        $bucket = StableBucket::bucket($subjectKey . '|' . $orgId . '|PR23_STICKY_BUCKET|' . $salt, 100);

        return $bucket < 50 ? 'A' : 'B';
    }
}
