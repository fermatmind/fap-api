<?php

declare(strict_types=1);

namespace App\Services\Content;

final class Eq60ContentLintService
{
    private const REQUIRED_QUESTION_COUNT = 60;

    /**
     * @var list<string>
     */
    private const REQUIRED_DIMENSIONS = ['SA', 'ER', 'EM', 'RM'];

    /**
     * @var list<int>
     */
    private const REVERSE_QUESTION_IDS = [5, 10, 11, 15, 18, 20, 22, 23, 29, 30, 31, 42, 44, 52, 53, 57];

    public function __construct(
        private readonly Eq60PackLoader $loader,
    ) {}

    /**
     * @return array{ok:bool,pack_id:string,version:string,errors:list<array{file:string,line:int,message:string}>}
     */
    public function lint(?string $version = null): array
    {
        $version = $this->loader->normalizeVersion($version);
        $errors = [];

        $questionIndex = $this->lintQuestions($version, $errors);
        $this->lintOptions($version, $errors);
        $this->lintPolicy($version, $questionIndex, $errors);
        $this->lintLanding($version, $errors);
        $this->lintGoldenCases($version, $questionIndex, $errors);

        return [
            'ok' => $errors === [],
            'pack_id' => Eq60PackLoader::PACK_ID,
            'version' => $version,
            'errors' => $errors,
        ];
    }

    /**
     * @param list<array{file:string,line:int,message:string}> $errors
     * @return array<int,array{dimension:string,direction:int}>
     */
    private function lintQuestions(string $version, array &$errors): array
    {
        $file = $this->loader->rawPath('questions_eq60_bilingual.csv', $version);
        $rows = $this->loader->readCsvWithLines($file);
        if (count($rows) !== self::REQUIRED_QUESTION_COUNT) {
            $errors[] = $this->error($file, 1, 'questions must contain exactly 60 rows.');
        }

        $seen = [];
        $dimensionCounts = array_fill_keys(self::REQUIRED_DIMENSIONS, 0);
        $index = [];

        foreach ($rows as $entry) {
            $line = (int) ($entry['line'] ?? 0);
            $row = (array) ($entry['row'] ?? []);

            $qid = (int) ($row['question_id'] ?? 0);
            if ($qid <= 0) {
                $errors[] = $this->error($file, $line, 'question_id must be positive integer.');
                continue;
            }

            $seen[$qid] = ($seen[$qid] ?? 0) + 1;

            $dimension = strtoupper(trim((string) ($row['dimension'] ?? '')));
            if (!in_array($dimension, self::REQUIRED_DIMENSIONS, true)) {
                $errors[] = $this->error($file, $line, 'dimension must be one of SA/ER/EM/RM.');
                continue;
            }

            $expectedDimension = $this->expectedDimensionForQuestion($qid);
            if ($expectedDimension === null || $dimension !== $expectedDimension) {
                $errors[] = $this->error($file, $line, 'dimension does not match question_id range.');
            }

            $direction = (int) ($row['direction'] ?? 0);
            if (!in_array($direction, [1, -1], true)) {
                $errors[] = $this->error($file, $line, 'direction must be 1 or -1.');
                continue;
            }

            $isReverse = in_array($qid, self::REVERSE_QUESTION_IDS, true);
            if ($isReverse && $direction !== -1) {
                $errors[] = $this->error($file, $line, 'reverse question direction must be -1.');
            }
            if (!$isReverse && $direction !== 1) {
                $errors[] = $this->error($file, $line, 'forward question direction must be 1.');
            }

            if (trim((string) ($row['text_en'] ?? '')) === '') {
                $errors[] = $this->error($file, $line, 'text_en is required.');
            }
            if (trim((string) ($row['text_zh'] ?? '')) === '') {
                $errors[] = $this->error($file, $line, 'text_zh is required.');
            }

            $dimensionCounts[$dimension] = (int) ($dimensionCounts[$dimension] ?? 0) + 1;
            $index[$qid] = [
                'dimension' => $dimension,
                'direction' => $direction,
            ];
        }

        for ($qid = 1; $qid <= self::REQUIRED_QUESTION_COUNT; $qid++) {
            if (($seen[$qid] ?? 0) !== 1) {
                $errors[] = $this->error($file, 1, 'question_id=' . $qid . ' must appear exactly once.');
            }
        }

        foreach (self::REQUIRED_DIMENSIONS as $dimension) {
            if ((int) ($dimensionCounts[$dimension] ?? 0) !== 15) {
                $errors[] = $this->error($file, 1, 'dimension ' . $dimension . ' must contain exactly 15 questions.');
            }
        }

        ksort($index, SORT_NUMERIC);

        return $index;
    }

