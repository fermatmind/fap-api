<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Services\Content\BigFivePackLoader;
use App\Services\Scale\ScaleIdentityResolver;

final class QuestionAnalyticsSupport
{
    private const BIG5_FRONTEND_SLUG = 'big-five-personality-test-ocean-model';

    /** @var array<string,mixed>|null */
    private ?array $big5DefinitionCache = null;

    public function __construct(
        private readonly BigFivePackLoader $bigFivePackLoader,
        private readonly ScaleIdentityResolver $scaleIdentityResolver,
    ) {}

    /**
     * @return list<string>
     */
    public function enabledAuthoritativeScaleCodes(): array
    {
        return ['BIG5_OCEAN'];
    }

    /**
     * @return array{
     *     scale_code:string,
     *     scale_code_v2:string,
     *     scale_uid:?string
     * }
     */
    public function canonicalScale(string $scaleCode = 'BIG5_OCEAN'): array
    {
        $resolved = $this->scaleIdentityResolver->resolveByAnyCode($scaleCode);

        $legacy = strtoupper(trim((string) ($resolved['scale_code_v1'] ?? 'BIG5_OCEAN')));
        $v2 = strtoupper(trim((string) ($resolved['scale_code_v2'] ?? (config('scale_identity.code_map_v1_to_v2.BIG5_OCEAN') ?? 'BIG_FIVE_OCEAN_MODEL'))));
        $uid = trim((string) ($resolved['scale_uid'] ?? (config('scale_identity.scale_uid_map.BIG5_OCEAN') ?? '')));

        return [
            'scale_code' => $legacy !== '' ? $legacy : 'BIG5_OCEAN',
            'scale_code_v2' => $v2 !== '' ? $v2 : 'BIG_FIVE_OCEAN_MODEL',
            'scale_uid' => $uid !== '' ? $uid : null,
        ];
    }

    /**
     * @param  list<string>  $requestedScaleCodes
     * @return array<string,array<string,mixed>>
     */
    public function authoritativeScaleConfigs(array $requestedScaleCodes = []): array
    {
        $requested = array_map(
            static fn (string $value): string => strtoupper(trim($value)),
            array_filter($requestedScaleCodes, static fn (string $value): bool => trim($value) !== '')
        );
        $requested = array_values(array_unique($requested));

        $configs = [
            'BIG5_OCEAN' => $this->big5Config(),
        ];

        if ($requested === []) {
            return $configs;
        }

        return array_intersect_key($configs, array_flip($requested));
    }

    public function resolveAuthoritativeScaleCode(
        ?string $scaleCode,
        ?string $scaleCodeV2 = null,
        ?string $scaleUid = null,
    ): ?string {
        $legacy = strtoupper(trim((string) $scaleCode));
        $v2 = strtoupper(trim((string) $scaleCodeV2));
        $uid = trim((string) $scaleUid);

        $canonical = $this->canonicalScale();

        if ($legacy === $canonical['scale_code'] || $v2 === $canonical['scale_code_v2']) {
            return $canonical['scale_code'];
        }

        if ($uid !== '' && $canonical['scale_uid'] !== null && $uid === $canonical['scale_uid']) {
            return $canonical['scale_code'];
        }

        return null;
    }

    public function frontendLocaleSegment(string $locale): string
    {
        return str_starts_with(strtolower(trim($locale)), 'zh') ? 'zh' : 'en';
    }

    /**
     * @return array{detail:?string,take:?string}
     */
    public function frontendUrls(string $locale): array
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');
        if ($base === '') {
            return [
                'detail' => null,
                'take' => null,
            ];
        }

        $segment = $this->frontendLocaleSegment($locale);

