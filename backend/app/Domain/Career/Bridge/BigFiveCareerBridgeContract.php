<?php

declare(strict_types=1);

namespace App\Domain\Career\Bridge;

use JsonException;
use RuntimeException;

final class BigFiveCareerBridgeContract
{
    public const INPUT_CONTRACT_VERSION = 'big_five_career_bridge.input.v1';

    public const OUTPUT_CONTRACT_VERSION = 'big_five_career_bridge.output.v1';

    public const INPUT_SCHEMA_ID = 'urn:fermatmind:career:big-five-bridge-input:v1';

    public const OUTPUT_SCHEMA_ID = 'urn:fermatmind:career:big-five-bridge-output:v1';

    public const INPUT_SCHEMA_PATH = 'docs/career/contracts/big-five-career-bridge-input.v1.schema.json';

    public const OUTPUT_SCHEMA_PATH = 'docs/career/contracts/big-five-career-bridge-output.v1.schema.json';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_GENERATED_CANDIDATE = 'generated_candidate';

    public const STATUS_PENDING_MANUAL_REVIEW = 'pending_manual_review';

    public const STATUS_PUBLISHED_PROJECTION_READY = 'published_projection_ready';

    public const CLAIM_MODE = 'explanation_only';

    public const BIG_FIVE_ROLE = 'supplementary_work_style_explanation';

    public const PRIMARY_CAREER_SIGNAL = 'riasec';

    public const BIG_FIVE_SOURCE_KIND = 'backend_public_api_published_projection';

    public const SELECTED_REVISION_SOURCE = 'published_revision';

    public const CAREER_PROJECTION_KIND = 'career_runtime_publish_projection';

    public const CAREER_PROJECTION_VERSION = 'career.runtime_publish_projection.v1';

    /** @var list<string> */
    private const INPUT_KEYS = [
        'bridge_contract_version',
        'bridge_id',
        'locale',
        'big_five_asset_identity',
        'big_five_primary_status',
        'big_five_published_revision_id',
        'big_five_public_projection_hash',
        'big_five_claim_permissions',
        'big_five_source_permission',
        'big_five_reviewer_permission',
        'big_five_date_permission',
        'career_canonical_slug',
        'career_runtime_projection_version',
        'career_runtime_projection_hash',
        'career_publish_eligibility',
        'private_data_absent',
        'big_five_projection',
        'career_projection',
        'signal_policy',
        'privacy_boundary',
    ];

    /** @var list<string> */
    private const BIG_FIVE_KEYS = [
        'authority_surface',
        'source_kind',
        'framework',
        'asset_id',
        'locale',
        'primary_status',
        'is_public',
        'published_revision_id',
        'selected_revision_id',
        'selected_revision_source',
        'public_projection_hash',
        'working_revision_id',
        'public_projection_ready',
        'visible_evidence',
        'working_or_draft_revision_used',
        'generated_authority_package_used',
    ];

    /** @var list<string> */
    private const VISIBLE_EVIDENCE_KEYS = [
        'claim_permission',
        'source_permission',
        'reviewer_permission',
        'visible_date_permission',
    ];

    /** @var list<string> */
    private const CAREER_KEYS = [
        'projection_kind',
        'projection_version',
        'projection_hash',
        'occupation_id',
        'canonical_slug',
        'locale',
        'public_resolution_type',
        'runtime_publish_state',
        'detail_route_enabled',
        'dataset_visible',
        'release_gate_pass',
        'publish_eligibility',
        'public_projection_ready',
        'blockers',
    ];

    /** @var list<string> */
    private const SIGNAL_POLICY_KEYS = [
        'primary_career_interest_signal',
        'big_five_role',
        'claim_mode',
        'occupation_ranking_allowed',
        'hiring_use_allowed',
        'outcome_prediction_allowed',
        'pseo_expansion_allowed',
    ];

    /** @var list<string> */
    private const PRIVACY_KEYS = [
        'contains_private_assessment_data',
        'contains_user_identifiers',
        'contains_attempt_or_report_links',
        'contains_order_or_payment_data',
    ];

