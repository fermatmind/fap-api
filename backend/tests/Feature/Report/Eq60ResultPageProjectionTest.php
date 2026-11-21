<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Services\Content\Eq60PackLoader;
use App\Services\Eq\Eq60ResultPageProjection;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class Eq60ResultPageProjectionTest extends TestCase
{
    use RefreshDatabase;

    private function report(string $id): array
    {
        $dimensions = [];
        foreach (['SA' => 85, 'ER' => 93, 'EM' => 108, 'RM' => 116] as $code => $standard) {
            // Deliberately not the preview bootstrap equation: immutable scores win.
            $dimensions[$code] = ['raw_score' => 50, 'standard_score' => $standard];
        }

        return ['schema_version' => 'eq_60.report.v2', 'scale_code' => 'EQ_60', 'eq_report_mode' => 'self_report',
            'interpretation' => ['core_formulation_id' => $id], 'scores' => ['dimensions' => $dimensions]];
    }

    public function test_all_nine_profiles_share_trilingual_ui_contract_and_preserve_scores(): void
    {
        $library = app(Eq60PackLoader::class)->loadResultPageLibrary()['library'];
        foreach (array_keys($library['profiles']) as $id) {
            $report = $this->report($id);
            $before = serialize($report);
            $page = app(Eq60ResultPageProjection::class)->project($report);
            $this->assertSame($id, $page['result_id']);
            $this->assertNull($page['quality']);
            $this->assertSame($before, serialize($report));
            foreach (['zh-CN', 'en', 'zh-TW'] as $locale) {
                $copy = $page['copy'][$locale];
                $this->assertCount(8, $copy['careers']);
                $this->assertCount(7, $copy['plan']);
                $this->assertCount(10, $copy['sections']);
                $this->assertSame([85, 93, 108, 116], array_column($copy['dimensions'], 'score'));
                $this->assertSame(['developing', 'competent', 'proficient', 'exceptional'], array_column($copy['dimensions'], 'band_code'));
            }
            $this->assertSame(0, preg_match('/[\x{3400}-\x{9fff}]/u', json_encode($page['copy']['en'], JSON_UNESCAPED_UNICODE)));
            $this->assertStringNotContainsString('raw_scores', json_encode($page));
        }
    }

    public function test_quality_branch_never_exposes_normal_profile_or_dimension_claims(): void
    {
        $report = $this->report('low_confidence_result');
        unset($report['scores']);
        $page = app(Eq60ResultPageProjection::class)->project($report);
        foreach (['zh-CN', 'en', 'zh-TW'] as $locale) {
            $this->assertCount(3, $page['quality'][$locale]['steps']);
            $this->assertArrayNotHasKey('dimensions', $page['copy'][$locale]);
            $this->assertArrayNotHasKey('careers', $page['copy'][$locale]);
        }
    }

    public function test_unknown_classification_fails_instead_of_using_sample_profile(): void
    {
        $this->expectException(DomainException::class);
        app(Eq60ResultPageProjection::class)->project($this->report('unknown'));
    }

    public function test_fixed_public_sample_cannot_read_an_attempt_or_accept_scores(): void
    {
        $first = $this->getJson('/api/v0.5/eq-assessment/sample')->assertOk()->assertJsonPath('sample', true);
        $second = $this->getJson('/api/v0.5/eq-assessment/sample?attempt_id=private&score=145&result=balanced_integrated')->assertOk();
        $this->assertSame($first->json(), $second->json());
        $first->assertJsonPath('eq60_result_page_v1.copy.en.dimensions.0.score', 109);
    }
}
