<?php

declare(strict_types=1);

namespace App\Services\Attempts;

use App\Exceptions\Api\ApiProblemException;
use App\Models\Attempt;

final class AttemptAnswerPersistence
{
    public function __construct(
        private AnswerSetStore $answerSets,
        private AnswerRowWriter $answerRowWriter,
    ) {
    }

    public function canonicalJson(array $answers): string
    {
        return $this->answerSets->canonicalJson($answers);
    }

    public function persist(Attempt $attempt, array $answers, ?int $durationMs, string $scoringSpecVersion): void
    {
        $stored = $this->answerSets->storeFinalAnswers($attempt, $answers, $durationMs, $scoringSpecVersion);
        if (!($stored['ok'] ?? false)) {
            throw new ApiProblemException(
                500,
                'INTERNAL_ERROR',
                (string) ($stored['message'] ?? 'failed to store answer set.')
            );
        }

        $rowsWritten = $this->answerRowWriter->writeRows($attempt, $answers, $durationMs);
        if (!($rowsWritten['ok'] ?? false)) {
            throw new ApiProblemException(
                500,
                'INTERNAL_ERROR',
                (string) ($rowsWritten['message'] ?? 'failed to write answer rows.')
            );
        }
    }
}