    /** @var list<string> */
    private const OUTPUT_KEYS = [
        'contract_version',
        'bridge_id',
        'status',
        'claim_mode',
        'public_reader_allowed',
        'source_locks',
        'content',
        'claim_boundary',
        'privacy_boundary',
        'discoverability_changes',
        'blockers',
    ];

    /** @var list<string> */
    private const SOURCE_LOCK_KEYS = [
        'big_five_asset_id',
        'big_five_locale',
        'big_five_published_revision_id',
        'big_five_public_projection_hash',
        'career_occupation_id',
        'career_canonical_slug',
        'career_locale',
        'career_projection_version',
        'career_runtime_projection_hash',
    ];

    /** @var list<string> */
    private const CONTENT_KEYS = [
        'reflection_signals',
        'environment_questions',
        'feedback_and_structure_preferences',
        'possible_friction_cues',
        'exploration_examples',
        'boundary_copy',
    ];

    /** @var list<string> */
    private const CLAIM_BOUNDARY_KEYS = [
        'big_five_role',
        'primary_career_interest_signal',
        'recommendation_authority',
        'ranking_allowed',
        'hiring_use_allowed',
        'pseo_allowed',
    ];

    /** @var list<string> */
    private const FORBIDDEN_CONTENT_KEYS = [
        'fit_score',
        'match_score',
        'occupation_rank',
        'hiring_recommendation',
        'promotion_probability',
        'income_prediction',
        'success_probability',
        'placement_probability',
        'diagnosis',
        'score_vector',
        'percentile',
        'answers',
        'selector_trace',
        'attempt_id',
        'report_url',
        'user_id',
        'order_id',
        'payment_id',
    ];

    /** @var list<string> */
    private const FORBIDDEN_CLAIM_FRAGMENTS = [
        'the best career for you is',
        'you are best suited for',
        'guaranteed career fit',
        'guaranteed to succeed',
        'should be hired',
        'will be promoted',
        'will earn more',
        '最适合你的职业是',
        '你最适合从事',
        '保证职业匹配',
        '保证成功',
        '应该录用',
        '一定会晋升',
        '一定会赚得更多',
    ];

    /**
     * @return array{contract_version: string, status: string, public_reader_allowed: bool, claim_mode: string, blockers: list<string>}
     */
    public function assess(array $input, array $output): array
    {
        $blockers = array_merge(
            $this->validateInput($input),
            $this->validateOutput($output),
            $this->validateSourceLocks($input, $output),
        );

        if (($output['status'] ?? null) !== self::STATUS_PUBLISHED_PROJECTION_READY) {
            $blockers[] = 'output.status_not_published_projection_ready';
        }

        $blockers = $this->uniqueSorted($blockers);
        $ready = $blockers === [];

        return [
            'contract_version' => self::OUTPUT_CONTRACT_VERSION,
            'status' => $ready ? self::STATUS_PUBLISHED_PROJECTION_READY : self::STATUS_BLOCKED,
            'public_reader_allowed' => $ready,
            'claim_mode' => self::CLAIM_MODE,
            'blockers' => $blockers,
        ];
    }

