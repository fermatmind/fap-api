<?php

namespace App\Services\Psychometrics;

class QualityChecker
{
    public function check(array $answers, array $scoringSpec, array $qualitySpec): array
    {
        $checks = is_array($qualitySpec['checks'] ?? null) ? $qualitySpec['checks'] : [];
        $gradeRules = is_array($qualitySpec['grade_rules'] ?? null) ? $qualitySpec['grade_rules'] : [];

        $answerMap = $this->buildAnswerMap($answers);
        $results = [];

        foreach ($checks as $check) {
            if (!is_array($check)) {
                continue;
            }
            $results[] = $this->evaluateCheck($check, $answers, $answerMap, $scoringSpec);
        }

        $grade = $this->grade($results, $gradeRules);

        return [
            'grade' => $grade,
            'checks' => $results,
        ];
    }

    private function buildAnswerMap(array $answers): array
    {
        $map = [];
        foreach ($answers as $a) {
            if (!is_array($a)) {
                continue;
            }
            $qid = (string) ($a['question_id'] ?? '');
            $code = strtoupper((string) ($a['code'] ?? ''));
            if ($qid !== '' && $code !== '') {
                $map[$qid] = $code;
            }
        }
        return $map;
    }

    private function evaluateCheck(array $check, array $answers, array $answerMap, array $scoringSpec): array
    {
        $id = (string) ($check['id'] ?? '');
        $type = (string) ($check['type'] ?? 'unknown');
        $params = is_array($check['params'] ?? null) ? $check['params'] : [];

        return match ($type) {
            'min_answer_count' => $this->checkMinAnswerCount($id, $answers, $params),
            'max_same_option_ratio' => $this->checkMaxSameOptionRatio($id, $answerMap, $params),
            'reverse_pair_mismatch_ratio' => $this->checkReversePairMismatch($id, $answerMap, $scoringSpec, $params),
            default => [
                'id' => $id,
                'type' => $type,
                'passed' => true,
                'score' => 100,
                'value' => null,
                'detail' => 'check type not implemented',
            ],
        };
    }

    private function checkMinAnswerCount(string $id, array $answers, array $params): array
    {
        $min = isset($params['min']) ? (int) $params['min'] : 1;
        $count = count($answers);
        $passed = $count >= $min;

        return [
            'id' => $id,
            'type' => 'min_answer_count',
            'passed' => $passed,
            'score' => $passed ? 100 : 0,
            'value' => $count,
            'threshold' => $min,
        ];
    }

    private function checkMaxSameOptionRatio(string $id, array $answerMap, array $params): array
    {
        $maxRatio = isset($params['max_ratio']) ? (float) $params['max_ratio'] : 0.9;
        $total = count($answerMap);

        if ($total <= 0) {
            return [
                'id' => $id,
                'type' => 'max_same_option_ratio',
                'passed' => true,
                'score' => 100,
                'value' => null,
                'threshold' => $maxRatio,
                'detail' => 'no answers',
            ];
        }

        $counts = array_count_values(array_values($answerMap));
        $max = max($counts);
        $ratio = $max / $total;
        $passed = $ratio <= $maxRatio;
        $score = (int) round(max(0.0, (1 - ($ratio / max($maxRatio, 0.0001))) * 100));

        return [
            'id' => $id,
            'type' => 'max_same_option_ratio',
            'passed' => $passed,
            'score' => $passed ? 100 : $score,
            'value' => round($ratio, 4),
            'threshold' => $maxRatio,
        ];
    }

    private function checkReversePairMismatch(string $id, array $answerMap, array $scoringSpec, array $params): array
    {
        $maxRatio = isset($params['max_ratio']) ? (float) $params['max_ratio'] : 0.5;
        $pairs = is_array($scoringSpec['reverse_pairs'] ?? null) ? $scoringSpec['reverse_pairs'] : [];

        $tested = 0;
        $mismatch = 0;

        foreach ($pairs as $pair) {
            if (!is_array($pair)) {
                continue;
            }

            $a = (string) ($pair['a'] ?? $pair[0] ?? '');
            $b = (string) ($pair['b'] ?? $pair[1] ?? '');

            if ($a === '' || $b === '') {
                continue;
            }

            if (!isset($answerMap[$a]) || !isset($answerMap[$b])) {
                continue;
            }

            $tested += 1;
            $codeA = $answerMap[$a];
            $codeB = $answerMap[$b];

            if (!$this->isReverseMatch($codeA, $codeB)) {
                $mismatch += 1;
            }
        }

        if ($tested === 0) {
            return [
                'id' => $id,
                'type' => 'reverse_pair_mismatch_ratio',
                'passed' => true,
                'score' => 100,
                'value' => null,
                'threshold' => $maxRatio,
                'detail' => 'no reverse pairs',
            ];
        }

        $ratio = $mismatch / $tested;
        $passed = $ratio <= $maxRatio;

        return [
            'id' => $id,
            'type' => 'reverse_pair_mismatch_ratio',
            'passed' => $passed,
            'score' => $passed ? 100 : (int) round(max(0.0, (1 - ($ratio / max($maxRatio, 0.0001))) * 100)),
            'value' => round($ratio, 4),
            'threshold' => $maxRatio,
            'tested' => $tested,
            'mismatch' => $mismatch,
        ];
    }

    private function isReverseMatch(string $codeA, string $codeB): bool
    {
        $map = [
            'A' => 'E',
            'B' => 'D',
            'C' => 'C',
            'D' => 'B',
            'E' => 'A',
        ];

        $codeA = strtoupper($codeA);
        $codeB = strtoupper($codeB);

        return isset($map[$codeA]) && $map[$codeA] === $codeB;
    }

    private function grade(array $checks, array $rules): string
    {
        $default = (string) ($rules['default'] ?? 'A');
        $grade = $default;
        $downgrade = is_array($rules['downgrade'] ?? null) ? $rules['downgrade'] : [];

        $failedIds = [];
        foreach ($checks as $check) {
            if (!is_array($check)) {
                continue;
            }
            if (($check['passed'] ?? true) === false) {
                $failedIds[] = (string) ($check['id'] ?? '');
            }
        }

        $failCount = count($failedIds);

        foreach ($downgrade as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $targetGrade = (string) ($rule['grade'] ?? '');
            $cond = is_array($rule['if'] ?? null) ? $rule['if'] : [];

            $hit = false;
            if (isset($cond['any_failed']) && is_array($cond['any_failed'])) {
                foreach ($cond['any_failed'] as $id) {
                    if (in_array((string) $id, $failedIds, true)) {
                        $hit = true;
                        break;
                    }
                }
            }

            if (isset($cond['fail_count_gte'])) {
                $n = (int) $cond['fail_count_gte'];
                if ($failCount >= $n) {
                    $hit = true;
                }
            }

            if ($hit && $targetGrade !== '') {
                $grade = $this->worseGrade($grade, $targetGrade);
            }
        }

        return $grade;
    }

    private function worseGrade(string $a, string $b): string
    {
        $order = [
            'A' => 1,
            'B' => 2,
            'C' => 3,
            'D' => 4,
        ];

        $ra = $order[strtoupper($a)] ?? 1;
        $rb = $order[strtoupper($b)] ?? 1;

        return ($rb > $ra) ? strtoupper($b) : strtoupper($a);
    }
}
