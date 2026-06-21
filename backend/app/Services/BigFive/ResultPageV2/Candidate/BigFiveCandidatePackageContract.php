<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\Candidate;

final class BigFiveCandidatePackageContract
{
    public const MANIFEST_SCHEMA_VERSION = 'big5.result_page_v2.production_equivalent_candidate.v1';

    public const PAYLOAD_SCHEMA_VERSION = 'big5.result_page_v2.candidate_payload.v1';

    public const PAYLOADS_MANIFEST_SCHEMA_VERSION = 'big5.result_page_v2.candidate_payloads_manifest.v1';

    public const INACTIVE_RELEASE_SCHEMA_VERSION = 'big5.result_page_v2.inactive_candidate_release_manifest.v1';

    public const EXPECTED_SOURCE_ASSET_COUNT = 325;

    public const PACK_ID = 'BIG5_OCEAN';

    public const PACK_VERSION = 'result_page_v2';

    public const SOURCE_ASSETS_RELATIVE_PATH = 'content_assets/big5/result_page_v2/selector_ready_assets/v0_3_p0_full/assets.json';

    public const SOURCE_MANIFEST_RELATIVE_PATH = 'content_assets/big5/result_page_v2/selector_ready_assets/v0_3_p0_full/manifest.json';

    public const RELEASE_ACTION = 'bigfive_result_page_v2_import_inactive_candidate';

    public const REQUIRED_CANDIDATE_FILES = [
        'candidate_manifest.json',
        'candidate_hashes.json',
        'candidate_payloads_manifest.json',
        'candidate_payload_hashes.json',
        'candidate_payload_source_mapping.json',
        'source_mapping_report.json',
        'metadata_leakage_report.json',
        'forbidden_claim_report.json',
        'rollback_plan.md',
    ];

    public const PUBLIC_PAYLOAD_FORBIDDEN_KEYS = [
        'asset_key',
        'asset_layer',
        'asset_type',
        'avoid_when',
        'body_quality',
        'can_combine_with',
        'cannot_combine_with',
        'copy_role',
        'dedupe_group',
        'editor_note',
        'editor_notes',
        'fallback_allowed',
        'fixed_type',
        'frontend_fallback',
        'governance_metadata',
        'import_policy',
        'internal_combination_key',
        'internal_metadata',
        'internal_notes',
        'must_include_assets',
        'must_suppress_assets',
        'private_metadata',
        'production_use_allowed',
        'qa_note',
        'qa_notes',
        'qa_status',
        'raw_mean',
        'raw_score',
        'raw_scores',
        'ready_for_pilot',
        'ready_for_production',
        'ready_for_runtime',
        'recommended_coupling_assets',
        'recommended_facet_assets',
        'recommended_trait_band_assets',
        'review_status',
        'runtime_use',
        'score_vector',
        'selection_guidance',
        'selection_priority',
        'selector_basis',
        'source_reference',
        'source_trace',
        'type_code',
        'type_name',
        'user_confirmed_type',
    ];

    public const FORBIDDEN_CLAIM_PATTERNS = [
        '/临床诊断/u',
        '/医学诊断/u',
        '/诊断为/u',
        '/固定人格类型/u',
        '/你就是某一型/u',
        '/你就是这类/u',
        '/最终类型/u',
        '/确定就是/u',
        '/准确率/u',
        '/保证/u',
        '/clinical diagnosis/i',
        '/medical diagnosis/i',
        '/diagnosed as/i',
        '/fixed type/i',
        '/confirmed type/i',
        '/you are this type/i',
        '/you are a type/i',
        '/final type/i',
        '/accuracy rate/i',
        '/guarantee/i',
        '/employment screening/i',
        '/hiring decision/i',
        '/recruiting screen/i',
    ];

    public const ALLOWED_BOUNDARY_FRAGMENTS = [
        '不应用于招聘',
        '不用于招聘',
        'not for hiring',
        'not for employment screening',
        'not be used for hiring',
    ];
}