    /** @return list<string> */
    public function validateInput(array $input): array
    {
        $blockers = [];
        $this->validateExactKeys($input, self::INPUT_KEYS, 'input', $blockers);

        $this->requireSame($input['bridge_contract_version'] ?? null, self::INPUT_CONTRACT_VERSION, 'input.bridge_contract_version_invalid', $blockers);
        $this->requireSafeIdentity($input['bridge_id'] ?? null, 'input.bridge_id_invalid', $blockers);
        $this->requireOneOf($input['locale'] ?? null, ['en', 'zh'], 'input.locale_invalid', $blockers);
        $this->requireSafeIdentity($input['big_five_asset_identity'] ?? null, 'input.big_five_asset_identity_invalid', $blockers);
        $this->requireSame($input['big_five_primary_status'] ?? null, 'published', 'input.big_five_primary_status_invalid', $blockers);
        $this->requirePositiveInteger($input['big_five_published_revision_id'] ?? null, 'input.big_five_published_revision_id_invalid', $blockers);
        $this->requireSha256($input['big_five_public_projection_hash'] ?? null, 'input.big_five_public_projection_hash_invalid', $blockers);
        foreach (['big_five_claim_permissions', 'big_five_source_permission', 'big_five_reviewer_permission', 'big_five_date_permission'] as $permission) {
            $this->requireSame($input[$permission] ?? null, true, 'input.'.$permission.'_missing', $blockers);
        }
        $this->requireSlug($input['career_canonical_slug'] ?? null, 'input.career_canonical_slug_invalid', $blockers);
        $this->requireSame($input['career_runtime_projection_version'] ?? null, self::CAREER_PROJECTION_VERSION, 'input.career_runtime_projection_version_invalid', $blockers);
        $this->requireSha256($input['career_runtime_projection_hash'] ?? null, 'input.career_runtime_projection_hash_invalid', $blockers);
        $this->requireSame($input['career_publish_eligibility'] ?? null, true, 'input.career_publish_eligibility_missing', $blockers);
        $this->requireSame($input['private_data_absent'] ?? null, true, 'input.private_data_absence_unproven', $blockers);

        $bigFive = $this->objectAt($input, 'big_five_projection', 'input.big_five_projection_invalid', $blockers);
        $career = $this->objectAt($input, 'career_projection', 'input.career_projection_invalid', $blockers);
        $signalPolicy = $this->objectAt($input, 'signal_policy', 'input.signal_policy_invalid', $blockers);
        $privacy = $this->objectAt($input, 'privacy_boundary', 'input.privacy_boundary_invalid', $blockers);

        $this->validateBigFiveProjection($bigFive, $blockers);
        $this->validateCareerProjection($career, $blockers);
        $this->validateSignalPolicy($signalPolicy, $blockers);
        $this->validatePrivacyBoundary($privacy, 'input.privacy_boundary', $blockers);
        $this->validateLocaleBinding($input, $bigFive, $career, $blockers);
        $this->validateTopLevelBindings($input, $bigFive, $career, $privacy, $blockers);

        $forbiddenKeys = $this->forbiddenKeys($input);
        foreach ($forbiddenKeys as $key) {
            $blockers[] = 'input.forbidden_private_or_ranking_key:'.$key;
        }

        return $this->uniqueSorted($blockers);
    }

    /** @return list<string> */
    public function validateOutput(array $output): array
    {
        $blockers = [];
        $this->validateExactKeys($output, self::OUTPUT_KEYS, 'output', $blockers);

        $this->requireSame($output['contract_version'] ?? null, self::OUTPUT_CONTRACT_VERSION, 'output.contract_version_invalid', $blockers);
        $this->requireSafeIdentity($output['bridge_id'] ?? null, 'output.bridge_id_invalid', $blockers);
        $this->requireOneOf($output['status'] ?? null, [
            self::STATUS_BLOCKED,
            self::STATUS_GENERATED_CANDIDATE,
            self::STATUS_PENDING_MANUAL_REVIEW,
            self::STATUS_PUBLISHED_PROJECTION_READY,
        ], 'output.status_invalid', $blockers);
        $this->requireSame($output['claim_mode'] ?? null, self::CLAIM_MODE, 'output.claim_mode_invalid', $blockers);
        $this->requireBoolean($output['public_reader_allowed'] ?? null, 'output.public_reader_allowed_invalid', $blockers);
        $this->requireSame($output['discoverability_changes'] ?? null, false, 'output.discoverability_changes_must_be_false', $blockers);

        $sourceLocks = $this->objectAt($output, 'source_locks', 'output.source_locks_invalid', $blockers);
        $content = $this->objectAt($output, 'content', 'output.content_invalid', $blockers);
        $claimBoundary = $this->objectAt($output, 'claim_boundary', 'output.claim_boundary_invalid', $blockers);
        $privacy = $this->objectAt($output, 'privacy_boundary', 'output.privacy_boundary_invalid', $blockers);
        $declaredBlockers = $this->stringListAt($output, 'blockers', 'output.blockers_invalid', $blockers, allowEmpty: true);

        $this->validateSourceLockShape($sourceLocks, $blockers);
        $this->validateContent($content, $output['status'] ?? null, $blockers);
        $this->validateClaimBoundary($claimBoundary, $blockers);
        $this->validatePrivacyBoundary($privacy, 'output.privacy_boundary', $blockers);

        if (($output['status'] ?? null) === self::STATUS_PUBLISHED_PROJECTION_READY) {
            $this->requireSame($output['public_reader_allowed'] ?? null, true, 'output.ready_reader_must_be_allowed', $blockers);
            if ($declaredBlockers !== []) {
                $blockers[] = 'output.ready_blockers_must_be_empty';
            }
        }

        if (($output['status'] ?? null) === self::STATUS_BLOCKED) {
            $this->requireSame($output['public_reader_allowed'] ?? null, false, 'output.blocked_reader_must_be_denied', $blockers);
            if ($declaredBlockers === []) {
                $blockers[] = 'output.blocked_requires_reason';
            }
        }

        if (in_array($output['status'] ?? null, [self::STATUS_GENERATED_CANDIDATE, self::STATUS_PENDING_MANUAL_REVIEW], true)) {
            $this->requireSame($output['public_reader_allowed'] ?? null, false, 'output.non_ready_reader_must_be_denied', $blockers);
        }

        foreach ($this->forbiddenKeys($output) as $key) {
            $blockers[] = 'output.forbidden_private_or_ranking_key:'.$key;
        }
        foreach ($this->forbiddenClaimFragments($content) as $fragment) {
            $blockers[] = 'output.deterministic_or_outcome_claim:'.$fragment;
        }

        return $this->uniqueSorted($blockers);
    }

