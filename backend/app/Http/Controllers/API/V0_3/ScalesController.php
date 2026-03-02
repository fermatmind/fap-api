<?php

namespace App\Http\Controllers\API\V0_3;

use App\Exceptions\Api\ApiProblemException;
use App\Http\Controllers\Controller;
use App\Services\Content\BigFivePackLoader;
use App\Services\Content\ClinicalComboPackLoader;
use App\Services\Content\Eq60PackLoader;
use App\Services\Content\QuestionsService;
use App\Services\Content\Sds20PackLoader;
use App\Services\Scale\ScaleCodeInputGuard;
use App\Services\Scale\ScaleCodeResponseProjector;
use App\Services\Scale\ScaleIdentityResolver;
use App\Services\Scale\ScaleRegistry;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScalesController extends Controller
{
    public function __construct(
        private ScaleRegistry $registry,
        private ScaleIdentityResolver $identityResolver,
        private ScaleCodeResponseProjector $responseProjector,
        private ScaleCodeInputGuard $inputGuard,
        private OrgContext $orgContext,
    ) {}

    /**
     * GET /api/v0.3/scales
     */
    public function index(Request $request): JsonResponse
    {
        $orgId = $this->orgContext->orgId();
        $items = $this->registry->listVisible($orgId);

        return response()->json([
            'ok' => true,
            'items' => $items,
        ]);
    }

    /**
     * GET /api/v0.3/scales/{scale_code}
     */
    public function show(Request $request, string $scale_code): JsonResponse
    {
        $orgId = $this->orgContext->orgId();
        $code = strtoupper(trim($scale_code));
        if ($code === '') {
            return response()->json([
                'ok' => false,
                'error_code' => 'SCALE_REQUIRED',
                'message' => 'scale_code is required.',
            ], 400);
        }
        $this->inputGuard->assertAccepted($code);

        $row = $this->registry->getByCode($code, $orgId);
        if (! $row) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'scale not found.',
            ], 404);
        }

        $scaleCodeMeta = $this->buildScaleCodeMeta($code, $row);

        return response()->json(array_merge([
            'ok' => true,
            'scale_code' => $scaleCodeMeta['scale_code'],
            'item' => $row,
        ], $scaleCodeMeta));
    }

    /**
     * GET /api/v0.3/scales/{scale_code}/questions
     */
    public function questions(
        Request $request,
        string $scale_code,
        QuestionsService $questionsService,
        BigFivePackLoader $bigFivePackLoader,
        ClinicalComboPackLoader $clinicalPackLoader,
        Sds20PackLoader $sds20PackLoader,
        Eq60PackLoader $eq60PackLoader
    ): JsonResponse {
        try {
        $orgId = $this->orgContext->orgId();
        $code = strtoupper(trim($scale_code));
        if ($code === '') {
            return response()->json([
                'ok' => false,
                'error_code' => 'SCALE_REQUIRED',
                'message' => 'scale_code is required.',
            ], 400);
        }
        $this->inputGuard->assertAccepted($code);

        $row = $this->registry->getByCode($code, $orgId);
        if (! $row) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'scale not found.',
            ], 404);
        }
        $resolvedScaleCode = strtoupper(trim((string) ($row['code'] ?? $code)));
        $scaleCodeMeta = $this->buildScaleCodeMeta($code, $row);

        $packId = (string) ($row['default_pack_id'] ?? '');
        $dirVersion = (string) ($row['default_dir_version'] ?? '');
        if ($packId === '' || $dirVersion === '') {
            return response()->json([
                'ok' => false,
                'error_code' => 'PACK_NOT_CONFIGURED',
                'message' => 'scale pack not configured.',
            ], 503);
        }

        $region = (string) ($request->query('region') ?? $row['default_region'] ?? config('content_packs.default_region', ''));
        $locale = (string) ($request->query('locale') ?? $row['default_locale'] ?? config('content_packs.default_locale', ''));

        if ($resolvedScaleCode === 'BIG5_OCEAN') {
            $version = (string) ($row['default_dir_version'] ?? BigFivePackLoader::PACK_VERSION);
            $normalizedLocale = $this->normalizeBigFiveLocale($locale);
            $compiledMin = $bigFivePackLoader->readCompiledJson('questions.min.compiled.json', $version);
            $compiled = null;
            $questionsDoc = null;
            $contentPackageVersion = $version;
            $policyCompiled = $bigFivePackLoader->readCompiledJson('policy.compiled.json', $version);
            $legalCompiled = $bigFivePackLoader->readCompiledJson('legal.compiled.json', $version);

            if (is_array($compiledMin)) {
                $questionsDoc = $this->buildBigFiveQuestionsDocFromMin($compiledMin, $normalizedLocale);
                $contentPackageVersion = (string) ($compiledMin['pack_version'] ?? $version);
            }

            if (! is_array($questionsDoc)) {
                $compiled = $bigFivePackLoader->readCompiledJson('questions.compiled.json', $version);
                if (! is_array($compiled)) {
                    return response()->json([
                        'ok' => false,
                        'error_code' => 'COMPILED_MISSING',
                        'message' => 'BIG5_OCEAN compiled questions missing.',
                    ], 503);
                }

                $questionsDocByLocale = is_array($compiled['questions_doc_by_locale'] ?? null)
                    ? $compiled['questions_doc_by_locale']
                    : [];
                $questionsDoc = $questionsDocByLocale[$normalizedLocale] ?? ($compiled['questions_doc'] ?? null);
                if (! is_array($questionsDoc)) {
                    return response()->json([
                        'ok' => false,
                        'error_code' => 'COMPILED_INVALID',
                        'message' => 'BIG5_OCEAN compiled questions invalid.',
                    ], 503);
                }
                $contentPackageVersion = (string) ($compiled['pack_version'] ?? $version);
            }

            $policy = is_array($policyCompiled['policy'] ?? null) ? $policyCompiled['policy'] : [];
            $legal = is_array($legalCompiled['legal'] ?? null) ? $legalCompiled['legal'] : [];
            $validityItemsRaw = is_array($policy['validity_items'] ?? null) ? $policy['validity_items'] : [];
            $validityItems = [];
            foreach ($validityItemsRaw as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $itemId = trim((string) ($item['item_id'] ?? ''));
                if ($itemId === '') {
                    continue;
                }
                $prompt = $normalizedLocale === 'zh-CN'
                    ? trim((string) ($item['prompt_zh'] ?? ''))
                    : trim((string) ($item['prompt_en'] ?? ''));

                $validityItems[] = [
                    'item_id' => $itemId,
                    'text' => $prompt,
                    'required' => (bool) ($item['required'] ?? false),
                ];
            }

            $disclaimerTexts = is_array($legal['texts'] ?? null) ? $legal['texts'] : [];
            $policyDisclaimerTexts = is_array($policy['disclaimer'] ?? null) ? $policy['disclaimer'] : [];
            $disclaimerText = trim((string) ($disclaimerTexts[$normalizedLocale] ?? ''));
            if ($disclaimerText === '') {
                $disclaimerText = trim((string) ($policyDisclaimerTexts[$normalizedLocale] ?? ''));
            }

            $disclaimerVersion = trim((string) ($legal['disclaimer_version'] ?? ''));
            if ($disclaimerVersion === '') {
                $disclaimerVersion = 'BIG5_OCEAN_'.$version;
            }
            $disclaimerHash = trim((string) ($legal['hash'] ?? ''));
            if ($disclaimerHash === '') {
                $disclaimerHash = hash('sha256', $disclaimerVersion.'|'.$disclaimerText);
            }

            return response()->json([
                'ok' => true,
                'scale_code' => $scaleCodeMeta['scale_code'],
                'region' => $region,
                'locale' => $normalizedLocale,
                'pack_id' => $packId,
                'dir_version' => $dirVersion,
                'content_package_version' => $contentPackageVersion,
                'questions' => $questionsDoc,
                'meta' => [
                    'validity_items' => $validityItems,
                    'disclaimer_version' => $disclaimerVersion,
                    'disclaimer_hash' => $disclaimerHash,
                    'disclaimer_text' => $disclaimerText,
                ],
            ] + $scaleCodeMeta);
        }

        if ($resolvedScaleCode === 'CLINICAL_COMBO_68') {
            $version = (string) ($row['default_dir_version'] ?? ClinicalComboPackLoader::PACK_VERSION);
            $doc = $clinicalPackLoader->loadQuestionsDoc($locale, $version);
            $consent = $clinicalPackLoader->loadConsent((string) ($doc['locale_resolved'] ?? $locale), $version);
            $privacyAddendum = $clinicalPackLoader->loadPrivacyAddendum((string) ($doc['locale_resolved'] ?? $locale), $version);
            $crisisResources = $clinicalPackLoader->loadCrisisResources((string) ($doc['locale_resolved'] ?? $locale), $region, $version);

            return response()->json([
                'ok' => true,
                'scale_code' => $scaleCodeMeta['scale_code'],
                'region' => $region,
                'locale' => (string) ($doc['locale_resolved'] ?? 'zh-CN'),
                'pack_id' => $packId,
                'dir_version' => $dirVersion,
                'content_package_version' => $version,
                'questions' => [
                    'schema' => 'fap.questions.v1',
                    'items' => is_array($doc['items'] ?? null) ? $doc['items'] : [],
                ],
                'meta' => [
                    'locale_requested' => (string) ($doc['locale_requested'] ?? $locale),
                    'locale_resolved' => (string) ($doc['locale_resolved'] ?? 'zh-CN'),
                    'modules' => is_array($doc['modules'] ?? null) ? $doc['modules'] : [],
                    'disclaimer_text' => (string) ($doc['disclaimer_text'] ?? ''),
                    'consent' => $consent,
                    'privacy_addendum' => $privacyAddendum,
                    'crisis_resources' => $crisisResources,
                ],
            ] + $scaleCodeMeta);
        }

        if ($resolvedScaleCode === 'SDS_20') {
            $version = (string) ($row['default_dir_version'] ?? Sds20PackLoader::PACK_VERSION);
            $doc = $sds20PackLoader->loadQuestionsDoc($locale, $version);
            $localeResolved = (string) ($doc['locale_resolved'] ?? $sds20PackLoader->normalizeLocale($locale));
            $landing = $sds20PackLoader->loadLanding($localeResolved, $version);
            $sourceCatalog = $sds20PackLoader->loadSourceCatalog($version);
            $optionsFormat = $sds20PackLoader->loadOptionsFormat($localeResolved, $version);

            return response()->json([
                'ok' => true,
                'scale_code' => $scaleCodeMeta['scale_code'],
                'region' => $region,
                'locale' => $localeResolved,
                'pack_id' => $packId,
                'dir_version' => $dirVersion,
                'content_package_version' => $version,
                'questions' => [
                    'schema' => 'fap.questions.v1',
                    'items' => is_array($doc['items'] ?? null) ? $doc['items'] : [],
                ],
                'options' => [
                    'format' => $optionsFormat,
                ],
                'meta' => [
                    'locale_requested' => (string) ($doc['locale_requested'] ?? $locale),
                    'locale_resolved' => $localeResolved,
                    'consent' => [
                        'required' => (bool) data_get($landing, 'consent.required', true),
                        'version' => (string) data_get($landing, 'consent.version', ''),
                        'hash' => (string) data_get($landing, 'consent.hash', ''),
                        'text' => (string) data_get($landing, 'consent.text', ''),
                    ],
                    'disclaimer' => [
                        'version' => (string) data_get($landing, 'disclaimer.version', ''),
                        'hash' => (string) data_get($landing, 'disclaimer.hash', ''),
                        'text' => (string) data_get($landing, 'disclaimer.text', ''),
                    ],
                    'source' => [
                        'items' => $sourceCatalog,
                    ],
                ],
            ] + $scaleCodeMeta);
        }

        if ($resolvedScaleCode === 'EQ_60') {
            $version = (string) ($row['default_dir_version'] ?? Eq60PackLoader::PACK_VERSION);
            $doc = $eq60PackLoader->loadQuestionsDoc($locale, $version);
            $localeResolved = (string) ($doc['locale_resolved'] ?? $eq60PackLoader->normalizeLocale($locale));

            return response()->json([
                'ok' => true,
                'scale_code' => $scaleCodeMeta['scale_code'],
                'region' => $region,
                'locale' => $localeResolved,
                'pack_id' => $packId,
                'dir_version' => $dirVersion,
                'content_package_version' => $version,
                'questions' => [
                    'schema' => 'fap.questions.v1',
                    'items' => is_array($doc['items'] ?? null) ? $doc['items'] : [],
                ],
                'meta' => [
                    'locale_requested' => (string) ($doc['locale_requested'] ?? $locale),
                    'locale_resolved' => $localeResolved,
                    'option_anchors' => is_array($doc['option_anchors'] ?? null) ? $doc['option_anchors'] : [],
                    'dimension_codes' => is_array($doc['dimension_codes'] ?? null) ? $doc['dimension_codes'] : ['SA', 'ER', 'SE', 'RM'],
                ],
            ] + $scaleCodeMeta);
        }

        $assetsBaseUrlOverride = $request->attributes->get('assets_base_url');
        $assetsBaseUrlOverride = is_string($assetsBaseUrlOverride) ? $assetsBaseUrlOverride : null;

        $loaded = $questionsService->loadByPack($packId, $dirVersion, $assetsBaseUrlOverride);
        if (! ($loaded['ok'] ?? false)) {
            $error = (string) ($loaded['error_code'] ?? $loaded['error'] ?? 'READ_FAILED');
            $status = $error === 'NOT_FOUND' ? 404 : 503;

            return response()->json([
                'ok' => false,
                'error_code' => $error,
                'message' => (string) ($loaded['message'] ?? 'failed to load questions'),
            ], $status);
        }

        return response()->json([
            'ok' => true,
            'scale_code' => $scaleCodeMeta['scale_code'],
            'region' => $region,
            'locale' => $locale,
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'content_package_version' => (string) ($loaded['content_package_version'] ?? ''),
            'questions' => $loaded['questions'],
        ] + $scaleCodeMeta);
        } catch (\Throwable $e) {
            if ($e instanceof ApiProblemException) {
                throw $e;
            }

            report($e);

            return response()->json([
                'ok' => false,
                'error_code' => 'QUESTIONS_UNAVAILABLE',
                'message' => 'questions unavailable.',
                'details' => [],
            ], 503);
        }
    }

    private function normalizeBigFiveLocale(string $locale): string
    {
        return str_starts_with(strtolower($locale), 'zh') ? 'zh-CN' : 'en';
    }

    /**
     * @param  array<string,mixed>  $compiledMin
     * @return array<string,mixed>|null
     */
    private function buildBigFiveQuestionsDocFromMin(array $compiledMin, string $normalizedLocale): ?array
    {
        $questionIndex = is_array($compiledMin['question_index'] ?? null) ? $compiledMin['question_index'] : [];
        $textsByLocale = is_array($compiledMin['texts_by_locale'] ?? null) ? $compiledMin['texts_by_locale'] : [];
        $questionOptionSetRef = is_array($compiledMin['question_option_set_ref'] ?? null)
            ? $compiledMin['question_option_set_ref']
            : [];
        $optionSets = is_array($compiledMin['option_sets'] ?? null) ? $compiledMin['option_sets'] : [];

        $zhTexts = is_array($textsByLocale['zh-CN'] ?? null) ? $textsByLocale['zh-CN'] : [];
        $enTexts = is_array($textsByLocale['en'] ?? null) ? $textsByLocale['en'] : [];

        if (count($questionIndex) !== 120 || count($zhTexts) !== 120 || count($enTexts) !== 120) {
            return null;
        }

        ksort($questionIndex, SORT_NUMERIC);

        $items = [];
        foreach ($questionIndex as $qidRaw => $meta) {
            $qid = (int) $qidRaw;
            if ($qid <= 0 || ! is_array($meta)) {
                return null;
            }

            $dimension = strtoupper(trim((string) ($meta['dimension'] ?? '')));
            $facetCode = strtoupper(trim((string) ($meta['facet_code'] ?? '')));
            $direction = (int) ($meta['direction'] ?? 0);

            $textZh = trim((string) ($zhTexts[(string) $qid] ?? $zhTexts[$qid] ?? ''));
            $textEn = trim((string) ($enTexts[(string) $qid] ?? $enTexts[$qid] ?? ''));
            if (
                $textZh === ''
                || $textEn === ''
                || $dimension === ''
                || $facetCode === ''
                || ! in_array($direction, [1, -1], true)
            ) {
                return null;
            }

            $setId = (string) ($questionOptionSetRef[(string) $qid] ?? $questionOptionSetRef[$qid] ?? 'LIKERT5');
            $rawOptionSet = is_array($optionSets[$setId] ?? null) ? $optionSets[$setId] : [];
            $options = $this->buildBigFiveQuestionOptionsForLocale($rawOptionSet, $normalizedLocale);
            if ($options === []) {
                return null;
            }

            $items[] = [
                'question_id' => (string) $qid,
                'order' => $qid,
                'dimension' => $dimension,
                'facet_code' => $facetCode,
                'text' => $normalizedLocale === 'zh-CN' ? $textZh : $textEn,
                'text_zh' => $textZh,
                'text_en' => $textEn,
                'direction' => $direction,
                'options' => $options,
            ];
        }

        return [
            'schema' => 'fap.questions.v1',
            'items' => $items,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rawOptionSet
     * @return list<array<string,mixed>>
     */
    private function buildBigFiveQuestionOptionsForLocale(array $rawOptionSet, string $normalizedLocale): array
    {
        $options = [];
        foreach ($rawOptionSet as $opt) {
            if (! is_array($opt)) {
                continue;
            }

            $code = trim((string) ($opt['code'] ?? ''));
            $score = (int) ($opt['score'] ?? 0);
            $labelZh = trim((string) ($opt['label_zh'] ?? $opt['text_zh'] ?? ''));
            $labelEn = trim((string) ($opt['label_en'] ?? $opt['text_en'] ?? ''));

            if ($code === '' || $labelZh === '' || $labelEn === '') {
                return [];
            }

            if ($normalizedLocale === 'zh-CN') {
                $options[] = [
                    'code' => $code,
                    'score' => $score,
                    'text' => $labelZh,
                    'text_en' => $labelEn,
                ];

                continue;
            }

            $options[] = [
                'code' => $code,
                'score' => $score,
                'text' => $labelEn,
                'text_zh' => $labelZh,
                'text_en' => $labelEn,
            ];
        }

        return $options;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array{
     *     requested_scale_code:string,
     *     scale_code:string,
     *     scale_code_legacy:string,
     *     scale_code_v2:string,
     *     scale_uid:?string,
     *     pack_id_v2:?string,
     *     dir_version_v2:?string,
     *     resolved_from_alias:bool
     * }
     */
    private function buildScaleCodeMeta(string $requestedScaleCode, array $row): array
    {
        $requested = strtoupper(trim($requestedScaleCode));
        $legacyCode = strtoupper(trim((string) ($row['code'] ?? $requested)));
        $identity = $this->identityResolver->resolveByAnyCode($requested);
        if (! is_array($identity) || ! ((bool) ($identity['is_known'] ?? false))) {
            $identity = $this->identityResolver->resolveByAnyCode($legacyCode);
        }

        $isKnown = is_array($identity) && ((bool) ($identity['is_known'] ?? false));
        $resolvedV1 = $isKnown
            ? strtoupper(trim((string) ($identity['scale_code_v1'] ?? $legacyCode)))
            : $legacyCode;
        $resolvedV2 = $isKnown
            ? strtoupper(trim((string) ($identity['scale_code_v2'] ?? $legacyCode)))
            : $legacyCode;
        $scaleUid = $isKnown ? trim((string) ($identity['scale_uid'] ?? '')) : '';
        $packIdV2 = $isKnown ? $this->trimOrNull($identity['pack_id_v2'] ?? null) : null;
        $dirVersionV2 = $isKnown ? $this->trimOrNull($identity['dir_version_v2'] ?? null) : null;
        $responseCodes = $this->responseProjector->project(
            $legacyCode,
            $resolvedV2,
            $scaleUid !== '' ? $scaleUid : null
        );

        return [
            'requested_scale_code' => $requested,
            'scale_code' => $responseCodes['scale_code'],
            'scale_code_legacy' => $responseCodes['scale_code_legacy'],
            'scale_code_v2' => $responseCodes['scale_code_v2'],
            'scale_uid' => $responseCodes['scale_uid'],
            'pack_id_v2' => $packIdV2 ?? $this->trimOrNull($row['default_pack_id'] ?? null),
            'dir_version_v2' => $dirVersionV2 ?? $this->trimOrNull($row['default_dir_version'] ?? null),
            'resolved_from_alias' => $requested !== $resolvedV1,
        ];
    }

    private function trimOrNull(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
