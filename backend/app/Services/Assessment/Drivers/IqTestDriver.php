<?php

namespace App\Services\Assessment\Drivers;

use App\Services\Assessment\ScoreResult;

class IqTestDriver implements DriverInterface
{
    public function score(array $answers, array $spec, array $ctx): ScoreResult
    {
        $durationMs = max(0, (int) ($ctx['duration_ms'] ?? 0));
        $normalizedItems = $this->normalizeAnswers($answers);
        $quality = $this->buildQuality($durationMs, $normalizedItems, $spec);

        $breakdown = [
            'status' => 'unscored',
            'reason_code' => 'ANSWER_KEY_MISSING',
            'scoring_mode' => (string) ($spec['scoring_mode'] ?? 'pending_answer_key'),
            'duration_ms' => $durationMs,
            'answer_count' => count($normalizedItems),
            'quality' => $quality,
            'items' => $normalizedItems,
        ];

        return new ScoreResult(0.0, 0.0, $breakdown, null, null, [
            'status' => 'unscored',
            'reason_code' => 'ANSWER_KEY_MISSING',
        ]);
    }

    /**
     * @return list<array{question_id:string,code:string}>
     */
    private function normalizeAnswers(array $answers): array
    {
        $normalized = [];

        foreach ($answers as $answer) {
            if (! is_array($answer)) {
                continue;
            }

            $questionId = strtoupper(trim((string) ($answer['question_id'] ?? '')));
            $code = strtoupper(trim((string) ($answer['code'] ?? ($answer['option_code'] ?? ''))));
            if ($questionId === '' || $code === '') {
                continue;
            }

            if (! preg_match('/^[A-F]$/', $code)) {
                continue;
            }

            $normalized[$questionId] = [
                'question_id' => $questionId,
                'code' => $code,
            ];
        }

        ksort($normalized);

        return array_values($normalized);
    }

    /**
     * @param  list<array{question_id:string,code:string}>  $items
     * @return array{level:string,flags:list<string>}
     */
    private function buildQuality(int $durationMs, array $items, array $spec): array
    {
        $qualityRules = is_array($spec['quality_rules'] ?? null) ? $spec['quality_rules'] : [];
        $flags = [];

        $speedingSecondsLt = (int) ($qualityRules['speeding_seconds_lt'] ?? 0);
        if ($speedingSecondsLt > 0 && $durationMs > 0 && $durationMs < ($speedingSecondsLt * 1000)) {
            $flags[] = 'SPEEDING';
        }

        $straightliningRunLen = (int) ($qualityRules['straightlining_run_len_gte'] ?? 0);
        if ($straightliningRunLen > 1 && $this->maxRunLengthByCode($items) >= $straightliningRunLen) {
            $flags[] = 'STRAIGHTLINING';
        }

        if ($items === []) {
            $flags[] = 'NO_VALID_ANSWERS';
        }

        $level = match (true) {
            in_array('NO_VALID_ANSWERS', $flags, true) => 'D',
            $flags === [] => 'A',
            default => 'B',
        };

        return [
            'level' => $level,
            'flags' => array_values(array_unique($flags)),
        ];
    }

    /**
     * @param  list<array{question_id:string,code:string}>  $items
     */
    private function maxRunLengthByCode(array $items): int
    {
        $maxRun = 0;
        $currentRun = 0;
        $prev = null;

        foreach ($items as $item) {
            $code = (string) ($item['code'] ?? '');
            if ($code === '') {
                continue;
            }

            if ($code === $prev) {
                $currentRun++;
            } else {
                $currentRun = 1;
                $prev = $code;
            }

            if ($currentRun > $maxRun) {
                $maxRun = $currentRun;
            }
        }

        return $maxRun;
    }
}
