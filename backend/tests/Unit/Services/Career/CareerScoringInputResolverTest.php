<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Services\Career\CareerScoringInputResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Career\CareerFoundationFixture;
use Tests\TestCase;

final class CareerScoringInputResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_tied_authority_times_use_id_order_without_overriding_time_or_explicit_refs(): void
    {
        $this->freezeSecond();
        $chain = CareerFoundationFixture::seedHighTrustCompleteChain();
        $refs = [];
        foreach (['truthMetric' => 'truth_metric_id', 'trustManifest' => 'trust_manifest_id', 'indexState' => 'index_state_id'] as $key => $field) {
            $original = $chain[$key];
            $replacement = $original->replicate();
            $replacement->id = 'ffffffff-ffff-7000-8000-000000000001';
            $replacement->created_at = $original->created_at;
            if (array_key_exists('updated_at', $original->getAttributes())) {
                $replacement->updated_at = $original->updated_at;
            }
            $replacement->row_fingerprint = hash('sha256', 'tie-order-'.$field);
            if ($key === 'indexState') {
                $replacement->index_eligible = false;
            } elseif ($key === 'trustManifest') {
                $replacement->reviewer_status = 'pending';
            }
            $replacement->save();
            $refs[$field] = $original->id;
        }

        $resolver = app(CareerScoringInputResolver::class);
        $latest = $resolver->resolve($chain['childProjection'], $chain['occupation']);
        foreach (array_keys($refs) as $field) {
            self::assertSame('ffffffff-ffff-7000-8000-000000000001', $latest[$field]);
        }
        self::assertFalse($latest['index_eligible']);
        self::assertSame('pending', $latest['reviewer_status']);

        $pinned = $resolver->resolve($chain['childProjection'], $chain['occupation'], $refs);
        foreach ($refs as $field => $id) {
            self::assertSame($id, $pinned[$field]);
        }

        foreach (['truthMetric' => 'reviewed_at', 'trustManifest' => 'reviewed_at', 'indexState' => 'changed_at'] as $key => $date) {
            $chain[$key]->update([$date => $chain[$key]->getAttribute($date)->copy()->addSecond()]);
        }
        $later = $resolver->resolve($chain['childProjection'], $chain['occupation']);
        foreach ($refs as $field => $id) {
            self::assertSame($id, $later[$field]);
        }
    }
}