    /** @return array<string, mixed> */
    public function schemaDocument(string $kind): array
    {
        $relativePath = match ($kind) {
            'input' => self::INPUT_SCHEMA_PATH,
            'output' => self::OUTPUT_SCHEMA_PATH,
            default => throw new RuntimeException('Unknown Big Five Career bridge schema kind.'),
        };

        $contents = file_get_contents(base_path($relativePath));
        if ($contents === false) {
            throw new RuntimeException('Unable to read Big Five Career bridge schema.');
        }

        try {
            $schema = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid Big Five Career bridge schema JSON.', previous: $exception);
        }

        if (! is_array($schema)) {
            throw new RuntimeException('Big Five Career bridge schema must decode to an object.');
        }

        return $schema;
    }

    /** @param list<string> $blockers */
    private function validateBigFiveProjection(array $projection, array &$blockers): void
    {
        $this->validateExactKeys($projection, self::BIG_FIVE_KEYS, 'input.big_five_projection', $blockers);
        $this->requireSame($projection['authority_surface'] ?? null, 'personality_public_content_asset', 'input.big_five.authority_surface_invalid', $blockers);
        $this->requireSame($projection['source_kind'] ?? null, self::BIG_FIVE_SOURCE_KIND, 'input.big_five.source_kind_invalid', $blockers);
        $this->requireSame($projection['framework'] ?? null, 'big_five', 'input.big_five.framework_invalid', $blockers);
        $this->requireSafeIdentity($projection['asset_id'] ?? null, 'input.big_five.asset_id_invalid', $blockers);
        $this->requireOneOf($projection['locale'] ?? null, ['en', 'zh-CN'], 'input.big_five.locale_invalid', $blockers);
        $this->requireSame($projection['primary_status'] ?? null, 'published', 'input.big_five.primary_not_published', $blockers);
        $this->requireSame($projection['is_public'] ?? null, true, 'input.big_five.not_public', $blockers);
        $this->requirePositiveInteger($projection['published_revision_id'] ?? null, 'input.big_five.published_revision_missing', $blockers);
        $this->requirePositiveInteger($projection['selected_revision_id'] ?? null, 'input.big_five.selected_revision_missing', $blockers);
        $this->requireSame($projection['selected_revision_source'] ?? null, self::SELECTED_REVISION_SOURCE, 'input.big_five.selected_source_not_published_revision', $blockers);
        $this->requireSha256($projection['public_projection_hash'] ?? null, 'input.big_five.public_projection_hash_invalid', $blockers);
        $this->requireNullablePositiveInteger($projection['working_revision_id'] ?? null, 'input.big_five.working_revision_invalid', $blockers);
        $this->requireSame($projection['public_projection_ready'] ?? null, true, 'input.big_five.public_projection_not_ready', $blockers);
        $this->requireSame($projection['working_or_draft_revision_used'] ?? null, false, 'input.big_five.working_or_draft_revision_used', $blockers);
        $this->requireSame($projection['generated_authority_package_used'] ?? null, false, 'input.big_five.generated_package_used', $blockers);

        if (($projection['published_revision_id'] ?? null) !== ($projection['selected_revision_id'] ?? null)) {
            $blockers[] = 'input.big_five.selected_revision_not_published_revision';
        }
        if (($projection['working_revision_id'] ?? null) !== null
            && ($projection['working_revision_id'] ?? null) === ($projection['selected_revision_id'] ?? null)) {
            $blockers[] = 'input.big_five.selected_revision_aliases_working_revision';
        }

        $visibleEvidence = $this->objectAt($projection, 'visible_evidence', 'input.big_five.visible_evidence_invalid', $blockers);
        $this->validateExactKeys($visibleEvidence, self::VISIBLE_EVIDENCE_KEYS, 'input.big_five.visible_evidence', $blockers);
        foreach (self::VISIBLE_EVIDENCE_KEYS as $key) {
            $this->requireSame($visibleEvidence[$key] ?? null, true, 'input.big_five.visible_evidence_'.$key.'_missing', $blockers);
        }
    }

