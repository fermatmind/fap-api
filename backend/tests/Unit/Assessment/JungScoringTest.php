<?php

declare(strict_types=1);

namespace Tests\Unit\Assessment;

use App\Services\Assessment\Drivers\JungLikertDriver;
use App\Services\Assessment\Jung\ArchetypeScorer;
use App\Services\Assessment\Jung\CognitiveFunctionScorer;
use App\Services\Assessment\Jung\JungBank;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JungScoringTest extends TestCase
{
    public function test_archetype_scores_are_independent_and_preserve_exact_and_near_ties(): void
    {
        $answers = $this->answers(JungBank::ARCHETYPES, ['hero' => '5', 'sage' => '5', 'explorer' => '5'], '1');
        $answers[2]['code'] = '4';
        $result = (new ArchetypeScorer)->score(array_reverse($answers));
        self::assertSame(['hero', 'sage'], $result['primary']);
        self::assertSame([], $result['secondary']);
        self::assertSame(['hero', 'sage', 'explorer'], $result['near_leaders']);
        self::assertSame(93.75, $result['scores']['explorer']);
        self::assertSame(0.0, $result['scores']['creator']);
    }

    public function test_archetype_secondary_group_and_flat_profile(): void
    {
        $scorer = new ArchetypeScorer;
        $result = $scorer->score($this->answers(JungBank::ARCHETYPES, ['hero' => '5', 'sage' => '4', 'explorer' => '4']));
        self::assertSame(['hero'], $result['primary']);
        self::assertSame(['sage', 'explorer'], $result['secondary']);
        self::assertSame(75.0, $result['scores']['sage']);

        $flat = $scorer->score($this->answers(JungBank::ARCHETYPES, []));
        self::assertSame([], $flat['primary']);
        self::assertSame('flat', $flat['pattern']);
    }

    public function test_cognitive_function_axis_and_stack_golden_vector(): void
    {
        $answers = $this->answers(JungBank::FUNCTIONS, ['Ni' => '5', 'Te' => '4', 'Fi' => '2', 'Se' => '1']);
        $result = (new CognitiveFunctionScorer)->score($answers);
        self::assertSame(100.0, $result['scores']['Ni']);
        self::assertSame(0.0, $result['scores']['Se']);
        self::assertSame(['left' => 'Ni', 'right' => 'Se', 'difference' => 100.0, 'lean' => 'Ni'], $result['axes'][0]);
        self::assertNull($result['axes'][1]['lean']);
        self::assertSame(['type' => 'INTJ', 'stack' => ['Ni', 'Te', 'Fi', 'Se'], 'fit' => 73.75], $result['candidates'][0]);
        self::assertGreaterThanOrEqual(3, count($result['candidates']));
    }

    public function test_cognitive_flat_and_single_axis_profiles_do_not_assign_certainty(): void
    {
        $scorer = new CognitiveFunctionScorer;
        $flat = $scorer->score($this->answers(JungBank::FUNCTIONS, []));
        self::assertSame([], $flat['candidates']);
        self::assertSame('insufficient', $flat['stability']);
        $ambiguous = $scorer->score($this->answers(JungBank::FUNCTIONS, ['Ni' => '5', 'Se' => '1']));
        self::assertSame('unstable', $ambiguous['stability']);
        self::assertEquals($ambiguous['candidates'][0]['fit'], $ambiguous['candidates'][1]['fit']);
    }

    public function test_driver_binds_scores_to_the_attempt_bank_and_rejects_stale_hash(): void
    {
        $bank = new JungBank(JungBank::FUNCTIONS);
        $context = [
            'scale_code' => $bank->scale,
            'pack_id' => $bank->scale.'.v1',
            'dir_version' => 'v1',
            'form_code' => $bank->data['formCode'],
            'scoring_spec_version' => $bank->data['scoringVersion'],
            'content_manifest_hash' => $bank->hash,
        ];
        $answers = $this->answers($bank->scale, ['Ni' => '5']);
        $driver = new JungLikertDriver;
        $result = $driver->score($answers, [], $context);
        self::assertSame(100.0, $result->axisScoresJson['jung']['scores']['Ni']);
        self::assertNull($result->typeCode);
        self::assertSame($bank->hash, $result->normedJson['version_snapshot']['content_manifest_hash']);

        $context['content_manifest_hash'] = str_repeat('0', 64);
        $this->expectException(InvalidArgumentException::class);
        $driver->score($answers, [], $context);
    }

    public static function invalidResponses(): iterable
    {
        foreach ([JungBank::ARCHETYPES, JungBank::FUNCTIONS] as $scale) {
            foreach (['missing', 'duplicate', 'foreign', 'invalid'] as $kind) {
                yield $scale.' '.$kind => [$scale, $kind];
            }
        }
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_responses_fail_closed(string $scale, string $kind): void
    {
        $answers = $this->answers($scale, []);
        $last = count($answers) - 1;
        match ($kind) {
            'missing' => array_pop($answers),
            'duplicate' => $answers[$last] = $answers[0],
            'foreign' => $answers[$last]['question_id'] = 'FOREIGN_ID',
            'invalid' => $answers[$last]['code'] = '6',
        };

        $this->expectException(InvalidArgumentException::class);
        (new JungBank($scale))->totals($answers);
    }

    private function answers(string $scale, array $codes, string $default = '3'): array
    {
        return array_map(
            fn (array $item): array => [
                'question_id' => $item['id'],
                'code' => $codes[$item['dimension']] ?? $default,
            ],
            (new JungBank($scale))->data['items']
        );
    }
}