        return [
            'detail' => $base.'/'.$segment.'/tests/'.self::BIG5_FRONTEND_SLUG,
            'take' => $base.'/'.$segment.'/tests/'.self::BIG5_FRONTEND_SLUG.'/take',
        ];
    }

    public function attemptExplorerUrl(?string $questionId = null): string
    {
        $url = '/ops/attempts?tableSearch=BIG5_OCEAN';

        if ($questionId === null || trim($questionId) === '') {
            return $url;
        }

        return $url.'%20'.rawurlencode(trim($questionId));
    }

    /**
     * @return array{
     *     order_by_question_id:array<string,int>,
     *     question_ids_by_order:array<int,string>,
     *     questions:array<string,array<string,mixed>>,
     *     option_labels:array<string,array<string,string>>,
     *     total_questions:int
     * }
     */
    public function big5Definition(): array
    {
        if ($this->big5DefinitionCache !== null) {
            return $this->big5DefinitionCache;
        }

        $questionsCompiled = $this->bigFivePackLoader->readCompiledJson('questions.compiled.json');
        $compiledQuestions = is_array($questionsCompiled['questions'] ?? null)
            ? array_values(array_filter(
                $questionsCompiled['questions'],
                static fn (mixed $row): bool => is_array($row)
            ))
            : [];

        $orderByQuestionId = [];
        $questionIdsByOrder = [];
        $questions = [];

        if ($compiledQuestions !== []) {
            foreach ($compiledQuestions as $index => $question) {
                $questionId = trim((string) ($question['question_id'] ?? ''));
                if ($questionId === '') {
                    continue;
                }

                $order = $index + 1;
                $orderByQuestionId[$questionId] = $order;
                $questionIdsByOrder[$order] = $questionId;
                $questions[$questionId] = [
                    'question_id' => $questionId,
                    'question_order' => $order,
                    'dimension' => strtoupper(trim((string) ($question['dimension'] ?? ''))),
                    'facet_code' => strtoupper(trim((string) ($question['facet_code'] ?? ''))),
                    'direction' => (int) ($question['direction'] ?? 0),
                    'text_en' => trim((string) ($question['text_en'] ?? '')),
                    'text_zh' => trim((string) ($question['text_zh'] ?? '')),
                ];
            }
        }

        if ($orderByQuestionId === []) {
            $questionIndex = $this->bigFivePackLoader->readQuestionIndexPreferred() ?? [];
            $order = 0;

            foreach ($questionIndex as $questionId => $question) {
                if (! is_array($question)) {
                    continue;
                }

                $normalizedQuestionId = trim((string) $questionId);
                if ($normalizedQuestionId === '') {
                    continue;
                }

                $order++;
                $orderByQuestionId[$normalizedQuestionId] = $order;
                $questionIdsByOrder[$order] = $normalizedQuestionId;
                $questions[$normalizedQuestionId] = [
                    'question_id' => $normalizedQuestionId,
                    'question_order' => $order,
                    'dimension' => strtoupper(trim((string) ($question['dimension'] ?? ''))),
                    'facet_code' => strtoupper(trim((string) ($question['facet_code'] ?? ''))),
                    'direction' => (int) ($question['direction'] ?? 0),
                    'text_en' => '',
                    'text_zh' => '',
                ];
            }
        }

        $questionMinCompiled = $this->bigFivePackLoader->readCompiledJson('questions.min.compiled.json');
        $optionSets = is_array($questionMinCompiled['option_sets'] ?? null)
            ? $questionMinCompiled['option_sets']
            : [];
        $likertOptions = is_array($optionSets['LIKERT5'] ?? null) ? $optionSets['LIKERT5'] : [];
        $optionLabels = [];

        foreach ($likertOptions as $option) {
            if (! is_array($option)) {
                continue;
            }

            $code = trim((string) ($option['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $optionLabels[$code] = [
                'label_en' => trim((string) ($option['label_en'] ?? $code)),
                'label_zh' => trim((string) ($option['label_zh'] ?? $code)),
            ];
        }

        $this->big5DefinitionCache = [
            'order_by_question_id' => $orderByQuestionId,
            'question_ids_by_order' => $questionIdsByOrder,
            'questions' => $questions,
            'option_labels' => $optionLabels,
            'total_questions' => count($questionIdsByOrder),
        ];

        return $this->big5DefinitionCache;
    }

    /**
     * @return array<string,mixed>
     */
    private function big5Config(): array
    {
        $canonical = $this->canonicalScale('BIG5_OCEAN');
        $definition = $this->big5Definition();

        return $canonical + [
            'label' => 'BIG5_OCEAN',
            'frontend_slug' => self::BIG5_FRONTEND_SLUG,
            'question_definition' => $definition,
            'total_questions' => (int) ($definition['total_questions'] ?? 0),
        ];
    }
}
