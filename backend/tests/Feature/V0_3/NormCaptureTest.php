<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\BigFiveNormObservation;
use App\Services\BigFive\Norms\BigFiveNormObservationCaptureWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class NormCaptureTest extends TestCase
{
    use RefreshDatabase;

    public function test_capture_writer_has_no_public_route_exposure_and_defaults_off(): void
    {
        $actions = [];
        foreach (Route::getRoutes() as $route) {
            $actions[] = (string) ($route->getActionName() ?? '');
        }

        $this->assertNotContains(BigFiveNormObservationCaptureWriter::class, $actions);

        $result = (new BigFiveNormObservationCaptureWriter)->capture([
            'raw_domain_scores' => ['O' => 70],
            'raw_facet_scores' => ['O1' => 14],
        ], [
            'operation_scope' => 'internal_only',
            'observation_idempotency_key' => 'feature-default-off',
            'content_version' => 'big5.result_page_v2.content.v0.1',
            'score_version' => 'big5.scoring.v1',
            'norm_eligibility_status' => 'eligible',
            'norm_excluded' => false,
            'quality_level' => 'A',
        ]);

        $this->assertFalse($result->captured);
        $this->assertSame('capture_default_off', $result->reason);
        $this->assertSame(0, BigFiveNormObservation::query()->count());
    }
}
