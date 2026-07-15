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
}
