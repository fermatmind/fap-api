<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Services\Analytics\QuestionAnalyticsDailyBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\SeedsQuestionAnalyticsScenario;
use Tests\TestCase;

final class QuestionAnalyticsDailyBuilderTest extends TestCase
{
    use RefreshDatabase;
    use SeedsQuestionAnalyticsScenario;

    public function test_builder_aggregates_big5_only_question_option_and_progress_rows(): void
    {
        $scenario = $this->seedQuestionAnalyticsScenario(801);

        $payload = app(QuestionAnalyticsDailyBuilder::class)->build(
            new \DateTimeImmutable($scenario['from']),
            new \DateTimeImmutable($scenario['to']),
            [801],
        );

        $this->assertSame(['BIG5_OCEAN'], $payload['authoritative_scales']);
        $this->assertSame(240, (int) ($payload['source_answer_rows'] ?? 0));
        $this->assertSame(4, (int) ($payload['source_attempts'] ?? 0));

        $optionRows = collect($payload['option_rows']);
        $progressRows = collect($payload['progress_rows']);

        $this->assertSame(240, $optionRows->count());
        $this->assertSame(245, $progressRows->count());
        $this->assertSame(['BIG5_OCEAN'], $optionRows->pluck('scale_code')->unique()->values()->all());
        $this->assertFalse($optionRows->contains(static fn (array $row): bool => str_starts_with((string) $row['question_id'], 'CLIN')));
        $this->assertFalse($optionRows->contains(static fn (array $row): bool => str_starts_with((string) $row['question_id'], 'SDS')));

        $optionRowQ1En = $optionRows->first(static fn (array $row): bool => (string) $row['question_id'] === '1' && (string) $row['locale'] === 'en');
        $this->assertIsArray($optionRowQ1En);
        $this->assertSame(1, (int) ($optionRowQ1En['question_order'] ?? 0));
        $this->assertSame('1', (string) ($optionRowQ1En['option_key'] ?? ''));
        $this->assertSame(1, (int) ($optionRowQ1En['answered_rows_count'] ?? 0));
        $this->assertSame(1, (int) ($optionRowQ1En['distinct_attempts_answered'] ?? 0));

        $optionRowQ120Zh = $optionRows->first(static fn (array $row): bool => (string) $row['question_id'] === '120' && (string) $row['locale'] === 'zh-CN');
        $this->assertIsArray($optionRowQ120Zh);
        $this->assertSame(120, (int) ($optionRowQ120Zh['question_order'] ?? 0));

        $progressByQuestion = $this->summarizeProgressByQuestion($progressRows);

        $this->assertSame([
            'reached' => 4,
            'answered' => 4,
            'completed' => 2,
            'dropoff' => 0,
        ], $progressByQuestion['1']);
        $this->assertSame([
            'reached' => 4,
            'answered' => 3,
            'completed' => 2,
            'dropoff' => 1,
        ], $progressByQuestion['2']);
        $this->assertSame([
            'reached' => 3,
            'answered' => 2,
            'completed' => 2,
            'dropoff' => 1,
        ], $progressByQuestion['3']);
        $this->assertSame([
            'reached' => 2,
            'answered' => 2,
            'completed' => 2,
            'dropoff' => 0,
        ], $progressByQuestion['120']);

        $this->assertSame(
            ['en', 'fr-FR', 'zh-CN'],
            $progressRows->pluck('locale')->unique()->sort()->values()->all()
        );
        $this->assertTrue($progressRows->contains(static fn (array $row): bool => (string) $row['content_package_version'] === 'content_2026_02'));
    }

    /**
     * @return array<string,array{reached:int,answered:int,completed:int,dropoff:int}>
     */
    private function summarizeProgressByQuestion(Collection $rows): array
    {
        return $rows
            ->groupBy('question_id')
            ->map(static function (Collection $group): array {
                return [
                    'reached' => (int) $group->sum('reached_attempts_count'),
                    'answered' => (int) $group->sum('answered_attempts_count'),
                    'completed' => (int) $group->sum('completed_attempts_count'),
                    'dropoff' => (int) $group->sum('dropoff_attempts_count'),
                ];
            })
            ->all();
    }
}
