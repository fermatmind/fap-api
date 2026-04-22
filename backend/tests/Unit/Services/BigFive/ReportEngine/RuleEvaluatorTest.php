<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Contracts\ReportContext;
use App\Services\BigFive\ReportEngine\Rules\RuleEvaluator;
use Tests\TestCase;

final class RuleEvaluatorTest extends TestCase
{
    private RuleEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new RuleEvaluator;
    }

    public function test_it_supports_required_leaf_operators(): void
    {
        $context = $this->context();

        $this->assertTrue($this->evaluator->evaluate(['trait' => 'N', 'op' => '>=', 'value' => 65], $context));
        $this->assertTrue($this->evaluator->evaluate(['trait' => 'E', 'op' => '<=', 'value' => 35], $context));
        $this->assertTrue($this->evaluator->evaluate(['trait' => 'O', 'op' => 'between', 'value' => [50, 65]], $context));
        $this->assertTrue($this->evaluator->evaluate(['op' => 'abs_diff_ge', 'left' => 'N', 'right' => 'E', 'value' => 20], $context));
    }

    public function test_it_supports_all_any_and_abs_expression(): void
    {
        $context = $this->context();

        $this->assertTrue($this->evaluator->evaluate([
            'all' => [
                ['trait' => 'N', 'op' => '>=', 'value' => 65],
                ['any' => [
                    ['trait' => 'E', 'op' => '<=', 'value' => 10],
                    ['expr' => 'abs(N-E) >= 20'],
                ]],
            ],
        ], $context));
    }

    private function context(): ReportContext
    {
        return new ReportContext(
            locale: 'zh-CN',
            scaleCode: 'BIG5_OCEAN',
            formCode: 'big5_90',
            domains: [
                'O' => ['percentile' => 59],
                'E' => ['percentile' => 20],
                'N' => ['percentile' => 68],
            ],
            facets: [],
        );
    }
}
