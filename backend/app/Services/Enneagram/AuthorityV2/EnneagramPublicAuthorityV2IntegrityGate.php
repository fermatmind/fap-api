<?php

declare(strict_types=1);

namespace App\Services\Enneagram\AuthorityV2;

final class EnneagramPublicAuthorityV2IntegrityGate
{
    public const ARTIFACT = 'ENNEAGRAM-PUBLIC-AUTHORITY-V2-INTEGRITY-GATE-02';

    private const BASE_URL = 'https://fermatmind.com';

    private const ENTITY_COUNTS = [
        'hub' => 2,
        'center' => 6,
        'core_type' => 18,
        'wing' => 36,
        'instinctual_subtype' => 54,
    ];

    private const REVIEW_STATES = [
        'agent_promoted_content_ready',
        'published_no_llms',
    ];

    private const PRIVATE_PATH_PATTERN =
        '~/(?:attempts?|reports?|results?|orders?|payments?|checkout|account|me)(?:/|$)~i';

    private const PRIVATE_QUERY_PATTERN =
        '/(?:^|&)(?:token|session|user_id|attempt_id|result_id|report_id|order_no|payment_id)=/i';

    /**
     * @param  array<string, mixed>  $scorecard
     * @return array<string, mixed>
     */
    public function validate(array $scorecard): array
    {
        $errors = [];
        $rows = is_array($scorecard['rows'] ?? null) ? array_values($scorecard['rows']) : [];
        $expected = $this->expectedRows();

        $this->validateEnvelope($scorecard, $rows, $errors);

        $actualByKey = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $this->error($errors, 'row_not_object', 'row:'.$index, 'Every scorecard row must be an object.');

                continue;
            }

            $key = $this->rowKey($row);
            if ($key === null) {
                $this->error($errors, 'row_identity_missing', 'row:'.$index, 'Each row requires identity_key and locale.');

                continue;
            }

            if (isset($actualByKey[$key])) {
                $this->error($errors, 'duplicate_identity_locale', $key, 'Each identity and locale pair must be unique.');

                continue;
            }

