<?php

declare(strict_types=1);

namespace App\Services\Assessment\Scorers;

use InvalidArgumentException;
use LogicException;

/**
 * Standalone pilot. The questionnaire and scores do not enter the RIASEC attempt flow.
 */
final class CareerAssessmentScorer
{
    public const MODEL_VERSION = 'career_profile_v1';

    private const FORM_VERSION = 'career_v1';

    private const INTEREST_AXES = ['R', 'I', 'A', 'S', 'E', 'C'];

    private const CONDITIONS = ['night_shifts', 'travel'];

    public function questionnaire(): array
    {
        $path = __DIR__.'/../../../../resources/assessment/career-v1/questions.json';
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (($data['form_version'] ?? null) !== self::FORM_VERSION) {
            throw new LogicException('Unexpected career assessment form version.');
        }

        return $data;
    }

    /**
     * Omitted and null answers mean unknown; neutral is an explicit integer 3.
     * This entry point expects validated item IDs, never a legacy attempt payload.
     */
    public function score(string $formVersion, array $answers): array
    {
        if ($formVersion !== self::FORM_VERSION) {
            throw new InvalidArgumentException('Unsupported career assessment form version.');
        }

        $form = $this->questionnaire();
        $items = array_column($form['items'], null, 'id');
        if (array_diff_key($answers, $items) !== []) {
            throw new InvalidArgumentException('Unknown career assessment item.');
        }

        $groups = [];
        $context = [];

        foreach ($items as $id => $item) {
            $section = $item['section'];
            $axis = $item['axis'];
            $answer = $answers[$id] ?? null;

            if ($section === 'context') {
                if ($answer !== null && (! is_string($answer) || ! isset($item['options'][$answer]))) {
                    throw new InvalidArgumentException('Invalid career context answer: '.$id);
                }
                $context[$axis] = $answer;

                continue;
            }

            if ($answer !== null && (! is_int($answer) || $answer < 1 || $answer > 5)) {
                throw new InvalidArgumentException('Invalid career scale answer: '.$id);
            }

            $groups[$section][$axis][] = $answer !== null && ($item['reverse'] ?? false)
                ? 6 - $answer
                : $answer;
        }

        $scores = [];
        foreach ($groups as $section => $axes) {
            foreach ($axes as $axis => $answersForAxis) {
                $known = array_filter($answersForAxis, static fn (?int $answer): bool => $answer !== null);
                $answered = count($known);
                $minimum = $section === 'interest' ? 6 : 3;
                $scores[$section][$axis] = [
                    'score' => $answered >= $minimum ? round(25 * (array_sum($known) / $answered - 1), 4) : null,
                    'answered' => $answered,
                    'total' => count($answersForAxis),
                ];
            }
        }

        $interest = array_column($scores['interest'], 'score');
        $known = array_filter($interest, static fn (?float $score): bool => $score !== null);
        $range = count($known) === count(self::INTEREST_AXES) ? max($known) - min($known) : null;
        $highest = $range !== null && $range > 0
            ? array_keys(array_filter($scores['interest'], static fn (array $axis): bool => $axis['score'] === max($known)))
            : [];

        return [
            'form_version' => self::FORM_VERSION,
            'model_version' => self::MODEL_VERSION,
            'axes' => $scores,
            'interest_shape' => [
                'state' => $range === null ? 'incomplete' : ($range === 0.0 ? 'flat' : 'differentiated'),
                'range' => $range,
                'highest_axes' => $highest,
            ],
            'context' => $context,
        ];
    }

    /**
     * Compare against one pre-vetted, scope-compatible job profile.
     *
     * Profile keys: interest (six 0–100 salience values), style (0–100 on the
     * same bipolar axes), value (0–100 provision, not importance), requirements
     * (verified explicit bools). Missing evidence is absent or null.
     * No composite is returned; cohort comparison must use like-for-like inputs.
     */
    public function compare(array $result, array $profile): array
    {
        if (($result['model_version'] ?? null) !== self::MODEL_VERSION) {
            throw new InvalidArgumentException('Unsupported career assessment model version.');
        }

        $interest = $this->compareInterest($result, $profile['interest'] ?? []);
        $style = $this->comparePreference($result['axes']['style'], $profile['style'] ?? [], false);
        $value = $this->comparePreference($result['axes']['value'], $profile['value'] ?? [], true);

        $constraints = [];
        foreach (self::CONDITIONS as $field) {
            $answer = $result['context'][$field] ?? null;
            $required = $profile['requirements'][$field] ?? null;
            if ($required !== null && ! is_bool($required)) {
                throw new InvalidArgumentException('Invalid job requirement: '.$field);
            }

            $constraints[$field] = match (true) {
                $answer === null => 'unknown',
                $answer !== 'cannot' => 'not_restricted',
                $required === true => 'conflict',
                $required === false => 'no_conflict',
                default => 'unknown',
            };
        }

        return [
            'interest' => $interest,
            'style' => $style,
            'value' => $value,
            'constraints' => $constraints,
        ];
    }

    private function compareInterest(array $result, array $occupation): ?float
    {
        if ($result['interest_shape']['state'] !== 'differentiated') {
            return null;
        }

        $userTotal = 0;
        $jobTotal = 0;
        $weighted = 0.0;
        foreach (self::INTEREST_AXES as $axis) {
            $job = $this->jobValue($occupation, $axis);
            if ($job === null) {
                return null;
            }
            $user = $result['axes']['interest'][$axis]['score'];
            $weighted += $job * $user;
            $jobTotal += $job;
            $userTotal += $user;
        }

        // Center on the user's six-axis mean: overall liking is not directional fit.
        return $jobTotal > 0 ? round(50 + ($weighted / $jobTotal - $userTotal / 6) / 2, 4) : null;
    }

    private function comparePreference(array $user, array $occupation, bool $isValue): array
    {
        $dimensions = [];
        $total = 0.0;
        $totalWeight = 0.0;
        $complete = true;

        foreach ($user as $axis => $measurement) {
            $person = $measurement['score'];
            $job = $this->jobValue($occupation, $axis);
            $weight = $isValue ? $person : 1;
            $dimensions[$axis] = match (true) {
                $person === null, $job === null => null,
                $isValue && $person === 0.0 => null,
                $isValue => $job,
                default => 100 - abs($person - $job),
            };

            if ($person === null) {
                $complete = false;
            } elseif ($weight > 0) {
                if ($dimensions[$axis] === null) {
                    $complete = false;
                } else {
                    $total += $weight * $dimensions[$axis];
                    $totalWeight += $weight;
                }
            }
        }

        return [
            'score' => $complete && $totalWeight > 0 ? round($total / $totalWeight, 4) : null,
            'dimensions' => $dimensions,
        ];
    }

    private function jobValue(array $fields, string $axis): ?float
    {
        $value = $fields[$axis] ?? null;
        if ($value === null) {
            return null;
        }
        if ((! is_float($value) && ! is_int($value)) || ! is_finite((float) $value) || $value < 0 || $value > 100) {
            throw new InvalidArgumentException('Invalid career profile axis: '.$axis);
        }

        return (float) $value;
    }
}