    /** @param list<string> $blockers */
    private function validateCareerProjection(array $projection, array &$blockers): void
    {
        $this->validateExactKeys($projection, self::CAREER_KEYS, 'input.career_projection', $blockers);
        $this->requireSame($projection['projection_kind'] ?? null, self::CAREER_PROJECTION_KIND, 'input.career.projection_kind_invalid', $blockers);
        $this->requireSame($projection['projection_version'] ?? null, self::CAREER_PROJECTION_VERSION, 'input.career.projection_version_invalid', $blockers);
        $this->requireSha256($projection['projection_hash'] ?? null, 'input.career.projection_hash_invalid', $blockers);
        $this->requireSafeIdentity($projection['occupation_id'] ?? null, 'input.career.occupation_id_invalid', $blockers);
        $this->requireSlug($projection['canonical_slug'] ?? null, 'input.career.canonical_slug_invalid', $blockers);
        $this->requireOneOf($projection['locale'] ?? null, ['en', 'zh'], 'input.career.locale_invalid', $blockers);
        $this->requireSame($projection['public_resolution_type'] ?? null, 'public_canonical_job', 'input.career.not_public_canonical_job', $blockers);
        $this->requireSame($projection['runtime_publish_state'] ?? null, 'published', 'input.career.runtime_not_published', $blockers);
        $this->requireSame($projection['detail_route_enabled'] ?? null, true, 'input.career.detail_route_not_enabled', $blockers);
        $this->requireSame($projection['dataset_visible'] ?? null, true, 'input.career.dataset_not_visible', $blockers);
        $this->requireSame($projection['release_gate_pass'] ?? null, true, 'input.career.release_gate_not_passed', $blockers);
        $this->requireSame($projection['publish_eligibility'] ?? null, true, 'input.career.publish_eligibility_missing', $blockers);
        $this->requireSame($projection['public_projection_ready'] ?? null, true, 'input.career.public_projection_not_ready', $blockers);

        $projectionBlockers = $this->stringListAt($projection, 'blockers', 'input.career.blockers_invalid', $blockers, allowEmpty: true);
        if ($projectionBlockers !== []) {
            $blockers[] = 'input.career.runtime_projection_has_blockers';
        }
    }

    /** @param list<string> $blockers */
    private function validateSignalPolicy(array $policy, array &$blockers): void
    {
        $this->validateExactKeys($policy, self::SIGNAL_POLICY_KEYS, 'input.signal_policy', $blockers);
        $this->requireSame($policy['primary_career_interest_signal'] ?? null, self::PRIMARY_CAREER_SIGNAL, 'input.signal_policy.riasec_not_primary', $blockers);
        $this->requireSame($policy['big_five_role'] ?? null, self::BIG_FIVE_ROLE, 'input.signal_policy.big_five_role_invalid', $blockers);
        $this->requireSame($policy['claim_mode'] ?? null, self::CLAIM_MODE, 'input.signal_policy.claim_mode_invalid', $blockers);
        foreach (['occupation_ranking_allowed', 'hiring_use_allowed', 'outcome_prediction_allowed', 'pseo_expansion_allowed'] as $key) {
            $this->requireSame($policy[$key] ?? null, false, 'input.signal_policy.'.$key.'_must_be_false', $blockers);
        }
    }

