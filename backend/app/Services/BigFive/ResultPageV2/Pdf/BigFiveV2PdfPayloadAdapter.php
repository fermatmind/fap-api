<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\Pdf;

use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Contract;
use App\Services\BigFive\ResultPageV2\BigFiveResultPageV2Validator;
use InvalidArgumentException;

final class BigFiveV2PdfPayloadAdapter
{
    public const PAYLOAD_KEY = 'big5_result_page_v2_pdf';

    public const SCHEMA_VERSION = 'fap.big5.result_page_v2.pdf_payload.v0_1';

    private const OMIT_MODULE_KEYS = [
        'module_08_share_save',
    ];

    private const FORBIDDEN_KEYS = [
        'source_reference',
        'selector_basis',
        'qa_notes',
        'editor_notes',
        'internal_metadata',
        'review_status',
        'production_use_allowed',
        'runtime_use',
        'ready_for_pilot',
        'ready_for_runtime',
        'ready_for_production',
        'frontend_fallback',
        'source_trace',
        'repair_log_refs',
        'share_safe_summary_zh',
        'share_card_summary_zh',
        'raw_score',
        'raw_scores',
        'raw_mean',
        'z',
        't',
        'standardized_scores',
        'score_vector',
        'percentile',
        'percentiles',
        'facet_vector',
        'domain_vector',
    ];

    public function __construct(
        private readonly BigFiveResultPageV2Validator $validator = new BigFiveResultPageV2Validator,
    ) {}

    /**
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    public function adapt(array $envelope): array
    {
        $errors = $this->validator->validateEnvelope($envelope);
        if ($errors !== []) {
            throw new InvalidArgumentException('Big Five V2 PDF adapter requires a valid route-driven payload: '.implode('; ', $errors));
        }

        $payload = $envelope[BigFiveResultPageV2Contract::PAYLOAD_KEY];
        if (! is_array($payload)) {
            throw new InvalidArgumentException('Big Five V2 PDF adapter requires a payload object.');
        }

        $modules = is_array($payload['modules'] ?? null) ? $payload['modules'] : [];
        $sections = [];
        foreach ($modules as $module) {
            if (! is_array($module)) {
                continue;
            }

            $moduleKey = (string) ($module['module_key'] ?? '');
            if (in_array($moduleKey, self::OMIT_MODULE_KEYS, true)) {
                continue;
            }

            $blocks = [];
            foreach ((array) ($module['blocks'] ?? []) as $block) {
                if (! is_array($block) || ($block['shareable'] ?? false) === true) {
                    continue;
                }

                $blocks[] = [
                    'block_key' => (string) ($block['block_key'] ?? ''),
                    'block_kind' => (string) ($block['block_kind'] ?? ''),
                    'content' => $this->filterPublicContent((array) ($block['content'] ?? [])),
                    'safety_level' => (string) ($block['safety_level'] ?? ''),
                    'evidence_level' => (string) ($block['evidence_level'] ?? ''),
                    'content_source' => (string) ($block['content_source'] ?? ''),
                ];
            }

            if ($blocks !== []) {
                $sections[] = [
                    'module_key' => $moduleKey,
                    'blocks' => $blocks,
                ];
            }
        }

        if ($sections === []) {
            throw new InvalidArgumentException('Big Five V2 PDF adapter found no safe PDF sections.');
        }

        return [
            self::PAYLOAD_KEY => [
                'schema_version' => self::SCHEMA_VERSION,
                'surface_key' => 'pdf',
                'source_payload_key' => BigFiveResultPageV2Contract::PAYLOAD_KEY,
                'source_schema_version' => (string) ($payload['schema_version'] ?? ''),
                'scale_code' => BigFiveResultPageV2Contract::SCALE_CODE,
                'content_version' => (string) ($payload['content_version'] ?? ''),
                'package_version' => (string) ($payload['package_version'] ?? ''),
                'canonical_profile_key' => (string) ($payload['canonical_profile_key'] ?? ''),
                'profile_label_zh' => (string) ($payload['profile_label_zh'] ?? ''),
                'sections' => $sections,
                'adapter_policy' => [
                    'source' => 'validated_route_driven_big5_result_page_v2_payload',
                    'frontend_authored_body_allowed' => false,
                    'invalid_payload_behavior' => 'fail_closed',
                    'metadata_filter_required' => true,
                    'production_enablement_allowed' => false,
                ],
            ],
        ];
    }

    /**
     * @param  array<int|string,mixed>  $content
     * @return array<int|string,mixed>
     */
    private function filterPublicContent(array $content): array
    {
        $filtered = [];
        foreach ($content as $key => $value) {
            if (in_array((string) $key, self::FORBIDDEN_KEYS, true)) {
                continue;
            }

            $filtered[$key] = is_array($value) ? $this->filterPublicContent($value) : $value;
        }

        return $filtered;
    }
}
