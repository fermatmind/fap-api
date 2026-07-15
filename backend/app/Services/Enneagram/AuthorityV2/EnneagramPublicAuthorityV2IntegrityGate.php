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

    private const UNSUPPORTED_CLAIM_PATTERN = '/(?:scientifically proven|neuroscience proves|clinically validated|absolute(?:ly)? accurate|most accurate personality test|科学(?:已)?证明|神经科学证明|临床验证|绝对准确)/iu';

    private const PREDICTION_PATTERN = '/(?:predict(?:s|ed|ive|or)?(?:\s+(?:your|a|the))?[\s-]+(?:career|job|relationship|partner|income|hiring)(?:[\s-]+(?:success|outcome|fit))?|(?:career|job|relationship|partner|income|hiring)[\s-]+(?:success[\s-]+)?predict(?:or|ion|ive)|guaranteed?\s+(?:career|job|relationship|income|outcome)|perfect\s+(?:career|job|partner)|预测(?:你(?:的)?|您(?:的)?|其)?(?:职业|收入|关系|录用|结果)(?:成功|结果|适配)?|保证(?:你(?:的)?|您(?:的)?|其)?(?:职业|收入|关系|录用|结果)(?:成功|结果)?|最适合的职业|完美伴侣)/iu';

    private const COMPETITOR_PATTERN = '/(?:\btruity\b|enneagram\s+institute)/iu';

    private const GENERIC_EXERCISE_PATTERN = '/(?:for (?:the next )?seven days,? (?:notice|observe|journal)|连续七天(?:观察|记录|注意))/iu';

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
        $minimumQuestionLength = ($asset['locale'] ?? null) === 'zh-CN' ? 8 : 12;
        $normalizedQuestions = array_values(array_unique(array_filter(array_map(
            fn (mixed $question): string => $this->normalize($question),
            $questions,
        ))));
        if (($answerability['direct_answer_supported'] ?? null) !== true
            || count($normalizedQuestions) < 3
            || min(array_map('mb_strlen', $normalizedQuestions ?: [''])) < $minimumQuestionLength) {
            $add(self::EDITORIAL_GATES[6], 'geo_answerability_insufficient', $key, "{$path}.answerability", 'Each page must declare at least three distinct questions supported by visible direct answers.');
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
        foreach ([
            [self::UNSUPPORTED_CLAIM_PATTERN, 'unsupported_science_claim'],
            [self::PREDICTION_PATTERN, 'career_or_relationship_prediction'],
            [self::COMPETITOR_PATTERN, 'competitor_language_detected'],
        ] as [$pattern, $code]) {
            if (preg_match($pattern, $text) === 1) {
                $add(self::EDITORIAL_GATES[9], $code, $key, $path, 'Public candidate text crosses the declared evidence or competitor boundary.');
            }
        }

        foreach ($duplicateBlocks as $index => $block) {
            $normalized = $this->normalize($block);
            if (mb_strlen($normalized) >= 80) {
                if (isset($paragraphs[$normalized])) {
                    $add(self::EDITORIAL_GATES[3], 'duplicate_paragraph', $key, "{$path}.text.{$index}", "Paragraph duplicates {$paragraphs[$normalized]['path']}.");
                }
                $paragraphs[$normalized] = ['key' => $key, 'path' => "{$path}.text.{$index}"];

                $template = preg_replace('/(?:type(?:[1-9]|one|two|three|four|five|six|seven|eight|nine)|[1-9]w[1-9]|(?:sp|so|sx)[-_]?[1-9]|第?(?:[1-9]|[一二三四五六七八九])型)/iu', '{type}', $normalized) ?? $normalized;
                if (isset($typeTemplates[$template])) {
                    $add(self::EDITORIAL_GATES[3], 'type_number_substitution_template', $key, "{$path}.text.{$index}", "Text differs from {$typeTemplates[$template]['path']} only by a type label or number.");
                }
                $typeTemplates[$template] = ['key' => $key, 'path' => "{$path}.text.{$index}"];
            }

            foreach (preg_split('/(?<=[.!?。！？])\s*/u', $block, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $sentence) {
                $normalizedSentence = $this->normalize($sentence);
                if (mb_strlen($normalizedSentence) < 50) {
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

    private function length(mixed $value): int
    {
        return mb_strlen(trim((string) $value));
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