    /**
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintOptions(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('options_eq60_bilingual.json', $version);
        $doc = $this->loader->readJson($file);
        if (!is_array($doc)) {
            $errors[] = $this->error($file, 1, 'options json invalid.');
            return;
        }

        $codes = array_values(array_map(
            static fn ($code): string => strtoupper(trim((string) $code)),
            (array) ($doc['codes'] ?? [])
        ));
        if ($codes !== ['A', 'B', 'C', 'D', 'E']) {
            $errors[] = $this->error($file, 1, 'codes must be [A,B,C,D,E].');
        }

        foreach (['zh-CN', 'en'] as $locale) {
            $labels = data_get($doc, 'labels.' . $locale, null);
            if (!is_array($labels) || count($labels) !== 5) {
                $errors[] = $this->error($file, 1, 'labels.' . $locale . ' must contain exactly 5 options.');
                continue;
            }

            foreach ($labels as $idx => $label) {
                if (trim((string) $label) === '') {
                    $errors[] = $this->error($file, 1, 'labels.' . $locale . '[' . $idx . '] is required.');
                }
            }
        }

        $scoreMap = is_array($doc['score_map'] ?? null) ? $doc['score_map'] : [];
        $expected = ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5];
        foreach ($expected as $code => $score) {
            if (!array_key_exists($code, $scoreMap)) {
                $errors[] = $this->error($file, 1, 'score_map missing code ' . $code . '.');
                continue;
            }
            if ((int) $scoreMap[$code] !== $score) {
                $errors[] = $this->error($file, 1, 'score_map.' . $code . ' must be ' . $score . '.');
            }
        }
    }

    /**
     * @param array<int,array{dimension:string,direction:int}> $questionIndex
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintPolicy(string $version, array $questionIndex, array &$errors): void
    {
        $file = $this->loader->rawPath('policy.json', $version);
        $doc = $this->loader->readJson($file);
        if (!is_array($doc)) {
            $errors[] = $this->error($file, 1, 'policy.json invalid.');
            return;
        }

        foreach (['engine_version', 'scoring_spec_version', 'dimension_map', 'reverse_question_ids', 'score_range'] as $key) {
            if (!array_key_exists($key, $doc)) {
                $errors[] = $this->error($file, 1, 'policy field missing: ' . $key);
            }
        }

        if (trim((string) ($doc['engine_version'] ?? '')) !== 'v1.0_normed_validity') {
            $errors[] = $this->error($file, 1, 'engine_version must be v1.0_normed_validity.');
        }
        if (trim((string) ($doc['scoring_spec_version'] ?? '')) !== 'eq60_spec_2026_v2') {
            $errors[] = $this->error($file, 1, 'scoring_spec_version must be eq60_spec_2026_v2.');
        }

        $dimensionMap = is_array($doc['dimension_map'] ?? null) ? $doc['dimension_map'] : [];
        $covered = [];
        foreach (self::REQUIRED_DIMENSIONS as $dimension) {
            $items = $dimensionMap[$dimension] ?? null;
            if (!is_array($items) || count($items) !== 15) {
                $errors[] = $this->error($file, 1, 'dimension_map.' . $dimension . ' must have exactly 15 questions.');
                continue;
            }

            foreach ($items as $qidRaw) {
                $qid = (int) $qidRaw;
                if ($qid < 1 || $qid > self::REQUIRED_QUESTION_COUNT) {
                    $errors[] = $this->error($file, 1, 'dimension_map.' . $dimension . ' contains invalid question id.');
                    continue;
                }
                $covered[] = $qid;

                $indexNode = $questionIndex[$qid] ?? null;
                if (!is_array($indexNode) || ($indexNode['dimension'] ?? '') !== $dimension) {
                    $errors[] = $this->error($file, 1, 'dimension_map.' . $dimension . ' does not match questions csv for id=' . $qid . '.');
                }
            }
        }

        sort($covered);
        if ($covered !== range(1, self::REQUIRED_QUESTION_COUNT)) {
            $errors[] = $this->error($file, 1, 'dimension_map must cover 1..60 exactly once.');
        }

        $reverse = array_map('intval', (array) ($doc['reverse_question_ids'] ?? []));
        sort($reverse);
        $expectedReverse = self::REVERSE_QUESTION_IDS;
        sort($expectedReverse);
        if ($reverse !== $expectedReverse) {
            $errors[] = $this->error($file, 1, 'reverse_question_ids must match fixed reverse item set.');
        }

        $totalMin = (int) data_get($doc, 'score_range.total.min', 0);
        $totalMax = (int) data_get($doc, 'score_range.total.max', 0);
        if ($totalMin !== 60 || $totalMax !== 300) {
            $errors[] = $this->error($file, 1, 'score_range.total must be {min:60,max:300}.');
        }

        $dimMin = (int) data_get($doc, 'score_range.dimension.min', 0);
        $dimMax = (int) data_get($doc, 'score_range.dimension.max', 0);
        if ($dimMin !== 15 || $dimMax !== 75) {
            $errors[] = $this->error($file, 1, 'score_range.dimension must be {min:15,max:75}.');
        }
    }

    /**
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintLanding(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('landing_i18n.json', $version);
        $doc = $this->loader->readJson($file);
        if (!is_array($doc)) {
            $errors[] = $this->error($file, 1, 'landing_i18n.json invalid.');
            return;
        }

        foreach (['zh-CN', 'en'] as $locale) {
            $node = is_array($doc[$locale] ?? null) ? $doc[$locale] : null;
            if (!is_array($node)) {
                $errors[] = $this->error($file, 1, 'landing node missing for ' . $locale . '.');
                continue;
            }

            if (trim((string) ($node['title'] ?? '')) === '') {
                $errors[] = $this->error($file, 1, 'title is required for ' . $locale . '.');
            }

            $consentVersion = trim((string) data_get($node, 'consent.version', ''));
            $consentText = trim((string) data_get($node, 'consent.text', ''));
            if ($consentVersion === '' || $consentText === '') {
                $errors[] = $this->error($file, 1, 'consent version/text missing for ' . $locale . '.');
            }

            $disclaimerVersion = trim((string) data_get($node, 'disclaimer.version', ''));
            $disclaimerText = trim((string) data_get($node, 'disclaimer.text', ''));
            if ($disclaimerVersion === '' || $disclaimerText === '') {
                $errors[] = $this->error($file, 1, 'disclaimer version/text missing for ' . $locale . '.');
            }
        }
    }

    /**
     * @param array<int,array{dimension:string,direction:int}> $questionIndex
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintGoldenCases(string $version, array $questionIndex, array &$errors): void
    {
        $file = $this->loader->rawPath('golden_cases.csv', $version);
        $rows = $this->loader->readCsvWithLines($file);
        if ($rows === []) {
            $errors[] = $this->error($file, 1, 'golden_cases.csv cannot be empty.');
            return;
        }

        foreach ($rows as $entry) {
            $line = (int) ($entry['line'] ?? 0);
            $row = (array) ($entry['row'] ?? []);

            $caseId = trim((string) ($row['case_id'] ?? ''));
            if ($caseId === '') {
                $errors[] = $this->error($file, $line, 'case_id is required.');
            }

            $answers = strtoupper(trim((string) ($row['answers'] ?? '')));
            if (strlen($answers) !== self::REQUIRED_QUESTION_COUNT) {
                $errors[] = $this->error($file, $line, 'answers length must be exactly 60.');
                continue;
            }

            if (preg_match('/^[ABCDE]{60}$/', $answers) !== 1) {
                $errors[] = $this->error($file, $line, 'answers must contain only A-E.');
                continue;
            }

            $computed = $this->computeScoresByAnswerString($answers, $questionIndex);
            $expectedTotal = (int) ($row['expected_total'] ?? -1);
            $expectedSa = (int) ($row['expected_sa'] ?? -1);
            $expectedEr = (int) ($row['expected_er'] ?? -1);
            $expectedEm = (int) ($row['expected_em'] ?? -1);
            $expectedRm = (int) ($row['expected_rm'] ?? -1);

            if (
                $expectedTotal !== $computed['total']
                || $expectedSa !== $computed['SA']
                || $expectedEr !== $computed['ER']
                || $expectedEm !== $computed['EM']
                || $expectedRm !== $computed['RM']
            ) {
                $errors[] = $this->error($file, $line, 'golden case expected scores do not match computed scores.');
            }
        }
    }

    private function expectedDimensionForQuestion(int $qid): ?string
    {
        if ($qid >= 1 && $qid <= 15) {
            return 'SA';
        }
        if ($qid >= 16 && $qid <= 30) {
            return 'ER';
        }
        if ($qid >= 31 && $qid <= 45) {
            return 'EM';
        }
        if ($qid >= 46 && $qid <= 60) {
            return 'RM';
        }

        return null;
    }

    /**
     * @param array<int,array{dimension:string,direction:int}> $questionIndex
     * @return array{SA:int,ER:int,EM:int,RM:int,total:int}
     */
    private function computeScoresByAnswerString(string $answers, array $questionIndex): array
    {
        $scoreMap = ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5];
        $dimScores = ['SA' => 0, 'ER' => 0, 'EM' => 0, 'RM' => 0];

        for ($qid = 1; $qid <= self::REQUIRED_QUESTION_COUNT; $qid++) {
            $code = substr($answers, $qid - 1, 1);
            $base = (int) ($scoreMap[$code] ?? 0);
            $indexNode = $questionIndex[$qid] ?? ['dimension' => '', 'direction' => 1];
            $dimension = strtoupper(trim((string) ($indexNode['dimension'] ?? '')));
            $direction = (int) ($indexNode['direction'] ?? 1);
            if (!isset($dimScores[$dimension])) {
                continue;
            }

            $resolved = $direction === -1 ? 6 - $base : $base;
            $dimScores[$dimension] += $resolved;
        }

        return [
            'SA' => $dimScores['SA'],
            'ER' => $dimScores['ER'],
            'EM' => $dimScores['EM'],
            'RM' => $dimScores['RM'],
            'total' => $dimScores['SA'] + $dimScores['ER'] + $dimScores['EM'] + $dimScores['RM'],
        ];
    }

    /**
     * @return array{file:string,line:int,message:string}
     */
    private function error(string $file, int $line, string $message): array
    {
        return [
            'file' => $file,
            'line' => $line,
            'message' => $message,
        ];
    }
}
