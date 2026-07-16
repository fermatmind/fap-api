<?php

declare(strict_types=1);

namespace App\Services\Enneagram\AuthorityV2;

use JsonException;
use RuntimeException;

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

    private const UNSUPPORTED_CLAIM_PATTERN = '/(?:(?:scientifically|clinically)[\s-]+(?:proven|validated)|clinical[\s-]+utility|neuroscience proves|absolute(?:ly)? accurate|most accurate personality test|(?:global(?:ly)?|worldwide|world[\'’]?s)\s+(?:first|best|most\s+accurate)\b|(?:科学|临床)(?:已)?(?:证明|验证)|临床效用|神经科学证明|绝对准确|全球(?:第一|首个|最好|最佳|最准确))/iu';

    private const FERMATMIND_PSYCHOMETRICS_PATTERN = '/(?:(?:fermatmind|费马测试|费马测评)[^.!?。！？\n]{0,80}(?:reliab(?:ility|le)|valid(?:ity|ated)|norms?|percentiles?|信度|效度|常模|百分位)|(?:reliab(?:ility|le)|valid(?:ity|ated)|norms?|percentiles?|信度|效度|常模|百分位)[^.!?。！？\n]{0,80}(?:fermatmind|费马测试|费马测评))/iu';

    private const FERMATMIND_EVIDENCE_TRANSFER_PATTERN = '/(?:(?:transfer(?:s|red)?|appl(?:y|ies|ied)|generaliz(?:e|es|ed))[^.!?。！？\n]{0,50}(?:to\s+)?(?:fermatmind|费马测试|费马测评)[^.!?。！？\n]{0,30}(?:scores?|translations?|individual[\s-]+interpretations?)|(?:转移|适用|推广)到?(?:费马测试|费马测评)[^。！？\n]{0,30}(?:分数|得分|翻译|个体解释))/iu';

    private const UNSUPPORTED_EQUIVALENCE_PATTERN = '/(?:(?:instrument|scale)[\s-]*(?:equivalence|equivalent)|equivalent[\s-]+to[\s-]+(?:rheti|another[\s-]+instrument)|(?:工具|量表)(?:等价性?|等效性?)|等价于(?:rheti|其他量表)|(?:enneagram[\s-]+)?wings?[\s-]+validity|validated[\s-]+subtypes?|翼型效度|经过验证的副型|(?:instinctual[\s-]+)?subtypes?[^.!?。！？\n]{0,40}(?:universality|universal|cross[\s-]+cultural[\s-]+equivalence)|universal[\s-]+subtype[\s-]+ontology|(?:本能)?副型[^。！？\n]{0,30}(?:普遍性|普遍有效|跨文化等价性?)|普遍(?:性)?副型本体)/iu';

    private const UNSUPPORTED_CENTER_SYSTEM_PATTERN = '/(?:(?:biological|diagnostic|neurological|neuroscience)[\s-]+(?:systems?|categories?|capacities?)|fixed[\s-]+capacit(?:y|ies)|(?:生物|诊断|神经|神经科学)(?:系统|类别|能力)|固定(?:能力|容量))/iu';

    private const UNSUPPORTED_DISCOVERABILITY_PATTERN = '/(?:(?:guarantee(?:d|s|ing)?|ensure[sd]?|deliver(?:s|ed)?|boost(?:s|ed)?|increase[sd]?|improve[sd]?)[^.!?。！？\n]{0,60}(?:search[\s-]+rankings?|traffic(?:[\s-]+lift)?|ai[\s-]+citations?|citation[\s-]+outcomes?)|(?:search[\s-]+rankings?|traffic[\s-]+lift|ai[\s-]+citations?|citation[\s-]+outcomes?)[^.!?。！？\n]{0,30}(?:guarantee(?:d)?|assured)|(?:保证|确保|提升|增加)[^。！？\n]{0,30}(?:搜索排名|流量(?:提升)?|AI引用|人工智能引用))/iu';

    private const UNSUPPORTED_ONTOLOGY_PATTERN = '/(?:(?:everyone|every\s+person|all\s+people|each\s+person)[^.!?。！？\n]{0,50}(?:one|a\s+single)\s+fixed\s+(?:enneagram\s+)?type|(?:one|a\s+single)\s+fixed\s+(?:enneagram\s+)?type\s+(?:per|for)\s+(?:person|everyone)|fixed[\s-]+identity|universal\s+nine[\s-]+factor\s+(?:recovery|structure|model)|每个人[^。！？\n]{0,30}(?:一个)?固定(?:的)?(?:九型人格)?类型|固定(?:的)?身份|普遍(?:的)?九因子(?:恢复|结构|模型))/iu';

    private const PREDICTION_PATTERN = '/(?:predict(?:s|ed|ive|or)?(?:\s+(?:your|a|the))?[\s-]+(?:career|job|relationship|partner|income|hiring|salary|turnover|health|admission|legal|financial)(?:[\s-]+(?:success|outcome|fit|performance))?|predictions?[\s-]+(?:of|for)(?:\s+(?:your|a|the))?[\s-]+(?:career|job|relationship|partner|income|hiring|salary|turnover|health|admission|legal|financial)(?:[\s-]+(?:success|outcomes?|fit|performance))?|(?:career|job|relationship|partner|income|hiring|salary|turnover|health|admission|legal|financial)(?:[\s-]+(?:success|outcome|fit|performance))?[\s-]+predict(?:or|ion|ive)|forecast(?:s|ed|ing)?(?:\s+of)?(?:\s+(?:your|a|the))?[\s-]+(?:career|job|relationship|partner|income|hiring|salary|turnover|health|admission|legal|financial)(?:[\s-]+(?:success|outcome|fit|performance))?|(?:career|job|relationship|partner|income|hiring|salary|turnover|health|admission|legal|financial)(?:[\s-]+(?:success|outcome|fit|performance))?[\s-]+forecast(?:s|ed|ing)?|guarantee(?:d|s|ing)?\s+(?:career|job|relationship|income|hiring|salary|turnover|health|admission|legal|financial|outcome)(?:[\s-]+(?:success|outcome|fit|performance))?|perfect\s+(?:career|job|partner)|预测(?:你(?:的)?|您(?:的)?|其)?(?:职业|收入|关系|录用|结果|健康|升学|法律|金融|薪资|离职|流失)(?:成功|结果|适配)?|(?:个人)?(?:职业成功|职业|收入|关系|录用|结果|健康|升学|法律|金融|薪资|离职|流失)(?:成功|结果|适配)?预测|保证(?:你(?:的)?|您(?:的)?|其)?(?:职业|收入|关系|录用|结果|健康|升学|法律|金融|薪资|离职|流失)(?:成功|结果)?|最适合的职业|完美伴侣)/iu';

    private const DIAGNOSIS_SCREENING_PATTERN = '/(?:medical\s+diagnos(?:is|e|tic)|clinical\s+diagnos(?:is|e|tic)|diagnos(?:e|es|ed|ing)\s+(?:you|your)|(?:personality|type|ability|health)\s+diagnos(?:is|tic)|diagnostic[\s-]+(?:tool|assessment|test|screen(?:ing)?)|(?:treats?|cures?)\s+(?:your\s+)?(?:condition|disorder|anxiety|depression|health|personality)|hiring[\s-]+(?:fit|suitability|screen(?:ing)?)|job[\s-]+suitability(?:[\s-]+guarantee)?|(?:employment|admission)[\s-]+screening|screen(?:s|ed|ing)?(?:\s+(?:candidates?|applicants?|people|users?))?\s+for\s+(?:hiring|employment|admission)(?:\s+suitability)?|医疗诊断|临床诊断|诊断(?:你(?:的)?|您(?:的)?|其)(?:性格|人格|类型|能力|健康|结果)?|(?:性格|人格|类型|能力|健康)诊断|(?:治疗|治愈)(?:你(?:的)?|您(?:的)?|其)?(?:疾病|焦虑|抑郁|健康|人格|性格)|招聘适配|录用适配|岗位适配保证|(?:岗位|招聘|录用)胜任力)/iu';

    private const DETERMINISTIC_RECOMMENDATION_PATTERN = '/(?:precise\s+career\s+recommendation|best\s+career\s+for\s+you|(?:find(?:s|ing)?\s+)?your\s+best\s+career|perfect\s+job\s+match|complete\s+personalized\s+career\s+recommender|(?:riasec|enneagram|mbti|big\s+five)\s+(?:ranks?|determines?)\s+(?:your\s+)?(?:best\s+)?(?:career|job|income|identity|ability|future)|determines?\s+(?:your\s+)?(?:income|career|job|identity|ability|future)|(?:salary|career|hiring|job|relationship|income|outcome|turnover|health|admission|legal|financial)(?:[\s-]+(?:success|fit|outcome|performance))?[\s-]+guarantee(?:d|s)?|精准职业推荐|最适合(?:你(?:的)?|您(?:的)?|其)?职业|完美(?:工作|职业)匹配|决定(?:你(?:的)?|您(?:的)?|其)?(?:收入|职业|工作|身份|能力|未来)|(?:薪资|职业|录用|工作|关系|收入|结果)保证|(?:big\s*five|riasec|mbti|九型人格)\s*(?:职业)?(?:精准匹配|推荐职业))/iu';

    private const HUMAN_REVIEW_RELEASE_PATTERN = '/(?:\b(?:human|expert|editor|editorial|manual|editorially|manually)[\s-]+reviewed\b|\b(?:human|expert|editor|editorial|editorially)[\s-]+approved\b|\breviewed\s+by\s+[\p{L}][\p{L}\p{M}.\'-]*(?:\s+[\p{L}][\p{L}\p{M}.\'-]*){0,3}|\b(?:human|expert|editor|editorial|manual)[\s-]+review\s+(?:(?:(?:has|had)\s+(?:been\s+)?)|(?:is|was|were)\s+)?(?:completed|approved|passed|cleared)\b|\b(?:completed|approved|passed|cleared)\s+(?:human|expert|editor|editorial|manual)[\s-]+review\b|\b(?:approved|cleared|eligible|ready)\s+for\s+(?:public\s+)?(?:publication|publishing|release|indexing|indexation)\b|\beligible\s+to\s+(?:publish|release|index)\b|\b(?:(?:approved|cleared|ready)\s+to\s+(?:publish|release|index)|(?:this|the)\s+(?:page|asset|content|draft|guide)\s+(?:is|was|has\s+been)\s+published|published\s+(?:online|publicly|to\s+production)|indexed(?:\s+by\s+[\p{L}\p{N}_-]+)?|indexable|(?:publication|publishing|release|indexing|indexation)[\s-]+ready)\b|\bpublication[\s-]+approved\b|\b(?:(?:already|currently)\s+)?(?:in|on)\s+(?:the\s+)?(?:sitemap|llms(?:\.txt)?)\b|\b(?:schema|sitemap|llms(?:\.txt)?)[\s-]+eligib(?:le|ility)\b|(?:人工|专家|编辑)审核(?:已)?(?:通过|完成|批准)|(?:人工|专家|编辑)(?:已)?批准|已完成(?:人工|专家|编辑)审核|已由[^。！？\n]{0,30}(?:人工|专家|编辑)审核|已获(?:发布|上线|收录)(?:批准|许可)|(?:已发布|可收录|发布就绪|已被(?:搜索|谷歌|Google)?收录)|(?:发布|上线|收录)(?:获批|已批准|就绪)|(?:已)?(?:进入|纳入|列入)\s*(?:站点地图|sitemap|llms(?:\.txt)?)|(?:schema|结构化数据|站点地图|sitemap|llms(?:\.txt)?)(?:已)?(?:具备资格|符合条件|可用|启用))/iu';

    private const CLINICAL_SCREENING_PATTERN = '/(?:\bscreen(?:s|ed|ing)?(?:\s+(?:users?|people|clients?|patients?))?\s+for\s+(?:anxiety|depression|mental[\s-]+health|health|conditions?|disorders?|personality)\b|\b(?:anxiety|depression|mental[\s-]+health|health|clinical|medical|personality)[\s-]+screen(?:ing)?(?:[\s-]+(?:tool|assessment|test))?\b|(?:焦虑|抑郁|心理健康|健康|临床|医疗|人格|性格)(?:筛查|筛检)(?:工具|测评|测试)?)/iu';

    private const PREDICTIVE_OF_OUTCOME_PATTERN = '/\bpredictive\s+of\s+(?:(?:your|a|the)\s+)?(?:career|job|relationship|partner|income|hiring|salary|turnover|health|admission|legal|financial)(?:[\s-]+(?:success|outcomes?|fit|performance))?\b/iu';

    private const MANUAL_APPROVAL_PATTERN = '/\bmanual(?:ly)?[\s-]+approved\b/iu';

    private const BARE_MEDICAL_CLAIM_PATTERN = '/(?:\bdiagnos(?:is|e(?:s|d)?|ing)\b|\btreatment\b|\bcure\b|诊断|确诊|治疗|治愈)/iu';

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
        if ($this->length($asset['title'] ?? null) < 4) {
            $add(self::EDITORIAL_GATES[0], 'title_missing', $key, "{$path}.title", 'Every public editorial asset requires a visible title.');
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
        $seenQuestions = [];
        if (count($faqs) < 3) {
            $add(self::EDITORIAL_GATES[4], 'faq_depth_insufficient', $key, "{$path}.faqs", 'Each page requires at least three page-specific FAQs.');
        }
        foreach ($faqs as $index => $faq) {
            $question = is_array($faq) ? (string) ($faq['question'] ?? '') : '';
            $answer = is_array($faq) ? (string) ($faq['answer'] ?? '') : '';
            if ($this->length($question) < 12 || $this->length($answer) < 60) {
                $add(self::EDITORIAL_GATES[4], 'faq_item_too_shallow', $key, "{$path}.faqs.{$index}", 'FAQ questions and answers must be substantive.');
            }
            $normalizedQuestion = $this->normalize($question);
            if ($normalizedQuestion !== '' && isset($seenQuestions[$normalizedQuestion])) {
                $add(self::EDITORIAL_GATES[4], 'repeated_faq_question', $key, "{$path}.faqs.{$index}.question", "FAQ question repeats {$seenQuestions[$normalizedQuestion]}.");
            }
            if ($normalizedQuestion !== '') {
                $seenQuestions[$normalizedQuestion] = "{$path}.faqs.{$index}.question";
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
        $questionTexts = [];
        foreach ($questions as $question) {
            $normalizedQuestion = $this->normalize($question);
            if ($normalizedQuestion !== '' && ! isset($questionTexts[$normalizedQuestion])) {
                $questionTexts[$normalizedQuestion] = trim((string) $question);
            }
        }
        $normalizedQuestions = array_keys($questionTexts);
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
            if ($this->length($visibleAnswer) < $minimumAnswerLength
                || ! $this->visibleAnswerSupportsQuestion($questionTexts[$question], (string) $visibleAnswer, $asset)) {
                $mappingInvalid = true;
                break;
            }
        }
        if ($mappingInvalid) {
            $add(self::EDITORIAL_GATES[6], 'geo_answerability_unverified', $key, "{$path}.answerability.question_answers", 'Every declared question must map to a substantive visible answer-first, section body, or FAQ answer.');
        }
    }

    /** @param array<string, mixed> $asset */
    private function visibleAnswerSupportsQuestion(string $question, string $answer, array $asset): bool
    {
        $questionTerms = $this->answerabilityLatinTerms($question);
        $answerTerms = array_fill_keys($this->answerabilityLatinTerms($answer), true);
        $markerText = implode(' ', array_map(
            static fn (string $field): string => (string) ($asset[$field] ?? ''),
            ['identity_key', 'code', 'path'],
        ));
        $markerTerms = array_fill_keys($this->answerabilityLatinTerms($markerText), true);
        $relevantTerms = array_values(array_filter(
            $questionTerms,
            static fn (string $term): bool => ! isset($markerTerms[$term])
                && preg_match('/^[0-9a-f]{8,64}$/i', $term) !== 1,
        ));
        $hanPhrases = $this->answerabilityHanPhrases($question, $asset);

        if ($relevantTerms === [] && $hanPhrases === []) {
            return false;
        }

        foreach ($relevantTerms as $term) {
            if (! isset($answerTerms[$term])) {
                return false;
            }
        }

        foreach ($hanPhrases as $phrase) {
            if (mb_strpos($answer, $phrase) === false) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function answerabilityLatinTerms(string $text): array
    {
        $terms = [];
        preg_match_all('/[\p{Latin}\p{N}]{3,}/u', mb_strtolower($text), $wordMatches);
        $stopWords = ['what', 'which', 'when', 'where', 'who', 'whose', 'why', 'how', 'should', 'could', 'would', 'does', 'did', 'are', 'the', 'and', 'for', 'from', 'into', 'with', 'this', 'that', 'these', 'those', 'your', 'our', 'their', 'its', 'can', 'appear', 'appears', 'apply', 'applies', 'define', 'defines'];
        foreach ($wordMatches[0] ?? [] as $term) {
            if (! in_array($term, $stopWords, true)) {
                $terms[] = $term;
            }
        }

        return array_values(array_unique($terms));
    }

    /** @param array<string, mixed> $asset @return list<string> */
    private function answerabilityHanPhrases(string $question, array $asset): array
    {
        foreach (['identity_key', 'code', 'path'] as $field) {
            $marker = (string) ($asset[$field] ?? '');
            if ($marker !== '') {
                $question = str_ireplace($marker, ' ', $question);
            }
        }

        preg_match_all('/\p{Han}{2,}/u', $question, $matches);
        $phrases = [];
        foreach ($matches[0] ?? [] as $phrase) {
            $phrase = preg_replace('/^(?:什么是|如何记录|如何观察|如何|为什么|哪些|哪个|怎样|怎么|请说明|请解释)/u', '', $phrase) ?? $phrase;
            $phrase = preg_replace('/(?:是什么|有哪些|为何|吗|呢)$/u', '', $phrase) ?? $phrase;
            $phrase = preg_replace('/^的+/u', '', $phrase) ?? $phrase;
            if ($this->length($phrase) >= 2) {
                $phrases[] = $phrase;
            }
        }

        return array_values(array_unique($phrases));
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
            [self::FERMATMIND_EVIDENCE_TRANSFER_PATTERN, 'unsupported_fermatmind_evidence_transfer_claim'],
            [self::UNSUPPORTED_EQUIVALENCE_PATTERN, 'unsupported_equivalence_or_validity_claim'],
            [self::UNSUPPORTED_CENTER_SYSTEM_PATTERN, 'unsupported_center_system_claim'],
            [self::UNSUPPORTED_DISCOVERABILITY_PATTERN, 'unsupported_discoverability_claim'],
            [self::UNSUPPORTED_ONTOLOGY_PATTERN, 'unsupported_ontology_claim'],
            [self::PREDICTION_PATTERN, 'career_or_relationship_prediction'],
            [self::PREDICTIVE_OF_OUTCOME_PATTERN, 'career_or_relationship_prediction'],
            [self::DETERMINISTIC_RECOMMENDATION_PATTERN, 'deterministic_recommendation_claim'],
            [self::HUMAN_REVIEW_RELEASE_PATTERN, 'visible_review_or_release_claim'],
            [self::MANUAL_APPROVAL_PATTERN, 'visible_review_or_release_claim'],
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
            }

            $templateThreshold = ($asset['locale'] ?? null) === 'zh-CN' ? 30 : 50;
            if (mb_strlen($normalized) >= $templateThreshold) {
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
        $prepared = preg_replace(
            '/(?<![\p{L}\p{N}])(?:One|Two|Three|Four|Five|Six|Seven|Eight|Nine)(?![\p{L}\p{N}])/u',
            ' {typelabel} ',
            $prepared,
        ) ?? $prepared;
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
            self::CLINICAL_SCREENING_PATTERN,
            self::BARE_MEDICAL_CLAIM_PATTERN,
        ]);

        return preg_match(self::DIAGNOSIS_SCREENING_PATTERN, $bounded) === 1
            || preg_match(self::CLINICAL_SCREENING_PATTERN, $bounded) === 1
            || preg_match(self::BARE_MEDICAL_CLAIM_PATTERN, $bounded) === 1;
    }

    private function withoutExplicitNegativeClaims(string $text): string
    {
        return $this->withoutExplicitlyNegatedMatches($text, [
            self::UNSUPPORTED_CLAIM_PATTERN,
            self::FERMATMIND_PSYCHOMETRICS_PATTERN,
            self::FERMATMIND_EVIDENCE_TRANSFER_PATTERN,
            self::UNSUPPORTED_EQUIVALENCE_PATTERN,
            self::UNSUPPORTED_CENTER_SYSTEM_PATTERN,
            self::UNSUPPORTED_DISCOVERABILITY_PATTERN,
            self::UNSUPPORTED_ONTOLOGY_PATTERN,
            self::PREDICTION_PATTERN,
            self::PREDICTIVE_OF_OUTCOME_PATTERN,
            self::DETERMINISTIC_RECOMMENDATION_PATTERN,
            self::HUMAN_REVIEW_RELEASE_PATTERN,
            self::MANUAL_APPROVAL_PATTERN,
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
                if (! $this->hasExplicitNegativePrefix($prefix, $claim)) {
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

    private function hasExplicitNegativePrefix(string $prefix, string $claim): bool
    {
        $commaLedEnglishClause = '/,\s*(?:(?:and|or)\s+)?(?:(?:it|this|that|we|you|they|he|she|there)|(?:an?|the|this|that|these|those|our|your|their|its)\s+[\p{L}\p{N}_\'’\-]+(?:\s+[\p{L}\p{N}_\'’\-]+){0,3}|[\p{L}\p{N}_\'’\-]+(?:\s+[\p{L}\p{N}_\'’\-]+){0,2}\s+(?:is|are|was|were|has|have|had|does|do|did|can|could|will|would|should|must|may|might))\s*$/iu';
        $commaLedProperNounClause = '/,\s*(?:(?:and|or)\s+)?\p{Lu}[\p{L}\p{M}\p{N}_\'’\-]*(?:\s+\p{Lu}[\p{L}\p{M}\p{N}_\'’\-]*){0,3}\s*$/u';
        $commaLedPredicateClause = preg_match('/,\s*$/u', $prefix) === 1
            && preg_match('/^(?:predicts|predictive|forecasts|guarantees|determines|screens|diagnoses|treats|cures|ensures|delivers|boosts|increases|improves)\b/iu', ltrim($claim)) === 1;
        $commaLedReviewOrReleaseClause = preg_match('/,\s*$/u', $prefix) === 1
            && (preg_match(self::HUMAN_REVIEW_RELEASE_PATTERN, ltrim($claim)) === 1
                || preg_match(self::MANUAL_APPROVAL_PATTERN, ltrim($claim)) === 1);
        $disjunctiveEnglishClause = '/\bor\s+(?:(?:it|this|that|we|you|they|he|she|there)|(?:an?|the|this|that|these|those|our|your|their|its)\s+[\p{L}\p{N}_\'’\-]+(?:\s+[\p{L}\p{N}_\'’\-]+){0,3}|[\p{L}\p{N}_\'’\-]+(?:\s+[\p{L}\p{N}_\'’\-]+){0,2}\s+(?:is|are|was|were|has|have|had|does|do|did|can|could|will|would|should|must|may|might))\s*$/iu';
        $disjunctiveChineseClause = '/或(?:者)?\s*(?:(?:本页|本内容|该页|该内容|它|其|我们|你|您|他们)|(?:会|能|可以|能够|已经|已|将|预测|证明|提供|给出|批准|适合))\s*$/u';
        if (preg_match($commaLedEnglishClause, $prefix) === 1
            || preg_match($commaLedProperNounClause, $prefix) === 1
            || $commaLedPredicateClause
            || $commaLedReviewOrReleaseClause
            || preg_match($disjunctiveEnglishClause, $prefix) === 1
            || preg_match($disjunctiveChineseClause, $prefix) === 1) {
            return false;
        }

        $englishNegative = '(?:\b(?:do|does|did|is|are|was|were|has|have|had|can|could|will|would|should|must|may|might)\s+not|\bcan(?:not|[\'’]t)|\b(?:does|did|is|are|was|were|has|have|had|could|will|would|should|must|may|might)n[\'’]t|\bnever)';
        $causalEnglishClause = '/'.$englishNegative.'.*\bas\s+(?:it|this|that|we|you|they|he|she|there|the\s+(?:page|guide|asset|content))\b[^.!?;:\n]*$/iu';
        if (preg_match($causalEnglishClause, $prefix) === 1) {
            return false;
        }

        $directBridge = '(?:\s+yet)?(?:\s+(?:an?|the))?\s*';
        $roleBridge = '\s+(?:(?:be\s+)?(?:used|treated|presented|described)|serve|function)\s+as(?:\s+(?:an?|the))?\s*';
        $claimBridge = '\s+(?:as|provide|offer)(?:\s+(?:an?|the))?\s*';
        $boundedEnglishScope = '(?!\s+(?:only|merely|just|simply|solely|exactly|necessarily)\b)(?:(?!\b(?:and|also|plus|additionally|furthermore|moreover|then|while|whereas|but|however|yet|before|after|because|since|although|though|unless|until|if|when|where|which|who|whose|that|so)\b|,\s*(?:(?:and|or)\s+)?(?:it|this|that|the\s+page|we|you|they|he|she|there)\b|\s+-\s+|[—–.!?;:\n]).){0,100}';
        $chineseNegative = '(?:并非|不是|不能|不会|不应|不得把|不得|禁止|不把|不用于)';
        $boundedChineseScope = '(?!只|仅|只是|仅仅)(?:(?!并且|而且|同时|也|还|又|此外|另外|然后|但|然而|却|可是|因为|所以|而是|[，,]\s*(?:或(?:者)?)?\s*(?:(?:本页|本内容|该页|该内容|它|其|我们|你|您|他们)|(?:会|能|可以|能够|已经|已|将|预测|证明|提供|给出|批准|适合))|-{2,}|[—–。！？；：\n]).){0,40}';

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

final class EnneagramPublicAuthorityV222ReleaseGate
{
    public const ARTIFACT = 'ENNEAGRAM-PUBLIC-AUTHORITY-V2-RELEASE-GATE-22';

    /** @var list<string> */
    private const ASSET_SOURCES = [
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-hub-centers-09/hub-centers-draft.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-1-family-10/type-1-family-draft.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-2-family-11/type-2-family-draft.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-3-family-12/type-3-family-draft.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-4-family-13/type-4-family-draft.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-5-family-14/type-5-family-draft.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-6-family-15/type-6-family-draft.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-7-family-16/type-7-family-draft.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-8-family-17/type-8-family-draft.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-9-family-18/type-9-family-draft.json',
    ];

    /** @var list<string> */
    private const QA_SOURCES = [
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-hub-centers-09/qa-report.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-1-family-10/qa-report.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-2-family-11/qa-report.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-3-family-12/qa-report.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-4-family-13/qa-report.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-5-family-14/qa-report.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-6-family-15/qa-report.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-7-family-16/qa-report.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-8-family-17/qa-report.json',
        'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-type-9-family-18/qa-report.json',
    ];

    private const PAGE_MAPS = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-source-ledger-07/page-claim-maps.json';

    private const SOURCE_REGISTRY = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-source-ledger-07/source-registry.json';

    private const BENCHMARK = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-benchmark-01/production-scorecard.json';

    private const LINK_GRAPH = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-link-graph-20/link-graph.json';

    private const MEDIA_SPECIFICATIONS = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-media-og-19/media-specifications.json';

    private const MEDIA_MAPPINGS = 'docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-media-og-19/localized-og-mappings.json';

    /** @var list<string> */
    private const HIDDEN_SCHEMA_KEYS = ['json_ld', 'schema', 'structured_data', 'faq_schema'];

    /** @var array<string, int> */
    private const ENTITY_COUNTS = [
        'center' => 6,
        'core_type' => 18,
        'hub' => 2,
        'instinctual_subtype' => 54,
        'wing' => 36,
    ];

    /** @var list<string> */
    private const EXECUTION_BOUNDARY_KEYS = [
        'production_write_executed',
        'database_mutated',
        'cms_mutated',
        'revision_pointer_changed',
        'media_uploaded',
        'cache_revalidated',
        'indexability_changed',
        'sitemap_changed',
        'llms_changed',
        'search_submitted',
        'deployed',
    ];

    public function __construct(
        private readonly EnneagramPublicAuthorityV2IntegrityGate $integrityGate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(string $basePath, string $manualReviewsPath): array
    {
        $errors = [];
        $assetRecords = [];
        $assetsByKey = [];
        $aggregateAssets = [];
        $paths = [];
        $entityCounts = [];
        $localeCounts = [];

        foreach (self::ASSET_SOURCES as $sourcePath) {
            $source = $this->loadJson($basePath, $sourcePath);
            $assets = is_array($source['assets'] ?? null) ? $source['assets'] : [];
            foreach ($assets as $index => $asset) {
                if (! is_array($asset)) {
                    $errors[] = $this->error('asset_not_object', $sourcePath.'#'.$index);

                    continue;
                }

                $assetKey = $this->assetKey($asset);
                if ($assetKey === null) {
                    $errors[] = $this->error('asset_identity_missing', $sourcePath.'#'.$index);

                    continue;
                }
                if (isset($assetsByKey[$assetKey])) {
                    $errors[] = $this->error('duplicate_asset_key', $assetKey);

                    continue;
                }

                $path = (string) ($asset['path'] ?? '');
                if ($path === '' || isset($paths[$path])) {
                    $errors[] = $this->error($path === '' ? 'asset_path_missing' : 'duplicate_asset_path', $assetKey);
                }
                $paths[$path] = true;
                $assetsByKey[$assetKey] = $asset;
                $aggregateAssets[] = $asset;
                $entity = (string) ($asset['entity_type'] ?? '');
                $locale = (string) ($asset['locale'] ?? '');
                $entityCounts[$entity] = ($entityCounts[$entity] ?? 0) + 1;
                $localeCounts[$locale] = ($localeCounts[$locale] ?? 0) + 1;

                $review = is_array($asset['review_truth'] ?? null) ? $asset['review_truth'] : [];
                $release = is_array($asset['release_truth'] ?? null) ? $asset['release_truth'] : [];
                if (($review['status'] ?? null) !== 'pending_manual_review'
                    || ($review['reviewer'] ?? null) !== null
                    || ($review['reviewed_at'] ?? null) !== null
                    || ($review['human_review_completed'] ?? null) !== false) {
                    $errors[] = $this->error('draft_review_truth_invalid', $assetKey);
                }
                if (($release['draft_only'] ?? null) !== true
                    || ($release['publish_eligible'] ?? null) !== false
                    || ($release['indexability_changed'] ?? null) !== false
                    || ($release['sitemap_changed'] ?? null) !== false
                    || ($release['llms_changed'] ?? null) !== false) {
                    $errors[] = $this->error('draft_release_truth_invalid', $assetKey);
                }
                if ($this->isPrivatePath($path)) {
                    $errors[] = $this->error('private_path_detected', $assetKey);
                }
                foreach ($this->hiddenSchemaPaths($asset) as $hiddenSchemaPath) {
                    $errors[] = $this->error('hidden_schema_detected', $assetKey.'.'.$hiddenSchemaPath);
                }

                $assetRecords[] = [
                    'asset_key' => $assetKey,
                    'identity_key' => (string) ($asset['identity_key'] ?? ''),
                    'locale' => $locale,
                    'entity_type' => $entity,
                    'code' => (string) ($asset['code'] ?? ''),
                    'path' => $path,
                    'source_path' => $sourcePath,
                    'asset_sha256' => $this->hashValue($asset),
                ];
            }
        }

        usort($assetRecords, fn (array $left, array $right): int => $left['asset_key'] <=> $right['asset_key']);
        ksort($entityCounts);
        ksort($localeCounts);

        if (count($assetRecords) !== 116 || count(array_unique(array_column($assetRecords, 'identity_key'))) !== 58) {
            $errors[] = $this->error('asset_count_mismatch', 'aggregate');
        }
        if ($entityCounts !== self::ENTITY_COUNTS || $localeCounts !== ['en' => 58, 'zh-CN' => 58]) {
            $errors[] = $this->error('taxonomy_count_mismatch', 'aggregate');
        }

        $pageMapsDocument = $this->loadJson($basePath, self::PAGE_MAPS);
        $pageMaps = is_array($pageMapsDocument['page_maps'] ?? null) ? $pageMapsDocument['page_maps'] : [];
        $pageMapByKey = $this->keyRows($pageMaps, 'page_map', $errors);
        $this->compareKeySets(array_keys($assetsByKey), array_keys($pageMapByKey), 'page_map_key_mismatch', $errors);

        $editorialGate = $this->integrityGate->validateEditorial(
            [
                'schema_version' => EnneagramPublicAuthorityV2IntegrityGate::EDITORIAL_SCHEMA_VERSION,
                'framework' => 'enneagram',
                'assets' => $aggregateAssets,
            ],
            $this->loadJson($basePath, self::SOURCE_REGISTRY),
            $pageMapsDocument,
        );
        $editorialIssues = $this->enrichEditorialIssues(
            is_array($editorialGate['issues'] ?? null) ? $editorialGate['issues'] : [],
            $aggregateAssets,
        );
        foreach ($editorialIssues as $issue) {
            $errors[] = $this->error(
                'editorial_integrity_'.(string) ($issue['code'] ?? 'unknown'),
                (string) ($issue['asset_key'] ?? $issue['path'] ?? 'aggregate'),
            );
        }
        if (($editorialGate['automated_gate_passed'] ?? null) !== true
            || ($editorialGate['qa_row_count'] ?? null) !== 116) {
            $errors[] = $this->error('editorial_integrity_gate_failed', 'aggregate');
        }

        $graphDocument = $this->loadJson($basePath, self::LINK_GRAPH);
        $graphRows = is_array($graphDocument['graph_records'] ?? null) ? $graphDocument['graph_records'] : [];
        $graphByKey = $this->keyRows($graphRows, 'graph', $errors);
        $this->compareKeySets(array_keys($assetsByKey), array_keys($graphByKey), 'graph_key_mismatch', $errors);

        foreach ($assetRecords as $record) {
            $key = $record['asset_key'];
            if (($pageMapByKey[$key]['path'] ?? null) !== $record['path']) {
                $errors[] = $this->error('page_map_path_mismatch', $key);
            }
            if (($graphByKey[$key]['path'] ?? null) !== $record['path']
                || ($graphByKey[$key]['canonical']['path'] ?? null) !== $record['path']) {
                $errors[] = $this->error('graph_path_mismatch', $key);
            }
        }
        $canonicalPaths = array_map(
            static fn (array $row): string => (string) ($row['canonical']['path'] ?? ''),
            $graphRows,
        );
        if (count($canonicalPaths) !== 116
            || in_array('', $canonicalPaths, true)
            || count(array_unique($canonicalPaths)) !== 116) {
            $errors[] = $this->error('canonical_count_mismatch', 'aggregate');
        }

        $qaAssetCount = 0;
        $qaSources = [];
        foreach (self::QA_SOURCES as $qaPath) {
            $qa = $this->loadJson($basePath, $qaPath);
            $finalQa = is_array($qa['final_qa'] ?? null) ? $qa['final_qa'] : [];
            if (($finalQa['status'] ?? null) !== 'pass_for_manual_review_handoff'
                || ($finalQa['asset_specific_issue_count'] ?? null) !== 0
                || ($finalQa['human_review_completed'] ?? null) !== false
                || ($finalQa['publish_eligible'] ?? null) !== false) {
                $errors[] = $this->error('qa_handoff_invalid', $qaPath);
            }
            $qaAssetCount += (int) ($finalQa['asset_count'] ?? 0);
            $qaSources[] = ['path' => $qaPath, 'sha256' => $this->hashFile($basePath, $qaPath)];
        }
        if ($qaAssetCount !== 116) {
            $errors[] = $this->error('qa_asset_count_mismatch', 'aggregate');
        }

        $benchmark = $this->loadJson($basePath, self::BENCHMARK);
        $benchmarkRows = is_array($benchmark['rows'] ?? null) ? $benchmark['rows'] : [];
        $fingerprints = [];
        foreach ($benchmarkRows as $row) {
            if (! is_array($row) || ($key = $this->assetKey($row)) === null) {
                $errors[] = $this->error('benchmark_identity_missing', 'benchmark');

                continue;
            }
            $fingerprints[$key] = [
                'asset_key' => $key,
                'path' => (string) ($row['path'] ?? ''),
                'pre_write_public_sha256' => $this->hashValue($row),
            ];
        }
        ksort($fingerprints);
        $this->compareKeySets(array_keys($assetsByKey), array_keys($fingerprints), 'benchmark_key_mismatch', $errors);
        $discoverabilityCounts = [
            'sitemap' => 0,
            'llms_txt' => 0,
            'llms_full_txt' => 0,
        ];
        foreach ($benchmarkRows as $row) {
            $discoverability = is_array($row['discoverability'] ?? null) ? $row['discoverability'] : [];
            $discoverabilityCounts['sitemap'] += ($discoverability['in_sitemap'] ?? null) === true ? 1 : 0;
            $discoverabilityCounts['llms_txt'] += ($discoverability['in_llms_txt'] ?? null) === true ? 1 : 0;
            $discoverabilityCounts['llms_full_txt'] += ($discoverability['in_llms_full_txt'] ?? null) === true ? 1 : 0;
        }
        if ($discoverabilityCounts !== ['sitemap' => 116, 'llms_txt' => 116, 'llms_full_txt' => 116]) {
            $errors[] = $this->error('discoverability_inventory_mismatch', 'benchmark');
        }

        $mediaSpecifications = $this->loadJson($basePath, self::MEDIA_SPECIFICATIONS);
        $mediaRows = is_array($mediaSpecifications['media_specifications'] ?? null) ? $mediaSpecifications['media_specifications'] : [];
        $mediaMappings = $this->loadJson($basePath, self::MEDIA_MAPPINGS);
        $mappingRows = is_array($mediaMappings['mappings'] ?? null) ? $mediaMappings['mappings'] : [];
        $mappingByKey = $this->keyRows($mappingRows, 'media_mapping', $errors);
        if (count($mediaRows) !== 58 || count($mappingByKey) !== 116) {
            $errors[] = $this->error('media_count_mismatch', 'aggregate');
        }
        $this->compareKeySets(array_keys($assetsByKey), array_keys($mappingByKey), 'media_mapping_key_mismatch', $errors);
        $mediaManifestRecords = [];
        foreach ($mediaRows as $index => $mediaRow) {
            if (! is_array($mediaRow)) {
                $errors[] = $this->error('media_manifest_record_invalid', 'media:'.$index);

                continue;
            }
            $mediaManifestRecords[] = [
                'identity_key' => (string) ($mediaRow['identity_key'] ?? ''),
                'spec_id' => (string) ($mediaRow['spec_id'] ?? ''),
                'record_sha256' => $this->hashValue($mediaRow),
            ];
        }
        usort($mediaManifestRecords, fn (array $left, array $right): int => $left['identity_key'] <=> $right['identity_key']);
        if (count(array_unique(array_column($mediaManifestRecords, 'identity_key'))) !== 58
            || count(array_unique(array_column($mediaManifestRecords, 'spec_id'))) !== 58) {
            $errors[] = $this->error('media_manifest_identity_mismatch', 'aggregate');
        }

        $pendingMediaRights = [];
        foreach ($mappingByKey as $key => $mapping) {
            $review = is_array($mapping['manual_rights_review'] ?? null) ? $mapping['manual_rights_review'] : [];
            if (($review['status'] ?? null) !== 'approved'
                || ($review['approved'] ?? null) !== true
                || ! is_string($review['reviewer'] ?? null)
                || trim((string) $review['reviewer']) === ''
                || ! is_string($review['reviewed_at'] ?? null)
                || trim((string) $review['reviewed_at']) === '') {
                $pendingMediaRights[] = $key;
            }
        }
        sort($pendingMediaRights);

        $manualReviews = $this->loadJson($basePath, $manualReviewsPath);
        $reviewEvidence = $this->validateManualReviews($manualReviews, $assetRecords, $errors);
        $missingReviews = [];
        foreach ($assetRecords as $record) {
            if (! isset($reviewEvidence['valid'][$record['asset_key']])) {
                $missingReviews[] = [
                    'asset_key' => $record['asset_key'],
                    'path' => $record['path'],
                    'asset_sha256' => $record['asset_sha256'],
                    'required_fields' => ['reviewer', 'reviewed_at', 'asset_sha256', 'decision'],
                ];
            }
        }

        $sourceHashes = [];
        foreach (array_merge(
            self::ASSET_SOURCES,
            self::QA_SOURCES,
            [self::PAGE_MAPS, self::SOURCE_REGISTRY, self::BENCHMARK, self::LINK_GRAPH, self::MEDIA_SPECIFICATIONS, self::MEDIA_MAPPINGS]
        ) as $path) {
            $sourceHashes[] = ['path' => $path, 'sha256' => $this->hashFile($basePath, $path)];
        }

        $automatedGatePassed = $errors === [];
        $humanReviewPassed = count($reviewEvidence['valid']) === 116
            && $missingReviews === []
            && $reviewEvidence['rejected'] === [];
        $mediaRightsPassed = $pendingMediaRights === [];
        $releaseEligible = $automatedGatePassed && $humanReviewPassed && $mediaRightsPassed;
        $packageSha = $this->hashValue([
            'asset_records' => $assetRecords,
            'pre_write_public_fingerprints' => array_values($fingerprints),
            'source_hashes' => $sourceHashes,
        ]);

        $status = match (true) {
            ! $automatedGatePassed => 'fail_closed',
            ! $humanReviewPassed => 'hold_missing_human_review',
            ! $mediaRightsPassed => 'hold_missing_media_rights_review',
            default => 'pass',
        };
        $currentBlockers = array_values(array_filter([
            $automatedGatePassed ? null : 'automated_release_gate_failed',
            $humanReviewPassed ? null : 'missing_or_rejected_named_human_review_records',
            $mediaRightsPassed ? null : 'missing_media_rights_review_records',
        ]));

        return [
            'artifact' => self::ARTIFACT,
            'schema_version' => 'enneagram_public_authority_v2_release_gate.v1',
            'status' => $status,
            'decision' => $releaseEligible ? 'PASS' : 'HOLD',
            'ok' => $releaseEligible,
            'automated_gate_passed' => $automatedGatePassed,
            'human_review_passed' => $humanReviewPassed,
            'media_rights_review_passed' => $mediaRightsPassed,
            'release_eligible' => $releaseEligible,
            'package_sha256' => $packageSha,
            'counts' => [
                'identities' => 58,
                'assets' => count($assetRecords),
                'locales' => $localeCounts,
                'entities' => $entityCounts,
                'source_mappings' => count($pageMapByKey),
                'qa_rows' => $qaAssetCount,
                'editorial_integrity_qa_rows' => (int) ($editorialGate['qa_row_count'] ?? 0),
                'graph_records' => count($graphByKey),
                'unique_canonicals' => count(array_unique($canonicalPaths)),
                'media_originals' => count($mediaRows),
                'media_mappings' => count($mappingByKey),
                'pre_write_public_fingerprints' => count($fingerprints),
                'named_human_reviews' => count($reviewEvidence['valid']),
                'approved_human_reviews' => count($reviewEvidence['approved']),
                'rejected_human_reviews' => count($reviewEvidence['rejected']),
                'missing_human_reviews' => count($missingReviews),
                'pending_media_rights_reviews' => count($pendingMediaRights),
            ],
            'collision_preflight' => [
                'status' => $automatedGatePassed ? 'pass' : 'fail',
                'unique_asset_keys' => count($assetsByKey),
                'unique_paths' => count($paths),
                'errors' => $errors,
            ],
            'editorial_integrity_gate' => [
                'status' => (string) ($editorialGate['status'] ?? 'fail_closed'),
                'automated_gate_passed' => ($editorialGate['automated_gate_passed'] ?? null) === true,
                'qa_row_count' => (int) ($editorialGate['qa_row_count'] ?? 0),
                'issue_count' => count($editorialIssues),
                'issues' => $editorialIssues,
                'validates' => [
                    'unsupported_claims',
                    'competitor_as_science',
                    'duplicate_or_template_content',
                    'mechanical_translation',
                    'visible_evidence_and_claim_boundaries',
                ],
            ],
            'discoverability_inventory' => [
                ...$discoverabilityCounts,
                'expected_each' => 116,
                'source' => self::BENCHMARK,
                'mutation_performed' => false,
            ],
            'asset_records' => $assetRecords,
            'pre_write_public_fingerprints' => array_values($fingerprints),
            'manual_review_records' => array_values($reviewEvidence['valid']),
            'missing_human_reviews' => $missingReviews,
            'rejected_human_review_asset_keys' => array_keys($reviewEvidence['rejected']),
            'media_manifest_records' => $mediaManifestRecords,
            'pending_media_rights_review_asset_keys' => $pendingMediaRights,
            'source_hashes' => $sourceHashes,
            'graph_manifest_sha256' => $this->hashFile($basePath, self::LINK_GRAPH),
            'media_manifest_sha256' => $this->hashFile($basePath, self::MEDIA_SPECIFICATIONS),
            'qa_sources' => $qaSources,
            'errors' => $errors,
            'release_boundary' => [
                'manual_review_requirement' => '116/116 named human review records bound to exact asset SHA256',
                'current_blockers' => $currentBlockers,
                'next_authority' => 'operator_supplied_human_review_evidence_then_separate_exact_sha_production_authorization',
                'production_command_executed' => false,
            ],
            'rollback_readiness' => [
                'complete' => true,
                'implementation' => EnneagramPublicAuthorityV206RevisionPromoter::class,
                'token_format' => 'base64url(json).hmac_sha256; payload version, artifact, target_count=116, preflight_fingerprint, and 116 rollback rows',
                'requires_exact_token_sha256_authorization' => true,
                'rollback_command_executed' => false,
            ],
            'exact_production_command_plan' => [
                'authorization_boundary' => 'PR23 separate exact backend SHA, package SHA256, and operator authorization required',
                'preflight' => 'php artisan personality:enneagram-authority-v2-revision-promoter --plan=<reviewed-116-target-plan.json> --preflight --json',
                'promote' => 'php artisan personality:enneagram-authority-v2-revision-promoter --plan=<reviewed-116-target-plan.json> --promote --confirm-preflight-fingerprint=<exact-preflight-sha256> --confirm-writer-deploy-sha=<exact-backend-git-sha> --operator-approved=<exact-dynamic-approval-phrase> --json',
                'rollback' => 'php artisan personality:enneagram-authority-v2-revision-promoter --rollback-token-file=<retained-token-file> --confirm-writer-deploy-sha=<exact-backend-git-sha> --operator-approved=<exact-dynamic-rollback-approval-phrase> --json',
                'executed' => false,
            ],
            'execution_boundaries' => array_fill_keys(self::EXECUTION_BOUNDARY_KEYS, false),
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<array<string, mixed>>  $assetRecords
     * @param  list<array{code:string,subject:string}>  $errors
     * @return array{valid:array<string,array<string,mixed>>,approved:array<string,array<string,mixed>>,rejected:array<string,array<string,mixed>>}
     */
    private function validateManualReviews(array $document, array $assetRecords, array &$errors): array
    {
        $assetHashes = [];
        foreach ($assetRecords as $record) {
            $assetHashes[$record['asset_key']] = $record['asset_sha256'];
        }

        $reviews = is_array($document['reviews'] ?? null) ? $document['reviews'] : [];
        $valid = [];
        $approved = [];
        $rejected = [];
        foreach ($reviews as $index => $review) {
            if (! is_array($review)) {
                $errors[] = $this->error('manual_review_not_object', 'review:'.$index);

                continue;
            }
            $key = (string) ($review['asset_key'] ?? '');
            if ($key === '' || ! isset($assetHashes[$key])) {
                $errors[] = $this->error('manual_review_asset_unknown', $key === '' ? 'review:'.$index : $key);

                continue;
            }
            if (isset($valid[$key])) {
                $errors[] = $this->error('duplicate_manual_review', $key);

                continue;
            }

            $reviewer = trim((string) ($review['reviewer'] ?? ''));
            $reviewedAt = (string) ($review['reviewed_at'] ?? '');
            $decision = (string) ($review['decision'] ?? '');
            if ($reviewer === ''
                || $reviewedAt === ''
                || strtotime($reviewedAt) === false
                || ($review['asset_sha256'] ?? null) !== $assetHashes[$key]
                || ! in_array($decision, ['approved', 'rejected'], true)) {
                $errors[] = $this->error('manual_review_invalid', $key);

                continue;
            }
            $valid[$key] = $review;
            if ($decision === 'approved') {
                $approved[$key] = $review;
            } else {
                $rejected[$key] = $review;
            }
        }

        ksort($valid);
        ksort($approved);
        ksort($rejected);

        return ['valid' => $valid, 'approved' => $approved, 'rejected' => $rejected];
    }

    /**
     * @param  list<mixed>  $rows
     * @param  list<array{code:string,subject:string}>  $errors
     * @return array<string, array<string, mixed>>
     */
    private function keyRows(array $rows, string $source, array &$errors): array
    {
        $keyed = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row) || ($key = $this->assetKey($row)) === null) {
                $errors[] = $this->error($source.'_identity_missing', $source.':'.$index);

                continue;
            }
            if (isset($keyed[$key])) {
                $errors[] = $this->error('duplicate_'.$source.'_key', $key);

                continue;
            }
            $keyed[$key] = $row;
        }

        return $keyed;
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     * @param  list<array{code:string,subject:string}>  $errors
     */
    private function compareKeySets(array $left, array $right, string $code, array &$errors): void
    {
        sort($left);
        sort($right);
        if ($left !== $right) {
            $errors[] = $this->error($code, 'aggregate');
        }
    }

    /** @param array<string, mixed> $row */
    private function assetKey(array $row): ?string
    {
        $locale = trim((string) ($row['locale'] ?? ''));
        $identity = trim((string) ($row['identity_key'] ?? ''));

        return $locale !== '' && $identity !== '' ? $locale.'|'.$identity : null;
    }

    /** @return array<string, mixed> */
    private function loadJson(string $basePath, string $relativePath): array
    {
        $resolved = str_starts_with($relativePath, '/') ? $relativePath : rtrim($basePath, '/').'/'.$relativePath;
        if (! is_file($resolved)) {
            throw new RuntimeException('Release-gate input not found: '.$resolved);
        }
        try {
            $decoded = json_decode((string) file_get_contents($resolved), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Release-gate input is not valid JSON: '.$resolved, 0, $exception);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('Release-gate input must be a JSON object: '.$resolved);
        }

        return $decoded;
    }

    private function hashFile(string $basePath, string $relativePath): string
    {
        $resolved = str_starts_with($relativePath, '/') ? $relativePath : rtrim($basePath, '/').'/'.$relativePath;
        $hash = hash_file('sha256', $resolved);
        if ($hash === false) {
            throw new RuntimeException('Unable to hash release-gate input: '.$resolved);
        }

        return $hash;
    }

    private function hashValue(mixed $value): string
    {
        try {
            return hash('sha256', json_encode(
                $this->normalizeForHash($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to hash release-gate value.', 0, $exception);
        }
    }

    private function normalizeForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->normalizeForHash($item), $value);
    }

    private function isPrivatePath(string $path): bool
    {
        return preg_match('~/(?:attempts?|reports?|results?|orders?|payments?|checkout|account|me)(?:/|$)~i', $path) === 1;
    }

    /** @param array<string, mixed> $value @return list<string> */
    private function hiddenSchemaPaths(array $value, string $prefix = ''): array
    {
        $paths = [];
        foreach ($value as $key => $child) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (in_array((string) $key, self::HIDDEN_SCHEMA_KEYS, true)) {
                $paths[] = $path;
            }
            if (is_array($child)) {
                $paths = array_merge($paths, $this->hiddenSchemaPaths($child, $path));
            }
        }

        return $paths;
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @param  list<array<string, mixed>>  $assets
     * @return list<array<string, mixed>>
     */
    private function enrichEditorialIssues(array $issues, array $assets): array
    {
        foreach ($issues as &$issue) {
            $message = (string) ($issue['message'] ?? '');
            if (preg_match('/duplicates assets\.(\d+)\./', $message, $matches) === 1) {
                $duplicateIndex = (int) $matches[1];
                if (isset($assets[$duplicateIndex])) {
                    $issue['duplicate_of_asset_key'] = $this->assetKey($assets[$duplicateIndex]);
                }
            }
        }
        unset($issue);

        return $issues;
    }

    /** @return array{code:string,subject:string} */
    private function error(string $code, string $subject): array
    {
        return ['code' => $code, 'subject' => $subject];
    }
}
