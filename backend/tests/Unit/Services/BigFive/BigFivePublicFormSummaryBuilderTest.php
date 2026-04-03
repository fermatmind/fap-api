<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\BigFive\BigFivePublicFormSummaryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BigFivePublicFormSummaryBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_big5_form_summary_from_meta_form_code(): void
    {
        $attempt = new Attempt([
            'scale_code' => 'BIG5_OCEAN',
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1-form-90',
            'question_count' => 90,
            'answers_summary_json' => [
                'meta' => [
                    'form_code' => 'big5_90',
                ],
            ],
        ]);

        $summary = app(BigFivePublicFormSummaryBuilder::class)->summarizeForAttempt($attempt, null, 'zh-CN');

        $this->assertIsArray($summary);
        $this->assertSame('big5_90', $summary['form_code']);
        $this->assertSame('90题标准版', $summary['label']);
        $this->assertSame('90题', $summary['short_label']);
        $this->assertSame(90, $summary['question_count']);
        $this->assertSame(11, $summary['estimated_minutes']);
        $this->assertSame('BIG5_OCEAN', $summary['scale_code']);
    }

    public function test_it_falls_back_to_dir_version_mapping_for_legacy_attempts(): void
    {
        $attempt = new Attempt([
            'scale_code' => 'BIG5_OCEAN',
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'question_count' => 120,
            'answers_summary_json' => ['stage' => 'seed'],
        ]);

        $summary = app(BigFivePublicFormSummaryBuilder::class)->summarizeForAttempt($attempt, null, 'en-US');

        $this->assertIsArray($summary);
        $this->assertSame('big5_120', $summary['form_code']);
        $this->assertSame('120-question full version', $summary['label']);
        $this->assertSame('120 questions', $summary['short_label']);
        $this->assertSame(120, $summary['question_count']);
        $this->assertSame('BIG5_OCEAN', $summary['scale_code']);
    }

    public function test_it_falls_back_to_question_count_when_dir_version_missing(): void
    {
        $attempt = new Attempt([
            'scale_code' => 'BIG5_OCEAN',
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => '',
            'question_count' => 90,
            'answers_summary_json' => ['stage' => 'seed'],
        ]);

        $summary = app(BigFivePublicFormSummaryBuilder::class)->summarizeForAttempt($attempt, null, 'zh-CN');

        $this->assertIsArray($summary);
        $this->assertSame('big5_90', $summary['form_code']);
        $this->assertSame(90, $summary['question_count']);
    }

    public function test_it_returns_null_when_form_cannot_be_safely_inferred(): void
    {
        $attempt = new Attempt([
            'scale_code' => 'BIG5_OCEAN',
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => '',
            'question_count' => 0,
            'answers_summary_json' => ['stage' => 'seed'],
        ]);

        $result = new Result([
            'scale_code' => 'BIG5_OCEAN',
            'result_json' => ['summary' => 'legacy'],
            'dir_version' => '',
        ]);

        $summary = app(BigFivePublicFormSummaryBuilder::class)->summarizeForAttempt($attempt, $result, 'zh-CN');

        $this->assertNull($summary);
    }
}