            $actualByKey[$key] = $row;
            if (! isset($expected[$key])) {
                $this->error($errors, 'unexpected_taxonomy_row', $key, 'The row is outside the frozen 58-identity bilingual taxonomy.');
            }
        }

        foreach ($expected as $key => $expectedRow) {
            $row = $actualByKey[$key] ?? null;
            if (! is_array($row)) {
                $this->error($errors, 'missing_taxonomy_row', $key, 'The frozen bilingual taxonomy row is missing.');

                continue;
            }

            $this->validateRow($key, $row, $expectedRow, $errors);
        }

        $codes = array_values(array_unique(array_column($errors, 'code')));
        sort($codes);

        return [
            'artifact' => self::ARTIFACT,
            'status' => $errors === [] ? 'pass' : 'fail',
            'ok' => $errors === [],
            'expected_identity_count' => 58,
            'expected_page_count' => 116,
            'source_page_count' => count($rows),
            'unique_identity_locale_count' => count($actualByKey),
            'error_count' => count($errors),
            'error_codes' => $codes,
            'errors' => $errors,
            'checks' => [
                'taxonomy' => $this->checkStatus($errors, ['unexpected_taxonomy_row', 'missing_taxonomy_row', 'duplicate_identity_locale', 'row_identity_missing']),
                'route_canonical_hreflang' => $this->checkStatus($errors, ['route_mismatch', 'http_truth_mismatch', 'canonical_mismatch', 'hreflang_mismatch']),
                'private_boundary' => $this->checkStatus($errors, ['private_boundary_violation', 'private_boundary_truth_conflict']),
                'qa_review_truth' => $this->checkStatus($errors, ['review_truth_conflict', 'review_state_invalid', 'revision_pointer_exposed', 'truth_boundary_conflict']),
            ],
            'writes_committed' => false,
            'cms_write_attempted' => false,
            'database_mutation_attempted' => false,
            'indexability_mutation_attempted' => false,
            'search_submission_attempted' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $scorecard
     * @param  list<mixed>  $rows
     * @param  list<array{code:string,row_key:string,message:string}>  $errors
     */
    private function validateEnvelope(array $scorecard, array $rows, array &$errors): void
    {
        if (($scorecard['capture_mode'] ?? null) !== 'read_only_http_get') {
            $this->error($errors, 'truth_boundary_conflict', 'scorecard', 'Integrity input must be the read-only production capture.');
        }

        $scope = is_array($scorecard['scope'] ?? null) ? $scorecard['scope'] : [];
        if (($scope['identity_count'] ?? null) !== 58 || ($scope['page_count'] ?? null) !== 116 || count($rows) !== 116) {
            $this->error($errors, 'truth_boundary_conflict', 'scorecard', 'Scope must freeze exactly 58 identities and 116 bilingual pages.');
        }
        if (($scope['locales'] ?? null) !== ['en', 'zh-CN']) {
            $this->error($errors, 'truth_boundary_conflict', 'scorecard', 'Only the frozen en and zh-CN locales are allowed.');
        }
        if (($scope['counts_by_entity_type'] ?? null) !== self::ENTITY_COUNTS) {
            $this->error($errors, 'truth_boundary_conflict', 'scorecard', 'Entity counts do not match the frozen Enneagram taxonomy.');
        }

        $truth = is_array($scorecard['truth_boundary'] ?? null) ? $scorecard['truth_boundary'] : [];
        if (($truth['human_review_completed_count'] ?? null) !== 0 || ($truth['production_writes_performed'] ?? null) !== false) {
            $this->error($errors, 'truth_boundary_conflict', 'scorecard', 'The benchmark must not claim human review or production writes.');
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $expected
     * @param  list<array{code:string,row_key:string,message:string}>  $errors
     */
    private function validateRow(string $key, array $row, array $expected, array &$errors): void
    {
        foreach (['identity_key', 'locale', 'entity_type', 'code', 'path', 'url'] as $field) {
            if (($row[$field] ?? null) !== ($expected[$field] ?? null)) {
                $this->error($errors, 'route_mismatch', $key, 'Frozen route field '.$field.' does not match the expected taxonomy.');
            }
        }

        if (($row['http_status'] ?? null) !== 200 || ($row['soft_404'] ?? null) !== false || ($row['effective_url'] ?? null) !== $expected['url']) {
            $this->error($errors, 'http_truth_mismatch', $key, 'Every public taxonomy route must be a non-soft-404 canonical HTTP 200.');
        }

        if (($row['canonical'] ?? null) !== $expected['url']) {
            $this->error($errors, 'canonical_mismatch', $key, 'Canonical must equal the frozen public URL.');
        }

        if (($row['hreflang'] ?? null) !== $expected['hreflang']) {
            $this->error($errors, 'hreflang_mismatch', $key, 'Hreflang must expose exact en, zh-CN, and English x-default counterparts.');
        }

        foreach (array_merge(
            [(string) ($row['path'] ?? ''), (string) ($row['url'] ?? ''), (string) ($row['canonical'] ?? '')],
            array_values(is_array($row['hreflang'] ?? null) ? $row['hreflang'] : [])
        ) as $publicTarget) {
            if ($this->isPrivateTarget((string) $publicTarget)) {
                $this->error($errors, 'private_boundary_violation', $key, 'Public authority metadata must never reference a private result, report, attempt, order, payment, checkout, account, or user target.');
                break;
            }
        }

        $private = is_array($row['private_boundary'] ?? null) ? $row['private_boundary'] : [];
        if (($private['safe'] ?? null) !== true || ($private['violations'] ?? null) !== []) {
            $this->error($errors, 'private_boundary_truth_conflict', $key, 'Private-boundary truth must be safe with zero violations.');
        }

        $review = is_array($row['review_truth'] ?? null) ? $row['review_truth'] : [];
        if (! in_array($review['review_state'] ?? null, self::REVIEW_STATES, true)) {
            $this->error($errors, 'review_state_invalid', $key, 'Only the frozen non-human review states are accepted.');
        }
        if (($review['reviewer'] ?? null) !== null || ($review['human_review_completed'] ?? null) !== false) {
            $this->error($errors, 'review_truth_conflict', $key, 'Agent/model state must not be represented as named human review.');
        }

        $revision = is_array($row['revision_state'] ?? null) ? $row['revision_state'] : [];
        if (($revision['public_revision_pointer_exposed'] ?? null) !== false || ($revision['working_revision_pointer_exposed'] ?? null) !== false) {
            $this->error($errors, 'revision_pointer_exposed', $key, 'The V1 benchmark must not synthesize Authority V2 revision pointers.');
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function expectedRows(): array
    {
        $identities = [[
            'identity_key' => 'hub:enneagram',
            'entity_type' => 'hub',
            'code' => 'enneagram',
            'suffix' => '',
        ]];

        foreach (['gut', 'heart', 'head'] as $center) {
            $identities[] = [
                'identity_key' => 'center:'.$center,
                'entity_type' => 'center',
                'code' => $center,
                'suffix' => '/centers/'.$center,
            ];
        }
        foreach (range(1, 9) as $type) {
            $identities[] = [
                'identity_key' => 'core_type:type-'.$type,
                'entity_type' => 'core_type',
                'code' => 'type-'.$type,
                'suffix' => '/type-'.$type,
            ];
        }
        foreach (['1w9', '1w2', '2w1', '2w3', '3w2', '3w4', '4w3', '4w5', '5w4', '5w6', '6w5', '6w7', '7w6', '7w8', '8w7', '8w9', '9w8', '9w1'] as $wing) {
            $identities[] = [
                'identity_key' => 'wing:'.$wing,
                'entity_type' => 'wing',
                'code' => $wing,
                'suffix' => '/wings/'.$wing,
            ];
        }
        foreach (range(1, 9) as $type) {
            foreach (['self-preservation', 'social', 'one-to-one'] as $instinct) {
                $code = 'type-'.$type.'/'.$instinct;
                $identities[] = [
                    'identity_key' => 'instinctual_subtype:'.$code,
                    'entity_type' => 'instinctual_subtype',
                    'code' => $code,
                    'suffix' => '/type-'.$type.'/instincts/'.$instinct,
                ];
            }
        }

        $expected = [];
        foreach (['en' => 'en', 'zh-CN' => 'zh'] as $locale => $pathLocale) {
            foreach ($identities as $identity) {
                $path = '/'.$pathLocale.'/personality/enneagram'.$identity['suffix'];
                $enPath = '/en/personality/enneagram'.$identity['suffix'];
                $zhPath = '/zh/personality/enneagram'.$identity['suffix'];
                $key = $identity['identity_key'].'|'.$locale;
                $expected[$key] = [
                    'identity_key' => $identity['identity_key'],
                    'locale' => $locale,
                    'entity_type' => $identity['entity_type'],
                    'code' => $identity['code'],
                    'path' => $path,
                    'url' => self::BASE_URL.$path,
                    'hreflang' => [
                        'en' => self::BASE_URL.$enPath,
                        'zh-CN' => self::BASE_URL.$zhPath,
                        'x-default' => self::BASE_URL.$enPath,
                    ],
                ];
            }
        }

        return $expected;
    }

    /** @param array<string, mixed> $row */
    private function rowKey(array $row): ?string
    {
        $identity = trim((string) ($row['identity_key'] ?? ''));
        $locale = trim((string) ($row['locale'] ?? ''));

        return $identity !== '' && $locale !== '' ? $identity.'|'.$locale : null;
    }

    private function isPrivateTarget(string $target): bool
    {
        $parts = parse_url($target);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : $target;
        $query = is_array($parts) ? (string) ($parts['query'] ?? '') : '';

        return preg_match(self::PRIVATE_PATH_PATTERN, $path) === 1
            || preg_match(self::PRIVATE_QUERY_PATTERN, $query) === 1;
    }

    /**
     * @param  list<array{code:string,row_key:string,message:string}>  $errors
     * @param  list<string>  $codes
     */
    private function checkStatus(array $errors, array $codes): string
    {
        foreach ($errors as $error) {
            if (in_array($error['code'], $codes, true)) {
                return 'fail';
            }
        }

        return 'pass';
    }

    /** @param list<array{code:string,row_key:string,message:string}> $errors */
    private function error(array &$errors, string $code, string $rowKey, string $message): void
    {
        $errors[] = [
            'code' => $code,
            'row_key' => $rowKey,
            'message' => $message,
        ];
    }

    public const EDITORIAL_ARTIFACT = 'ENNEAGRAM-PUBLIC-AUTHORITY-V2-EDITORIAL-GATE-08';

    public const EDITORIAL_SCHEMA_VERSION = 'enneagram_public_authority_v2_editorial_candidate.v1';

    public const EDITORIAL_TARGET_COUNT = 116;

    /** @var list<string> */
    private const EDITORIAL_GATES = [
        'schema_and_target_coverage',
        'bilingual_independence',
        'page_specific_information_gain',
        'duplicate_template_risk',
        'faq_depth',
        'observation_exercise_specificity',
        'geo_answerability',
        'visible_evidence',
        'manual_review_truth',
        'claim_safety',
    ];

    private const UNSUPPORTED_CLAIM_PATTERN = '/(?:(?:scientifically|clinically)\s+(?:proven|validated)|neuroscience proves|absolute(?:ly)? accurate|most accurate personality test|(?:global(?:ly)?|worldwide|world[\'’]?s)\s+(?:first|best|most\s+accurate)\b|(?:科学|临床)(?:已)?(?:证明|验证)|神经科学证明|绝对准确|全球(?:第一|首个|最好|最佳|最准确))/iu';

    private const FERMATMIND_PSYCHOMETRICS_PATTERN = '/(?:(?:fermatmind|费马测试|费马测评)[^.!?。！？\n]{0,80}(?:reliab(?:ility|le)|valid(?:ity|ated)|norms?|percentiles?|信度|效度|常模|百分位)|(?:reliab(?:ility|le)|valid(?:ity|ated)|norms?|percentiles?|信度|效度|常模|百分位)[^.!?。！？\n]{0,80}(?:fermatmind|费马测试|费马测评))/iu';

    private const UNSUPPORTED_CENTER_SYSTEM_PATTERN = '/(?:(?:biological|diagnostic|neurological|neuroscience)[\s-]+(?:systems?|categories?|capacities?)|(?:生物|诊断|神经|神经科学)(?:系统|类别|能力))/iu';

    private const UNSUPPORTED_DISCOVERABILITY_PATTERN = '/(?:(?:guarantee(?:d|s|ing)?|ensure[sd]?|deliver(?:s|ed)?|boost(?:s|ed)?|increase[sd]?|improve[sd]?)[^.!?。！？\n]{0,60}(?:search[\s-]+rankings?|traffic(?:[\s-]+lift)?|ai[\s-]+citations?|citation[\s-]+outcomes?)|(?:search[\s-]+rankings?|traffic[\s-]+lift|ai[\s-]+citations?|citation[\s-]+outcomes?)[^.!?。！？\n]{0,30}(?:guarantee(?:d)?|assured)|(?:保证|确保|提升|增加)[^。！？\n]{0,30}(?:搜索排名|流量(?:提升)?|AI引用|人工智能引用))/iu';

    private const UNSUPPORTED_ONTOLOGY_PATTERN = '/(?:(?:everyone|every\s+person|all\s+people|each\s+person)[^.!?。！？\n]{0,50}(?:one|a\s+single)\s+fixed\s+(?:enneagram\s+)?type|(?:one|a\s+single)\s+fixed\s+(?:enneagram\s+)?type\s+(?:per|for)\s+(?:person|everyone)|universal\s+nine[\s-]+factor\s+(?:recovery|structure|model)|每个人[^。！？\n]{0,30}(?:一个)?固定(?:的)?(?:九型人格)?类型|普遍(?:的)?九因子(?:恢复|结构|模型))/iu';

    private const PREDICTION_PATTERN = '/(?:predict(?:s|ed|ive|or)?(?:\s+(?:your|a|the))?[\s-]+(?:career|job|relationship|partner|income|hiring|salary|turnover|health|admission|legal|financial)(?:[\s-]+(?:success|outcome|fit|performance))?|(?:career|job|relationship|partner|income|hiring|salary|turnover|health|admission|legal|financial)(?:[\s-]+(?:success|outcome|fit|performance))?[\s-]+predict(?:or|ion|ive)|forecast(?:s|ed|ing)?(?:\s+of)?(?:\s+(?:your|a|the))?[\s-]+(?:career|job|relationship|partner|income|hiring|salary|turnover|health|admission|legal|financial)(?:[\s-]+(?:success|outcome|fit|performance))?|(?:career|job|relationship|partner|income|hiring|salary|turnover|health|admission|legal|financial)(?:[\s-]+(?:success|outcome|fit|performance))?[\s-]+forecast(?:s|ed|ing)?|guaranteed?\s+(?:career|job|relationship|income|hiring|salary|turnover|health|admission|legal|financial|outcome)|perfect\s+(?:career|job|partner)|预测(?:你(?:的)?|您(?:的)?|其)?(?:职业|收入|关系|录用|结果|健康|升学|法律|金融|薪资|离职|流失)(?:成功|结果|适配)?|(?:个人)?(?:职业成功|职业|收入|关系|录用|结果|健康|升学|法律|金融|薪资|离职|流失)(?:成功|结果|适配)?预测|保证(?:你(?:的)?|您(?:的)?|其)?(?:职业|收入|关系|录用|结果|健康|升学|法律|金融|薪资|离职|流失)(?:成功|结果)?|最适合的职业|完美伴侣)/iu';

    private const DIAGNOSIS_SCREENING_PATTERN = '/(?:medical\s+diagnos(?:is|e|tic)|clinical\s+diagnos(?:is|e|tic)|diagnos(?:e|es|ed|ing)\s+(?:you|your)|(?:personality|type|ability|health)\s+diagnos(?:is|tic)|diagnostic[\s-]+(?:tool|assessment|test|screen(?:ing)?)|(?:treats?|cures?)\s+(?:your\s+)?(?:condition|disorder|anxiety|depression|health|personality)|hiring[\s-]+(?:fit|suitability|screen(?:ing)?)|job[\s-]+suitability(?:[\s-]+guarantee)?|(?:employment|admission)[\s-]+screening|医疗诊断|临床诊断|诊断(?:你(?:的)?|您(?:的)?|其)(?:性格|人格|类型|能力|健康|结果)?|(?:性格|人格|类型|能力|健康)诊断|(?:治疗|治愈)(?:你(?:的)?|您(?:的)?|其)?(?:疾病|焦虑|抑郁|健康|人格|性格)|招聘适配|录用适配|岗位适配保证|(?:岗位|招聘|录用)胜任力)/iu';

    private const DETERMINISTIC_RECOMMENDATION_PATTERN = '/(?:precise\s+career\s+recommendation|best\s+career\s+for\s+you|perfect\s+job\s+match|complete\s+personalized\s+career\s+recommender|(?:riasec|enneagram|mbti|big\s+five)\s+(?:ranks?|determines?)\s+(?:your\s+)?(?:best\s+)?(?:career|job|income|identity|ability|future)|determines?\s+(?:your\s+)?(?:income|career|job|identity|ability|future)|(?:salary|career|hiring|job|relationship|income|outcome)[\s-]+guarantee|精准职业推荐|最适合(?:你(?:的)?|您(?:的)?|其)?职业|完美(?:工作|职业)匹配|决定(?:你(?:的)?|您(?:的)?|其)?(?:收入|职业|工作|身份|能力|未来)|(?:薪资|职业|录用|工作|关系|收入|结果)保证|(?:big\s*five|riasec|mbti|九型人格)\s*(?:职业)?(?:精准匹配|推荐职业))/iu';

    private const HUMAN_REVIEW_RELEASE_PATTERN = '/(?:\b(?:human|expert|editorial|manual|editorially|manually)[\s-]+reviewed\b|\breviewed\s+by\s+[\p{L}][\p{L}\p{M}.\'-]*(?:\s+[\p{L}][\p{L}\p{M}.\'-]*){0,3}|\b(?:human|expert|editorial|manual)[\s-]+review\s+(?:(?:(?:has|had)\s+(?:been\s+)?)|(?:is|was|were)\s+)?(?:completed|approved|passed|cleared)\b|\bcompleted\s+(?:human|expert|editorial|manual)[\s-]+review\b|\b(?:approved|cleared|eligible|ready)\s+for\s+(?:publication|publishing|release|indexing|indexation)\b|\b(?:(?:approved|cleared|ready)\s+to\s+(?:publish|release|index)|published|indexable|(?:publication|publishing|release|indexing|indexation)[\s-]+ready)\b|\bpublication[\s-]+approved\b|(?:人工|专家|编辑)审核(?:已)?(?:通过|完成|批准)|已由[^。！？\n]{0,30}(?:人工|专家|编辑)审核|已获(?:发布|上线|收录)(?:批准|许可)|(?:已发布|可收录|发布就绪)|(?:发布|上线|收录)(?:获批|已批准|就绪))/iu';

    private const BARE_MEDICAL_CLAIM_PATTERN = '/(?:\bdiagnos(?:is|es)\b|\btreatment\b|\bcure\b|诊断|确诊|治疗|治愈)/iu';

    private const COMPETITOR_PATTERN = '/(?:\btruity\b|enneagram\s+institute)/iu';

    private const GENERIC_EXERCISE_PATTERN = '/(?:for (?:the next )?(?:seven|7|７)[ -]?days?(?:\s+period)?,? (?:notice|observe|journal)|连续(?:七|7|７)天(?:观察|记录|注意))/iu';

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $sourceRegistry
     * @param  array<string, mixed>  $pageClaimMaps
     * @return array<string, mixed>
     */
    public function validateEditorial(array $candidate, array $sourceRegistry, array $pageClaimMaps): array
    {
        $issues = [];
        $add = static function (string $gate, string $code, ?string $assetKey, string $path, string $message) use (&$issues): void {
            $issues[] = [
                'gate' => $gate,
                'code' => $code,
                'asset_key' => $assetKey,
                'path' => $path,
                'message' => $message,
            ];
        };

        $maps = $this->indexedMaps($pageClaimMaps, $add);
        $claims = $this->indexedClaims($sourceRegistry, $add);
        $assets = is_array($candidate['assets'] ?? null) ? array_values($candidate['assets']) : [];

        if (($candidate['schema_version'] ?? null) !== self::EDITORIAL_SCHEMA_VERSION) {
            $add(self::EDITORIAL_GATES[0], 'schema_version_invalid', null, 'schema_version', 'The frozen editorial candidate schema is required.');
        }
        if (($candidate['framework'] ?? null) !== 'enneagram') {
            $add(self::EDITORIAL_GATES[0], 'framework_invalid', null, 'framework', 'The package framework must be enneagram.');
        }
        if (count($assets) !== self::EDITORIAL_TARGET_COUNT) {
            $add(self::EDITORIAL_GATES[0], 'target_count_invalid', null, 'assets', 'The aggregate editorial gate requires exactly 116 locale assets.');
        }

        $indexedAssets = [];
        foreach ($assets as $index => $asset) {
            if (! is_array($asset)) {
                $add(self::EDITORIAL_GATES[0], 'asset_not_object', null, "assets.{$index}", 'Every asset must be an object.');

                continue;
            }
            $key = $this->assetKey($asset);
            if ($key === null) {
                $add(self::EDITORIAL_GATES[0], 'asset_identity_invalid', null, "assets.{$index}", 'Asset locale and identity_key are required.');

                continue;
            }
            if (array_key_exists($key, $indexedAssets)) {
                $add(self::EDITORIAL_GATES[0], 'duplicate_asset_identity', $key, "assets.{$index}", 'A locale identity may appear only once.');
            }
            $indexedAssets[$key] = ['asset' => $asset, 'index' => $index];
        }

        foreach ($maps as $key => $map) {
            if (! array_key_exists($key, $indexedAssets)) {
                $add(self::EDITORIAL_GATES[0], 'target_asset_missing', $key, 'assets', 'The frozen 116-page target is missing.');
            }
        }
        foreach ($indexedAssets as $key => $entry) {
            if (! array_key_exists($key, $maps)) {
                $add(self::EDITORIAL_GATES[0], 'unknown_target_asset', $key, "assets.{$entry['index']}", 'Asset is not in the frozen 116-page target map.');
            }
        }

        $paragraphs = [];
        $sentences = [];
        $typeTemplates = [];
        $faqAnswers = [];
        foreach ($indexedAssets as $key => $entry) {
            $asset = $entry['asset'];
            $path = "assets.{$entry['index']}";
            $map = $maps[$key] ?? null;
            $this->validateShape($asset, $key, $path, $map, $add);
            $this->validateAuthorship($asset, $key, $path, $indexedAssets, $add);
            $this->validateInformationGain($asset, $key, $path, $add);
            $this->validateFaqs($asset, $key, $path, $faqAnswers, $add);
            $this->validateExercise($asset, $key, $path, $add);
            $this->validateAnswerability($asset, $key, $path, $add);
            $this->validateEvidenceAndClaims($asset, $key, $path, $map, $claims, $add);
            $this->validateReviewTruth($asset, $key, $path, $add);
            $this->validateTextSafety($asset, $key, $path, $paragraphs, $sentences, $typeTemplates, $add);
        }

        $qaRows = [];
        foreach ($maps as $key => $map) {
            $assetIssues = array_values(array_filter(
                $issues,
                static fn (array $issue): bool => $issue['asset_key'] === null || $issue['asset_key'] === $key,
            ));
            $gateResults = [];
            foreach (self::EDITORIAL_GATES as $gate) {
                $count = count(array_filter($assetIssues, static fn (array $issue): bool => $issue['gate'] === $gate));
                $gateResults[$gate] = ['status' => $count === 0 ? 'pass' : 'fail', 'issue_count' => $count];
            }
            $qaRows[] = [
                'identity_key' => $map['identity_key'],
                'locale' => $map['locale'],
                'entity_type' => $map['entity_type'],
                'code' => $map['code'],
                'path' => $map['path'],
                'status' => $assetIssues === [] ? 'pass' : 'fail',
                'gates' => $gateResults,
                'issues' => $assetIssues,
            ];
        }

        return [
            'artifact' => self::EDITORIAL_ARTIFACT,
            'schema_version' => 'enneagram_public_authority_v2_editorial_gate_report.v1',
            'status' => $issues === [] ? 'ready_for_human_review' : 'fail_closed',
            'ok' => $issues === [],
            'target_count' => self::EDITORIAL_TARGET_COUNT,
            'qa_row_count' => count($qaRows),
            'qa_rows' => $qaRows,
            'issues' => $issues,
            'automated_gate_passed' => $issues === [],
            'human_review_completed' => false,
            'human_review_passed' => false,
            'publish_eligible' => false,
            'writes_committed' => false,
            'cms_write_attempted' => false,
            'database_mutation_attempted' => false,
            'indexability_mutation_attempted' => false,
            'sitemap_mutation_attempted' => false,
            'llms_mutation_attempted' => false,
            'search_submission_attempted' => false,
            'deploy_attempted' => false,
        ];
    }

    /** @param callable(string, string, ?string, string, string): void $add */
    private function indexedMaps(array $payload, callable $add): array
    {
        $rows = is_array($payload['page_maps'] ?? null) ? $payload['page_maps'] : [];
        $indexed = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row) || ($key = $this->assetKey($row)) === null || isset($indexed[$key])) {
                $add(self::EDITORIAL_GATES[0], 'claim_map_invalid', null, "page_maps.{$index}", 'Each source-ledger map must have one unique locale identity.');

                continue;
            }
            $indexed[$key] = $row;
        }
        if (count($indexed) !== self::EDITORIAL_TARGET_COUNT) {
            $add(self::EDITORIAL_GATES[0], 'claim_map_count_invalid', null, 'page_maps', 'The source ledger must contain exactly 116 unique page maps.');
        }

        return $indexed;
    }

    /** @param callable(string, string, ?string, string, string): void $add */
    private function indexedClaims(array $payload, callable $add): array
    {
        $claims = [];
        foreach (is_array($payload['claims'] ?? null) ? $payload['claims'] : [] as $index => $claim) {
            if (! is_array($claim) || trim((string) ($claim['id'] ?? '')) === '') {
                $add(self::EDITORIAL_GATES[9], 'source_claim_invalid', null, "claims.{$index}", 'Every source-ledger claim needs an id.');

                continue;
            }
            $claims[(string) $claim['id']] = $claim;
        }

        return $claims;
    }

    /** @param array<string, mixed> $asset */
    private function assetKey(array $asset): ?string
    {
        $locale = trim((string) ($asset['locale'] ?? ''));
        $identity = trim((string) ($asset['identity_key'] ?? ''));

        return in_array($locale, ['en', 'zh-CN'], true) && $identity !== '' ? $locale.'|'.$identity : null;
    }

    /** @param array<string, mixed>|null $map @param callable(string, string, ?string, string, string): void $add */
    private function validateShape(array $asset, string $key, string $path, ?array $map, callable $add): void
    {
        foreach (['identity_key', 'locale', 'entity_type', 'code', 'path', 'title', 'answer_first', 'authoring', 'sections', 'faqs', 'observation_exercise', 'answerability', 'claim_ids', 'visible_evidence', 'review_truth', 'release_truth'] as $field) {
            if (! array_key_exists($field, $asset)) {
                $add(self::EDITORIAL_GATES[0], 'asset_field_missing', $key, "{$path}.{$field}", 'Required editorial field is missing.');
            }
        }
        if ($map !== null) {
            foreach (['identity_key', 'locale', 'entity_type', 'code', 'path'] as $field) {
                if (($asset[$field] ?? null) !== ($map[$field] ?? null)) {
                    $add(self::EDITORIAL_GATES[0], 'target_identity_mismatch', $key, "{$path}.{$field}", 'Asset identity must exactly match the frozen target map.');
                }
            }
        }
        if (! is_array($asset['sections'] ?? null) || $asset['sections'] === []) {
            $add(self::EDITORIAL_GATES[0], 'sections_missing', $key, "{$path}.sections", 'Visible sections must be non-empty.');
        } else {
            $minimumBodyLength = ($asset['locale'] ?? null) === 'zh-CN' ? 40 : 80;
            foreach ($asset['sections'] as $index => $section) {
                $heading = is_array($section) ? $section['heading'] ?? null : null;
                $body = is_array($section) ? $section['body'] ?? null : null;
                if ($this->length($heading) < 4 || $this->length($body) < $minimumBodyLength) {
                    $add(self::EDITORIAL_GATES[0], 'section_text_missing', $key, "{$path}.sections.{$index}", 'Every visible section requires a substantive heading and body.');
                }
            }
        }
    }

    /** @param array<string, array{asset: array<string, mixed>, index: int}> $assets @param callable(string, string, ?string, string, string): void $add */
    private function validateAuthorship(array $asset, string $key, string $path, array $assets, callable $add): void
    {
        $authoring = is_array($asset['authoring'] ?? null) ? $asset['authoring'] : [];
        if (($authoring['mode'] ?? null) !== 'independent_original' || array_key_exists('source_locale', $authoring) === false || $authoring['source_locale'] !== null) {
            $add(self::EDITORIAL_GATES[1], 'locale_not_independently_authored', $key, "{$path}.authoring", 'Each locale must declare independent original authorship with no source locale.');
        }
        if ($this->length($authoring['independence_note'] ?? null) < 40) {
            $add(self::EDITORIAL_GATES[1], 'independence_note_insufficient', $key, "{$path}.authoring.independence_note", 'Independent editorial intent must be explicit.');
        }
        $visibleBlocks = [(string) ($asset['title'] ?? ''), (string) ($asset['answer_first'] ?? '')];
        foreach (['sections', 'faqs', 'observation_exercise'] as $field) {
            $this->appendVisibleStrings($visibleBlocks, $asset[$field] ?? null);
        }
        $visibleText = implode("\n", $visibleBlocks);
        if (($asset['locale'] ?? null) === 'zh-CN') {
            $hanCount = preg_match_all('/\p{Han}/u', $visibleText) ?: 0;
            $letterCount = preg_match_all('/\p{L}/u', $visibleText) ?: 0;
            if ($hanCount < 80 || $letterCount === 0 || ($hanCount / $letterCount) < 0.25) {
                $add(self::EDITORIAL_GATES[1], 'locale_not_independently_authored', $key, $path, 'A zh-CN asset must contain substantive Chinese script across its rendered editorial fields.');
            }
        } elseif (($asset['locale'] ?? null) === 'en') {
            $latinCount = preg_match_all('/\p{Latin}/u', $visibleText) ?: 0;
            $letterCount = preg_match_all('/\p{L}/u', $visibleText) ?: 0;
            if ($latinCount < 120 || $letterCount === 0 || ($latinCount / $letterCount) < 0.60) {
                $add(self::EDITORIAL_GATES[1], 'locale_not_independently_authored', $key, $path, 'An en asset must contain substantive Latin-script English content across its rendered editorial fields.');
            }
        }
        $pairLocale = ($asset['locale'] ?? null) === 'en' ? 'zh-CN' : 'en';
        $pairKey = $pairLocale.'|'.($asset['identity_key'] ?? '');
        if (isset($assets[$pairKey])) {
            $outline = array_values(is_array($authoring['outline'] ?? null) ? $authoring['outline'] : []);
            $pairAuthoring = is_array($assets[$pairKey]['asset']['authoring'] ?? null) ? $assets[$pairKey]['asset']['authoring'] : [];
            $pairOutline = array_values(is_array($pairAuthoring['outline'] ?? null) ? $pairAuthoring['outline'] : []);
            if ($outline === [] || $outline === $pairOutline) {
                $add(self::EDITORIAL_GATES[1], 'identical_locale_outline', $key, "{$path}.authoring.outline", 'EN and zh-CN outlines must express independent editorial structure, not a mechanical translation outline.');
            }
        }
    }

    /** @param callable(string, string, ?string, string, string): void $add */
    private function validateInformationGain(array $asset, string $key, string $path, callable $add): void
    {
        $authoring = is_array($asset['authoring'] ?? null) ? $asset['authoring'] : [];
        $signals = is_array($authoring['page_specific_signals'] ?? null) ? $authoring['page_specific_signals'] : [];
        $normalized = array_values(array_unique(array_filter(array_map(fn (mixed $value): string => $this->normalize($value), $signals))));
        if (count($normalized) < 3 || min(array_map('mb_strlen', $normalized ?: [''])) < 18) {
            $add(self::EDITORIAL_GATES[2], 'page_specific_information_insufficient', $key, "{$path}.authoring.page_specific_signals", 'At least three substantive, distinct page-specific observations are required.');
        }
        $minimumAnswerLength = ($asset['locale'] ?? null) === 'zh-CN' ? 60 : 100;
        if ($this->length($asset['answer_first'] ?? null) < $minimumAnswerLength) {
            $add(self::EDITORIAL_GATES[2], 'answer_first_too_shallow', $key, "{$path}.answer_first", 'The answer-first definition must provide substantive page-specific information.');
        }
    }

    /** @param array<string, array{key: string, path: string}> $seen @param callable(string, string, ?string, string, string): void $add */
    private function validateFaqs(array $asset, string $key, string $path, array &$seen, callable $add): void
    {
        $faqs = is_array($asset['faqs'] ?? null) ? $asset['faqs'] : [];
        if (count($faqs) < 3) {
            $add(self::EDITORIAL_GATES[4], 'faq_depth_insufficient', $key, "{$path}.faqs", 'Each page requires at least three page-specific FAQs.');
        }
        foreach ($faqs as $index => $faq) {
            $question = is_array($faq) ? (string) ($faq['question'] ?? '') : '';
            $answer = is_array($faq) ? (string) ($faq['answer'] ?? '') : '';
            if ($this->length($question) < 12 || $this->length($answer) < 60) {
                $add(self::EDITORIAL_GATES[4], 'faq_item_too_shallow', $key, "{$path}.faqs.{$index}", 'FAQ questions and answers must be substantive.');
            }
            $normalized = $this->normalize($answer);
            if ($normalized !== '' && isset($seen[$normalized])) {
                $add(self::EDITORIAL_GATES[4], 'repeated_faq_answer', $key, "{$path}.faqs.{$index}.answer", "FAQ answer repeats {$seen[$normalized]['path']}.");
            }
            if ($normalized !== '') {
                $seen[$normalized] = ['key' => $key, 'path' => "{$path}.faqs.{$index}.answer"];
            }
        }
    }

    /** @param callable(string, string, ?string, string, string): void $add */
    private function validateExercise(array $asset, string $key, string $path, callable $add): void
    {
        $exercise = is_array($asset['observation_exercise'] ?? null) ? $asset['observation_exercise'] : [];
        foreach (['context', 'observable_signal', 'page_specific_signal', 'alternative_explanation', 'reflection_prompt'] as $field) {
            if ($this->length($exercise[$field] ?? null) < 30) {
                $add(self::EDITORIAL_GATES[5], 'exercise_not_page_specific', $key, "{$path}.observation_exercise.{$field}", 'The exercise needs a concrete observable cue, context, alternative, and reflection step.');
            }
        }
        $duration = $exercise['duration_days'] ?? null;
        if (! is_int($duration) || $duration < 1 || $duration > 14) {
            $add(self::EDITORIAL_GATES[5], 'exercise_duration_invalid', $key, "{$path}.observation_exercise.duration_days", 'Exercise duration must be an integer from 1 to 14 days.');
        }
        if (preg_match(self::GENERIC_EXERCISE_PATTERN, json_encode($exercise, JSON_UNESCAPED_UNICODE) ?: '') === 1) {
            $add(self::EDITORIAL_GATES[5], 'generic_seven_day_exercise', $key, "{$path}.observation_exercise", 'A generic seven-day observation prompt is not page-specific.');
        }
    }

    /** @param callable(string, string, ?string, string, string): void $add */
    private function validateAnswerability(array $asset, string $key, string $path, callable $add): void
    {
        $answerability = is_array($asset['answerability'] ?? null) ? $asset['answerability'] : [];
        $questions = is_array($answerability['questions'] ?? null) ? $answerability['questions'] : [];
        $questionAnswers = is_array($answerability['question_answers'] ?? null) ? $answerability['question_answers'] : [];
        $minimumQuestionLength = ($asset['locale'] ?? null) === 'zh-CN' ? 8 : 12;
        $minimumAnswerLength = ($asset['locale'] ?? null) === 'zh-CN' ? 40 : 80;
        $normalizedQuestions = array_values(array_unique(array_filter(array_map(
            fn (mixed $question): string => $this->normalize($question),
            $questions,
        ))));
        if (($answerability['direct_answer_supported'] ?? null) !== true
            || count($normalizedQuestions) < 3
            || min(array_map('mb_strlen', $normalizedQuestions ?: [''])) < $minimumQuestionLength) {
            $add(self::EDITORIAL_GATES[6], 'geo_answerability_insufficient', $key, "{$path}.answerability", 'Each page must declare at least three distinct questions supported by visible direct answers.');
        }

        $mappedAnswers = [];
        foreach ($questionAnswers as $mapping) {
            if (! is_array($mapping)) {
                continue;
            }
            $questionKey = $this->normalize($mapping['question'] ?? null);
            $visiblePath = trim((string) ($mapping['visible_path'] ?? ''));
            if ($questionKey !== '' && $visiblePath !== '') {
                $mappedAnswers[$questionKey] = $visiblePath;
            }
        }
        $mappingInvalid = count($mappedAnswers) !== count($normalizedQuestions);
        foreach ($normalizedQuestions as $question) {
            $visibleAnswer = isset($mappedAnswers[$question]) ? $this->visibleAnswerAtPath($asset, $mappedAnswers[$question]) : null;
            if ($this->length($visibleAnswer) < $minimumAnswerLength) {
                $mappingInvalid = true;
                break;
            }
        }
        if ($mappingInvalid) {
            $add(self::EDITORIAL_GATES[6], 'geo_answerability_unverified', $key, "{$path}.answerability.question_answers", 'Every declared question must map to a substantive visible answer-first, section body, or FAQ answer.');
        }
    }

    /** @param array<string, mixed>|null $map @param array<string, array<string, mixed>> $claims @param callable(string, string, ?string, string, string): void $add */
    private function validateEvidenceAndClaims(array $asset, string $key, string $path, ?array $map, array $claims, callable $add): void
    {
        $evidence = is_array($asset['visible_evidence'] ?? null) ? $asset['visible_evidence'] : [];
        $assetClaimIds = array_values(array_unique(array_map('strval', is_array($asset['claim_ids'] ?? null) ? $asset['claim_ids'] : [])));
        $visibleClaimIds = array_values(array_unique(array_map('strval', is_array($evidence['claim_ids'] ?? null) ? $evidence['claim_ids'] : [])));
        $limitations = array_values(array_filter(
            is_array($evidence['limitations'] ?? null) ? $evidence['limitations'] : [],
            fn (mixed $limitation): bool => $this->length($limitation) >= 30,
        ));
        if (($evidence['visible'] ?? null) !== true || count($limitations) < 2) {
            $add(self::EDITORIAL_GATES[7], 'visible_evidence_or_limitations_missing', $key, "{$path}.visible_evidence", 'Evidence and at least two limitations must be visible in the candidate content.');
        }
        if ($map === null) {
            return;
        }
        $requiredLimitations = array_values(array_filter(array_map(
            fn (mixed $limitation): string => $this->normalize($limitation),
            is_array($map['limitations'] ?? null) ? $map['limitations'] : [],
        )));
        $visibleLimitations = array_map(
            fn (mixed $limitation): string => $this->normalize($limitation),
            $limitations,
        );
        foreach ($requiredLimitations as $requiredLimitation) {
            if (! in_array($requiredLimitation, $visibleLimitations, true)) {
                $add(self::EDITORIAL_GATES[7], 'mapped_limitation_hidden', $key, "{$path}.visible_evidence.limitations", 'A page limitation required by the source ledger is absent from visible evidence.');
            }
        }
        $requiredFactual = array_values(array_map('strval', is_array($map['factual_claim_ids'] ?? null) ? $map['factual_claim_ids'] : []));
        foreach ($requiredFactual as $claimId) {
            if (! in_array($claimId, $assetClaimIds, true)) {
                $add(self::EDITORIAL_GATES[9], 'mapped_factual_claim_missing', $key, "{$path}.claim_ids", "Required mapped claim {$claimId} is missing.");
            }
            if (! in_array($claimId, $visibleClaimIds, true)) {
                $add(self::EDITORIAL_GATES[7], 'factual_claim_hidden', $key, "{$path}.visible_evidence.claim_ids", "Factual claim {$claimId} is not represented in visible evidence.");
            }
        }
        $permitted = array_values(array_filter(
            array_map('strval', is_array($map['claim_ids'] ?? null) ? $map['claim_ids'] : []),
            static fn (string $claimId): bool => ($claims[$claimId]['allowed_as_public_claim'] ?? false) === true,
        ));
        foreach ($assetClaimIds as $claimId) {
            if (! isset($claims[$claimId])) {
                $add(self::EDITORIAL_GATES[9], 'claim_unknown', $key, "{$path}.claim_ids", "Claim {$claimId} is not in the source registry.");
            } elseif (! in_array($claimId, $permitted, true)) {
                $add(self::EDITORIAL_GATES[9], 'claim_not_authorized_for_page', $key, "{$path}.claim_ids", "Claim {$claimId} is blocked or not authorized for this page.");
            }
        }
        foreach ($visibleClaimIds as $claimId) {
            if (! in_array($claimId, $assetClaimIds, true)) {
                $add(self::EDITORIAL_GATES[9], 'visible_claim_not_declared', $key, "{$path}.visible_evidence.claim_ids", "Visible evidence claim {$claimId} is absent from the page claim declaration.");
            }
            if (! isset($claims[$claimId]) || ! in_array($claimId, $permitted, true)) {
                $add(self::EDITORIAL_GATES[9], 'visible_claim_not_authorized', $key, "{$path}.visible_evidence.claim_ids", "Visible evidence claim {$claimId} is unknown, blocked, or unauthorized for this page.");
            }
        }
    }

    /** @param callable(string, string, ?string, string, string): void $add */
    private function validateReviewTruth(array $asset, string $key, string $path, callable $add): void
    {
        $review = is_array($asset['review_truth'] ?? null) ? $asset['review_truth'] : [];
        $expectedReview = [
            'status' => 'pending_manual_review',
            'reviewer' => null,
            'reviewed_at' => null,
            'human_review_completed' => false,
            'review_source' => 'unassigned',
        ];
        foreach ($expectedReview as $field => $expected) {
            if (! array_key_exists($field, $review) || $review[$field] !== $expected) {
                $add(self::EDITORIAL_GATES[8], 'manual_review_truth_invalid', $key, "{$path}.review_truth.{$field}", 'Automated or model QA must not be presented as completed human review.');
            }
        }
        foreach (array_diff(array_keys($review), array_keys($expectedReview)) as $field) {
            $add(self::EDITORIAL_GATES[8], 'manual_review_truth_invalid', $key, "{$path}.review_truth.{$field}", 'Review truth is a closed schema; undeclared approval metadata is forbidden.');
        }
        $release = is_array($asset['release_truth'] ?? null) ? $asset['release_truth'] : [];
        $expectedRelease = [
            'draft_only' => true,
            'publish_eligible' => false,
            'indexability_changed' => false,
            'sitemap_changed' => false,
            'llms_changed' => false,
        ];
        foreach ($expectedRelease as $field => $expected) {
            if (! array_key_exists($field, $release) || $release[$field] !== $expected) {
                $add(self::EDITORIAL_GATES[8], 'release_truth_invalid', $key, "{$path}.release_truth.{$field}", 'Editorial QA must leave release and discoverability gates closed.');
            }
        }
        foreach (array_diff(array_keys($release), array_keys($expectedRelease)) as $field) {
            $add(self::EDITORIAL_GATES[8], 'release_truth_invalid', $key, "{$path}.release_truth.{$field}", 'Release truth is a closed schema; undeclared publication metadata is forbidden.');
        }
    }

    /** @param array<string, array{key: string, path: string}> $paragraphs @param array<string, array{key: string, path: string}> $sentences @param array<string, array{key: string, path: string}> $typeTemplates @param callable(string, string, ?string, string, string): void $add */
    private function validateTextSafety(array $asset, string $key, string $path, array &$paragraphs, array &$sentences, array &$typeTemplates, callable $add): void
    {
        $blocks = [(string) ($asset['title'] ?? ''), (string) ($asset['answer_first'] ?? '')];
        foreach (is_array($asset['sections'] ?? null) ? $asset['sections'] : [] as $section) {
            if (is_array($section)) {
                $blocks[] = (string) ($section['heading'] ?? '');
                $blocks[] = (string) ($section['body'] ?? '');
            }
        }
        foreach (is_array($asset['faqs'] ?? null) ? $asset['faqs'] : [] as $faq) {
            if (is_array($faq)) {
                $blocks[] = (string) ($faq['question'] ?? '');
                $blocks[] = (string) ($faq['answer'] ?? '');
            }
        }
        $duplicateBlocks = $blocks;
        $this->appendVisibleStrings($duplicateBlocks, $asset['observation_exercise'] ?? null);
        $safetyBlocks = $duplicateBlocks;
        foreach (['answerability', 'visible_evidence'] as $visibleField) {
            $this->appendVisibleStrings($safetyBlocks, $asset[$visibleField] ?? null);
        }
        $text = implode("\n", $safetyBlocks);
        $claimText = $this->withoutExplicitNegativeClaims($text);
        foreach ([
            [self::UNSUPPORTED_CLAIM_PATTERN, 'unsupported_science_claim'],
            [self::FERMATMIND_PSYCHOMETRICS_PATTERN, 'unsupported_fermatmind_psychometrics_claim'],
            [self::UNSUPPORTED_CENTER_SYSTEM_PATTERN, 'unsupported_center_system_claim'],
            [self::UNSUPPORTED_DISCOVERABILITY_PATTERN, 'unsupported_discoverability_claim'],
            [self::UNSUPPORTED_ONTOLOGY_PATTERN, 'unsupported_ontology_claim'],
            [self::PREDICTION_PATTERN, 'career_or_relationship_prediction'],
            [self::DETERMINISTIC_RECOMMENDATION_PATTERN, 'deterministic_recommendation_claim'],
            [self::HUMAN_REVIEW_RELEASE_PATTERN, 'visible_review_or_release_claim'],
        ] as [$pattern, $code]) {
            if (preg_match($pattern, $claimText) === 1) {
                $add(self::EDITORIAL_GATES[9], $code, $key, $path, 'Public candidate text crosses the declared evidence or competitor boundary.');
            }
        }
        if (preg_match(self::COMPETITOR_PATTERN, $text) === 1) {
            $add(self::EDITORIAL_GATES[9], 'competitor_language_detected', $key, $path, 'Public candidate text crosses the declared evidence or competitor boundary.');
        }
        if ($this->containsUnboundedMedicalClaim($text)) {
            $add(self::EDITORIAL_GATES[9], 'diagnosis_or_screening_claim', $key, $path, 'Public candidate text crosses the non-diagnostic and non-treatment product boundary.');
        }

        foreach ($duplicateBlocks as $index => $block) {
            $normalized = $this->normalize($block);
            $paragraphThreshold = ($asset['locale'] ?? null) === 'zh-CN' ? 30 : 80;
            if (mb_strlen($normalized) >= $paragraphThreshold) {
                if (isset($paragraphs[$normalized])) {
                    $add(self::EDITORIAL_GATES[3], 'duplicate_paragraph', $key, "{$path}.text.{$index}", "Paragraph duplicates {$paragraphs[$normalized]['path']}.");
                }
                $paragraphs[$normalized] = ['key' => $key, 'path' => "{$path}.text.{$index}"];

                $template = $this->normalizeTemplate($block, $asset);
                if (isset($typeTemplates[$template])) {
                    $add(self::EDITORIAL_GATES[3], 'type_number_substitution_template', $key, "{$path}.text.{$index}", "Text differs from {$typeTemplates[$template]['path']} only by a type label or number.");
                }
                $typeTemplates[$template] = ['key' => $key, 'path' => "{$path}.text.{$index}"];
            }

            foreach (preg_split('/(?<=[.!?。！？])\s*/u', $block, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $sentence) {
                $normalizedSentence = $this->normalize($sentence);
                $sentenceThreshold = ($asset['locale'] ?? null) === 'zh-CN' ? 24 : 50;
                if (mb_strlen($normalizedSentence) < $sentenceThreshold) {
                    continue;
                }
                if (isset($sentences[$normalizedSentence])) {
                    $add(self::EDITORIAL_GATES[3], 'duplicate_sentence', $key, "{$path}.text.{$index}", "Sentence duplicates {$sentences[$normalizedSentence]['path']}.");
                }
                $sentences[$normalizedSentence] = ['key' => $key, 'path' => "{$path}.text.{$index}"];
            }
        }
    }

    private function normalize(mixed $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim((string) $value))) ?? '';
    }

    /** @param array<string, mixed> $asset */
    private function normalizeTemplate(string $value, array $asset): string
    {
        $prepared = preg_replace(
            '/(?<![\p{L}\p{N}])(?:[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}|[0-9a-f]{8,64})(?![\p{L}\p{N}])/iu',
            ' {marker} ',
            $value,
        ) ?? $value;
        $markers = [];
        foreach (['identity_key', 'code', 'path'] as $field) {
            $marker = is_string($asset[$field] ?? null) ? trim($asset[$field]) : '';
            if ($marker === '') {
                continue;
            }
            $markers[] = $marker;
            if ($field === 'path') {
                $markers[] = basename($marker);
            }
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $marker, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $component) {
                if (mb_strlen($component) >= 3 && ! in_array(mb_strtolower($component), ['enneagram', 'personality', 'core', 'type', 'center', 'wing', 'instinctual', 'subtype', 'hub'], true)) {
                    $markers[] = $component;
                }
            }
        }
        usort($markers, fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));
        foreach (array_unique($markers) as $marker) {
            $prepared = preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($marker, '/').'(?![\p{L}\p{N}])/iu', ' {marker} ', $prepared) ?? $prepared;
        }

        $normalized = $this->normalize($prepared);

        return preg_replace('/(?:type(?:[1-9]|one|two|three|four|five|six|seven|eight|nine)|ones|twos|threes|fours|fives|sixes|sevens|eights|nines|[1-9]w[1-9]|(?:sp|so|sx)[-_]?[1-9]|第?(?:[1-9]|[一二三四五六七八九])型)/iu', '{type}', $normalized) ?? $normalized;
    }

    private function length(mixed $value): int
    {
        return mb_strlen(trim((string) $value));
    }

    /** @param array<string, mixed> $asset */
    private function visibleAnswerAtPath(array $asset, string $path): ?string
    {
        if ($path === 'answer_first') {
            return is_string($asset['answer_first'] ?? null) ? $asset['answer_first'] : null;
        }
        if (preg_match('/^(?:sections\.\d+\.body|faqs\.\d+\.answer)$/', $path) !== 1
            || preg_match('/^(sections|faqs)\.(\d+)\.(body|answer)$/', $path, $matches) !== 1) {
            return null;
        }
        $collection = is_array($asset[$matches[1]] ?? null) ? $asset[$matches[1]] : [];
        $row = is_array($collection[(int) $matches[2]] ?? null) ? $collection[(int) $matches[2]] : [];
        $value = $row[$matches[3]] ?? null;

        return is_string($value) ? $value : null;
    }

    private function containsUnboundedMedicalClaim(string $text): bool
    {
        $bounded = $this->withoutExplicitlyNegatedMatches($text, [
            self::DIAGNOSIS_SCREENING_PATTERN,
            self::BARE_MEDICAL_CLAIM_PATTERN,
        ]);

        return preg_match(self::DIAGNOSIS_SCREENING_PATTERN, $bounded) === 1
            || preg_match(self::BARE_MEDICAL_CLAIM_PATTERN, $bounded) === 1;
    }

    private function withoutExplicitNegativeClaims(string $text): string
    {
        return $this->withoutExplicitlyNegatedMatches($text, [
            self::UNSUPPORTED_CLAIM_PATTERN,
            self::FERMATMIND_PSYCHOMETRICS_PATTERN,
            self::UNSUPPORTED_CENTER_SYSTEM_PATTERN,
            self::UNSUPPORTED_DISCOVERABILITY_PATTERN,
            self::UNSUPPORTED_ONTOLOGY_PATTERN,
            self::PREDICTION_PATTERN,
            self::DETERMINISTIC_RECOMMENDATION_PATTERN,
            self::HUMAN_REVIEW_RELEASE_PATTERN,
        ]);
    }

    /** @param list<string> $patterns */
    private function withoutExplicitlyNegatedMatches(string $text, array $patterns): string
    {
        $negatedMatches = [];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] ?? [] as [$claim, $offset]) {
                $prefix = mb_substr(substr($text, 0, $offset), -120);
                if (! $this->hasExplicitNegativePrefix($prefix)) {
                    continue;
                }

                $negatedMatches[] = [$offset, strlen($claim)];
            }
        }

        foreach ($negatedMatches as [$offset, $length]) {
            $text = substr_replace($text, str_repeat(' ', $length), $offset, $length);
        }

        return $text;
    }

    private function hasExplicitNegativePrefix(string $prefix): bool
    {
        $commaLedEnglishClause = '/,\s*(?:(?:it|this|that|we|you|they|he|she|there)|(?:an?|the|this|that|these|those|our|your|their|its)\s+[\p{L}\p{N}_\'’\-]+(?:\s+[\p{L}\p{N}_\'’\-]+){0,3}|[\p{L}\p{N}_\'’\-]+(?:\s+[\p{L}\p{N}_\'’\-]+){0,2}\s+(?:is|are|was|were|has|have|had|does|do|did|can|could|will|would|should|must|may|might))\s*$/iu';
        $commaLedProperNounClause = '/,\s*\p{Lu}[\p{L}\p{M}\p{N}_\'’\-]*(?:\s+\p{Lu}[\p{L}\p{M}\p{N}_\'’\-]*){0,3}\s*$/u';
        if (preg_match($commaLedEnglishClause, $prefix) === 1
            || preg_match($commaLedProperNounClause, $prefix) === 1) {
            return false;
        }

        $englishNegative = '(?:\b(?:does?|did|is|are|was|were|can|could|will|would|should|must|may|might)\s+not|\bcan(?:not|[\'’]t)|\b(?:does|did|is|are|was|were|could|will|would|should|must|may|might)n[\'’]t|\bnever)';
        $directBridge = '(?:\s+yet)?(?:\s+(?:an?|the))?\s*';
        $roleBridge = '\s+(?:(?:be\s+)?(?:used|treated|presented|described)|serve|function)\s+as(?:\s+(?:an?|the))?\s*';
        $claimBridge = '\s+(?:as|provide|offer)(?:\s+(?:an?|the))?\s*';
        $boundedEnglishScope = '(?!\s+(?:only|merely|just|simply|solely|exactly|necessarily)\b)(?:(?!\b(?:and|also|plus|additionally|furthermore|moreover|then|while|whereas|but|however|yet|before|after|because|although|though|unless|until|if|when|where|which|who|whose|that|so)\b|,\s*(?:it|this|that|the\s+page|we|you|they|he|she|there)\b|[.!?;:\n]).){0,100}';
        $chineseNegative = '(?:并非|不是|不能|不会|不应|不得把|不得|禁止|不把|不用于)';
        $boundedChineseScope = '(?!只|仅|只是|仅仅)(?:(?!并且|而且|同时|也|还|又|此外|另外|然后|但|然而|却|可是|[，,]\s*(?:(?:本页|本内容|该页|该内容|它|其|我们|你|您|他们)|(?:会|能|可以|能够|已经|已|将|预测|证明|提供|给出|批准|适合))|[。！？；：\n]).){0,40}';

        return preg_match('/'.$englishNegative.'(?:'.$directBridge.'|'.$roleBridge.'|'.$claimBridge.'|'.$boundedEnglishScope.')$/iu', $prefix) === 1
            || preg_match('/'.$chineseNegative.$boundedChineseScope.'$/u', $prefix) === 1;
    }

    /** @param list<string> $blocks */
    private function appendVisibleStrings(array &$blocks, mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $child) {
                $this->appendVisibleStrings($blocks, $child);
            }

            return;
        }
        if (is_string($value) && trim($value) !== '') {
            $blocks[] = $value;
        }
    }
}