    /** @param list<string> $blockers */
    private function validateSourceLockShape(array $sourceLocks, array &$blockers): void
    {
        $this->validateExactKeys($sourceLocks, self::SOURCE_LOCK_KEYS, 'output.source_locks', $blockers);
        $this->requireSafeIdentity($sourceLocks['big_five_asset_id'] ?? null, 'output.source_locks.big_five_asset_id_invalid', $blockers);
        $this->requireOneOf($sourceLocks['big_five_locale'] ?? null, ['en', 'zh-CN'], 'output.source_locks.big_five_locale_invalid', $blockers);
        $this->requirePositiveInteger($sourceLocks['big_five_published_revision_id'] ?? null, 'output.source_locks.big_five_published_revision_id_invalid', $blockers);
        $this->requireSha256($sourceLocks['big_five_public_projection_hash'] ?? null, 'output.source_locks.big_five_public_projection_hash_invalid', $blockers);
        $this->requireSafeIdentity($sourceLocks['career_occupation_id'] ?? null, 'output.source_locks.career_occupation_id_invalid', $blockers);
        $this->requireSlug($sourceLocks['career_canonical_slug'] ?? null, 'output.source_locks.career_canonical_slug_invalid', $blockers);
        $this->requireOneOf($sourceLocks['career_locale'] ?? null, ['en', 'zh'], 'output.source_locks.career_locale_invalid', $blockers);
        $this->requireSame($sourceLocks['career_projection_version'] ?? null, self::CAREER_PROJECTION_VERSION, 'output.source_locks.career_projection_version_invalid', $blockers);
        $this->requireSha256($sourceLocks['career_runtime_projection_hash'] ?? null, 'output.source_locks.career_runtime_projection_hash_invalid', $blockers);
    }

    /** @param list<string> $blockers */
    private function validateContent(array $content, mixed $status, array &$blockers): void
    {
        $this->validateExactKeys($content, self::CONTENT_KEYS, 'output.content', $blockers);
        $minimum = $status === self::STATUS_PUBLISHED_PROJECTION_READY ? 1 : 0;

        foreach (self::CONTENT_KEYS as $key) {
            $values = $this->stringListAt($content, $key, 'output.content.'.$key.'_invalid', $blockers, allowEmpty: $minimum === 0);
            if ($minimum === 1 && $values === []) {
                $blockers[] = 'output.content.'.$key.'_required_when_ready';
            }
        }
    }

    /** @param list<string> $blockers */
    private function validateClaimBoundary(array $boundary, array &$blockers): void
    {
        $this->validateExactKeys($boundary, self::CLAIM_BOUNDARY_KEYS, 'output.claim_boundary', $blockers);
        $this->requireSame($boundary['big_five_role'] ?? null, self::BIG_FIVE_ROLE, 'output.claim_boundary.big_five_role_invalid', $blockers);
        $this->requireSame($boundary['primary_career_interest_signal'] ?? null, self::PRIMARY_CAREER_SIGNAL, 'output.claim_boundary.riasec_not_primary', $blockers);
        foreach (['recommendation_authority', 'ranking_allowed', 'hiring_use_allowed', 'pseo_allowed'] as $key) {
            $this->requireSame($boundary[$key] ?? null, false, 'output.claim_boundary.'.$key.'_must_be_false', $blockers);
        }
    }

    /** @param list<string> $blockers */
    private function validatePrivacyBoundary(array $privacy, string $path, array &$blockers): void
    {
        $this->validateExactKeys($privacy, self::PRIVACY_KEYS, $path, $blockers);
        foreach (self::PRIVACY_KEYS as $key) {
            $this->requireSame($privacy[$key] ?? null, false, $path.'.'.$key.'_must_be_false', $blockers);
        }
    }

