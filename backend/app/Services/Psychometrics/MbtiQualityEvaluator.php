<?php

declare(strict_types=1);

namespace App\Services\Psychometrics;

use App\Services\ContentPackResolver;

final class MbtiQualityEvaluator
{
    public function __construct(
        private readonly ContentPackResolver $resolver,
        private readonly QualityChecker $checker,
    ) {}

    /**
     * @param  list<array<string,mixed>>  $answers
     * @return array<string,mixed>
     */
    public function evaluate(
        string $region,
        string $locale,
        string $contentPackageVersion,
        string $dirVersion,
        array $answers,
    ): array {
        $resolvedVersion = trim($contentPackageVersion) !== ''
            ? trim($contentPackageVersion)
            : trim($dirVersion);

        if ($resolvedVersion === '') {
            return [];
        }

        try {
            $resolved = $this->resolver->resolve('MBTI', $region, $locale, $resolvedVersion, $dirVersion);
        } catch (\Throwable) {
            return [];
        }

        $loader = $resolved->loaders['readJson'] ?? null;
        if (! is_callable($loader)) {
            return [];
        }

        $scoringSpec = $loader('scoring_spec.json');
        $qualitySpec = $loader('quality_checks.json');
        if (! is_array($scoringSpec) || ! is_array($qualitySpec)) {
            return [];
        }

        $quality = $this->checker->check($answers, $scoringSpec, $qualitySpec);
        $grade = strtoupper(trim((string) ($quality['grade'] ?? '')));
        if (! in_array($grade, ['A', 'B', 'C', 'D'], true)) {
            $grade = 'A';
        }

        $checks = is_array($quality['checks'] ?? null) ? $quality['checks'] : [];

        return [
            'level' => $grade,
            'grade' => $grade,
            'flags' => $this->deriveFlags($checks),
            'checks' => $checks,
        ];
    }

    /**
     * @param  list<mixed>  $checks
     * @return list<string>
     */
    private function deriveFlags(array $checks): array
    {
        $flags = [];

        foreach ($checks as $check) {
            if (! is_array($check) || (($check['passed'] ?? true) === true)) {
                continue;
            }

            $type = trim((string) ($check['type'] ?? ''));
            $flag = match ($type) {
                'min_answer_count' => 'INCOMPLETE_ANSWERS',
                'max_same_option_ratio' => 'STRAIGHTLINING',
                'reverse_pair_mismatch_ratio' => 'INCONSISTENT',
                default => '',
            };

            if ($flag !== '') {
                $flags[$flag] = true;
            }
        }

        return array_keys($flags);
    }
}
