<?php

namespace Tests\Feature\V0_3;

use Database\Seeders\Pr21AnswerDemoSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttemptProgressFlowTest extends TestCase
{
    use RefreshDatabase;

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr21AnswerDemoSeeder())->run();
    }

    public function test_progress_resume_flow(): void
    {
        $this->seedScales();

        $start = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'DEMO_ANSWERS',
        ]);
        $start->assertStatus(200);

        $attemptId = (string) $start->json('attempt_id');
        $token = (string) $start->json('resume_token');
        $this->assertNotSame('', $attemptId);
        $this->assertNotSame('', $token);

        $progress1 = $this->putJson("/api/v0.3/attempts/{$attemptId}/progress", [
            'seq' => 1,
            'cursor' => 'page-1',
            'duration_ms' => 1200,
            'answers' => [
                [
                    'question_id' => 'DEMO-SLIDER-1',
                    'question_type' => 'slider',
                    'question_index' => 0,
                    'code' => '3',
                    'answer' => ['value' => 3],
                ],
            ],
        ], [
            'X-Resume-Token' => $token,
        ]);
        $progress1->assertStatus(200);

        $progress2 = $this->putJson("/api/v0.3/attempts/{$attemptId}/progress", [
            'seq' => 2,
            'cursor' => 'page-2',
            'duration_ms' => 2400,
            'answers' => [
                [
                    'question_id' => 'DEMO-SLIDER-1',
                    'question_type' => 'slider',
                    'question_index' => 0,
                    'code' => '4',
                    'answer' => ['value' => 4],
                ],
                [
                    'question_id' => 'DEMO-RANK-1',
                    'question_type' => 'rank_order',
                    'question_index' => 1,
                    'code' => 'A>B>C',
                    'answer' => ['order' => ['A', 'B', 'C']],
                ],
            ],
        ], [
            'X-Resume-Token' => $token,
        ]);
        $progress2->assertStatus(200);

        $get = $this->getJson("/api/v0.3/attempts/{$attemptId}/progress", [
            'X-Resume-Token' => $token,
        ]);
        $get->assertStatus(200);
        $this->assertSame(2, (int) $get->json('answered_count'));

        $answers = $get->json('answers');
        $slider = collect($answers)->firstWhere('question_id', 'DEMO-SLIDER-1');
        $this->assertSame('4', (string) ($slider['code'] ?? ''));
    }
}