    /** @param list<string> $blockers */
    private function validateLocaleBinding(array $input, array $bigFive, array $career, array &$blockers): void
    {
        $bridgeLocale = $input['locale'] ?? null;
        $expectedBigFiveLocale = $bridgeLocale === 'zh' ? 'zh-CN' : $bridgeLocale;

        if (($bigFive['locale'] ?? null) !== $expectedBigFiveLocale) {
            $blockers[] = 'input.locale.big_five_projection_mismatch';
        }
        if (($career['locale'] ?? null) !== $bridgeLocale) {
            $blockers[] = 'input.locale.career_projection_mismatch';
        }
    }

    /** @return list<string> */
    private function validateSourceLocks(array $input, array $output): array
    {
        $bigFive = is_array($input['big_five_projection'] ?? null) ? $input['big_five_projection'] : [];
        $career = is_array($input['career_projection'] ?? null) ? $input['career_projection'] : [];
        $locks = is_array($output['source_locks'] ?? null) ? $output['source_locks'] : [];
        $blockers = [];

        $expected = [
            'big_five_asset_id' => $bigFive['asset_id'] ?? null,
            'big_five_locale' => $bigFive['locale'] ?? null,
            'big_five_published_revision_id' => $bigFive['published_revision_id'] ?? null,
            'big_five_public_projection_hash' => $bigFive['public_projection_hash'] ?? null,
            'career_occupation_id' => $career['occupation_id'] ?? null,
            'career_canonical_slug' => $career['canonical_slug'] ?? null,
            'career_locale' => $career['locale'] ?? null,
            'career_projection_version' => $career['projection_version'] ?? null,
            'career_runtime_projection_hash' => $career['projection_hash'] ?? null,
        ];

        foreach ($expected as $key => $value) {
            if (($locks[$key] ?? null) !== $value) {
                $blockers[] = 'output.source_locks.'.$key.'_mismatch';
            }
        }
        if (($output['bridge_id'] ?? null) !== ($input['bridge_id'] ?? null)) {
            $blockers[] = 'output.bridge_id_mismatch';
        }

        return $this->uniqueSorted($blockers);
    }

    /** @param list<string> $blockers */
    private function validateTopLevelBindings(array $input, array $bigFive, array $career, array $privacy, array &$blockers): void
    {
        $expected = [
            'big_five_asset_identity' => $bigFive['asset_id'] ?? null,
            'big_five_primary_status' => $bigFive['primary_status'] ?? null,
            'big_five_published_revision_id' => $bigFive['published_revision_id'] ?? null,
            'big_five_public_projection_hash' => $bigFive['public_projection_hash'] ?? null,
            'big_five_claim_permissions' => $bigFive['visible_evidence']['claim_permission'] ?? null,
            'big_five_source_permission' => $bigFive['visible_evidence']['source_permission'] ?? null,
            'big_five_reviewer_permission' => $bigFive['visible_evidence']['reviewer_permission'] ?? null,
            'big_five_date_permission' => $bigFive['visible_evidence']['visible_date_permission'] ?? null,
            'career_canonical_slug' => $career['canonical_slug'] ?? null,
            'career_runtime_projection_version' => $career['projection_version'] ?? null,
            'career_runtime_projection_hash' => $career['projection_hash'] ?? null,
            'career_publish_eligibility' => $career['publish_eligibility'] ?? null,
        ];

        foreach ($expected as $key => $value) {
            if (($input[$key] ?? null) !== $value) {
                $blockers[] = 'input.binding_mismatch:'.$key;
            }
        }

        $privacyIsAbsent = $privacy !== []
            && count(array_filter($privacy, static fn (mixed $value): bool => $value !== false)) === 0;
        if (($input['private_data_absent'] ?? null) !== $privacyIsAbsent) {
            $blockers[] = 'input.binding_mismatch:private_data_absent';
        }
    }

    /** @param list<string> $expected @param list<string> $blockers */
    private function validateExactKeys(array $value, array $expected, string $path, array &$blockers): void
    {
        foreach ($expected as $key) {
            if (! array_key_exists($key, $value)) {
                $blockers[] = $path.'.missing_key:'.$key;
            }
        }
        foreach (array_keys($value) as $key) {
            if (! in_array($key, $expected, true)) {
                $blockers[] = $path.'.unexpected_key:'.$key;
            }
        }
    }

    /** @param list<string> $blockers @return array<string, mixed> */
    private function objectAt(array $value, string $key, string $blocker, array &$blockers): array
    {
        if (! is_array($value[$key] ?? null) || array_is_list($value[$key])) {
            $blockers[] = $blocker;

            return [];
        }

        return $value[$key];
    }

    /** @param list<string> $blockers @return list<string> */
    private function stringListAt(array $value, string $key, string $blocker, array &$blockers, bool $allowEmpty): array
    {
        $items = $value[$key] ?? null;
        if (! is_array($items) || ! array_is_list($items)) {
            $blockers[] = $blocker;

            return [];
        }
        if (! $allowEmpty && $items === []) {
            $blockers[] = $blocker;

            return [];
        }
        if (count($items) > 20 || count($items) !== count(array_unique($items, SORT_REGULAR))) {
            $blockers[] = $blocker;

            return [];
        }
        foreach ($items as $item) {
            if (! is_string($item) || trim($item) === '' || mb_strlen($item) > 500) {
                $blockers[] = $blocker;

                return [];
            }
        }

        return $items;
    }

    /** @param list<string> $blockers */
    private function requireSame(mixed $actual, mixed $expected, string $blocker, array &$blockers): void
    {
        if ($actual !== $expected) {
            $blockers[] = $blocker;
        }
    }

    /** @param list<mixed> $allowed @param list<string> $blockers */
    private function requireOneOf(mixed $actual, array $allowed, string $blocker, array &$blockers): void
    {
        if (! in_array($actual, $allowed, true)) {
            $blockers[] = $blocker;
        }
    }

    /** @param list<string> $blockers */
    private function requireSafeIdentity(mixed $value, string $blocker, array &$blockers): void
    {
        if (! is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,191}$/', $value) !== 1) {
            $blockers[] = $blocker;
        }
    }

    /** @param list<string> $blockers */
    private function requireSlug(mixed $value, string $blocker, array &$blockers): void
    {
        if (! is_string($value) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) !== 1) {
            $blockers[] = $blocker;
        }
    }

    /** @param list<string> $blockers */
    private function requireBoolean(mixed $value, string $blocker, array &$blockers): void
    {
        if (! is_bool($value)) {
            $blockers[] = $blocker;
        }
    }

    /** @param list<string> $blockers */
    private function requirePositiveInteger(mixed $value, string $blocker, array &$blockers): void
    {
        if (! is_int($value) || $value < 1) {
            $blockers[] = $blocker;
        }
    }

    /** @param list<string> $blockers */
    private function requireSha256(mixed $value, string $blocker, array &$blockers): void
    {
        if (! is_string($value) || preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            $blockers[] = $blocker;
        }
    }

    /** @param list<string> $blockers */
    private function requireNullablePositiveInteger(mixed $value, string $blocker, array &$blockers): void
    {
        if ($value !== null && (! is_int($value) || $value < 1)) {
            $blockers[] = $blocker;
        }
    }

    /** @return list<string> */
    private function forbiddenKeys(array $value): array
    {
        $found = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_CONTENT_KEYS, true)) {
                $found[] = strtolower($key);
            }
            if (is_array($item)) {
                foreach ($this->forbiddenKeys($item) as $nested) {
                    $found[] = $nested;
                }
            }
        }

        return $this->uniqueSorted($found);
    }

    /** @return list<string> */
    private function forbiddenClaimFragments(array $content): array
    {
        $claimBearingContent = array_intersect_key(
            $content,
            array_flip([
                'reflection_signals',
                'environment_questions',
                'feedback_and_structure_preferences',
                'possible_friction_cues',
                'exploration_examples',
            ]),
        );
        $haystack = strtolower(implode("\n", $this->flattenStrings($claimBearingContent)));
        $found = [];
        foreach (self::FORBIDDEN_CLAIM_FRAGMENTS as $fragment) {
            if (str_contains($haystack, strtolower($fragment))) {
                $found[] = $fragment;
            }
        }

        return $this->uniqueSorted($found);
    }

    /** @return list<string> */
    private function flattenStrings(array $value): array
    {
        $strings = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            } elseif (is_array($item)) {
                foreach ($this->flattenStrings($item) as $nested) {
                    $strings[] = $nested;
                }
            }
        }

        return $strings;
    }

    /** @param list<string> $values @return list<string> */
    private function uniqueSorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }
}
