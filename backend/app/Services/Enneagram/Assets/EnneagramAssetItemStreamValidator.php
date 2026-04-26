<?php

declare(strict_types=1);

namespace App\Services\Enneagram\Assets;

final class EnneagramAssetItemStreamValidator
{
    private const REQUIRED_TOP_LEVEL_FIELDS = [
        'asset_count',
        'batch',
        'batch_name',
        'content_maturity',
        'import_policy',
        'locale',
        'replacement_policy',
        'review_status',
        'schema_version',
        'version',
    ];

    private const REQUIRED_ITEM_FIELDS = [
        'asset_key',
        'asset_type',
        'category',
        'module_key',
        'applies_to',
        'body_zh',
        'short_body_zh',
        'cta_zh',
        'replacement_target',
        'content_maturity',
        'review_status',
        'version',
    ];

    private const BANNED_BODY_PHRASES = [
        '诊断',
        '治疗',
        '病人',
        '病症',
        '绝对',
        '最准',
        '终极判型',
    ];

    public function __construct(
        private readonly EnneagramAssetMergePolicyValidator $mergePolicyValidator,
        private readonly EnneagramAssetPublicPayloadSanitizer $publicPayloadSanitizer,
    ) {}

    /**
     * @param  array{source_file?:string,metadata?:array<string,mixed>,items?:list<array<string,mixed>>}  $stream
     * @return array<string,mixed>
     */
    public function validate(array $stream): array
    {
        $metadata = is_array($stream['metadata'] ?? null) ? $stream['metadata'] : [];
        $items = is_array($stream['items'] ?? null) ? $stream['items'] : [];
        $sourceFile = (string) ($stream['source_file'] ?? '');
        $errors = [];
        $warnings = [];
        $batchKey = $this->mergePolicyValidator->detectBatchKey($metadata, $items);

        foreach (self::REQUIRED_TOP_LEVEL_FIELDS as $field) {
            if (! array_key_exists($field, $metadata)) {
                $errors[] = 'missing_top_level_field:'.$field;
            }
        }

        $declaredCount = (int) ($metadata['asset_count'] ?? -1);
        if ($declaredCount !== count($items)) {
            $errors[] = 'asset_count_mismatch:declared='.$declaredCount.',actual='.count($items);
        }

        $assetKeys = [];
        $bodyHashes = [];
        $categoryCounts = [];
        $typeCounts = [];
        $bannedHits = [];
        $copyJoiningErrors = [];
        $bodyLengthErrors = [];

        foreach ($items as $index => $item) {
            $requiredFields = self::REQUIRED_ITEM_FIELDS;
            if ($batchKey === '1R-F') {
                $requiredFields = array_merge($requiredFields, [
                    'pair_key',
                    'canonical_pair_key',
                    'type_a',
                    'type_b',
                    'title_zh',
                    'commercial_summary',
                    'page1_close_call_summary',
                    'shared_surface_similarity',
                    'core_motivation_difference',
                    'fear_difference',
                    'stress_reaction_difference',
                    'work_difference',
                    'relationship_difference',
                    'seven_day_observation_question',
                    'resonance_feedback_prompt',
                    'micro_discrimination_prompt',
                ]);
            } else {
                $requiredFields[] = 'type_id';
            }

            foreach ($requiredFields as $field) {
                if (! array_key_exists($field, $item)) {
                    $errors[] = 'item_'.$index.'_missing_field:'.$field;
                }
            }

            $assetKey = trim((string) ($item['asset_key'] ?? ''));
            if ($assetKey !== '') {
                if (isset($assetKeys[$assetKey])) {
                    $errors[] = 'duplicate_asset_key:'.$assetKey;
                }
                $assetKeys[$assetKey] = true;
            }

            $category = trim((string) ($item['category'] ?? ''));
            if ($category !== '') {
                $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
            }

            $typeId = trim((string) ($item['type_id'] ?? ''));
            if ($typeId !== '') {
                $typeCounts[$typeId] = ($typeCounts[$typeId] ?? 0) + 1;
            }

            $body = (string) ($item['body_zh'] ?? '');
            $bodyHash = hash('sha256', $body);
            if ($body !== '') {
                if (isset($bodyHashes[$bodyHash])) {
                    $errors[] = 'duplicate_body_zh:'.$assetKey;
                }
                $bodyHashes[$bodyHash] = true;
            }

            if (mb_strlen($body) < 12 || mb_strlen($body) > 900) {
                $bodyLengthErrors[] = $assetKey !== '' ? $assetKey : 'item_'.$index;
            }

            foreach (self::BANNED_BODY_PHRASES as $phrase) {
                if ($phrase !== '' && str_contains($body, $phrase)) {
                    $bannedHits[] = ($assetKey !== '' ? $assetKey : 'item_'.$index).':'.$phrase;
                }
            }

            if (preg_match('/[，。；、]{3,}|\\s{4,}|(。\\s*。)/u', $body) === 1) {
                $copyJoiningErrors[] = $assetKey !== '' ? $assetKey : 'item_'.$index;
            }
        }

        foreach ($bannedHits as $hit) {
            $errors[] = 'banned_body_phrase:'.$hit;
        }
        foreach ($copyJoiningErrors as $assetKey) {
            $errors[] = 'copy_joining_error:'.$assetKey;
        }
        foreach ($bodyLengthErrors as $assetKey) {
            $errors[] = 'body_length_out_of_bounds:'.$assetKey;
        }

        $errors = array_merge($errors, $this->mergePolicyValidator->validateSingle([
            'metadata' => $metadata,
            'items' => $items,
        ]));

        $productionAllowed = (bool) (
            $metadata['production_import_allowed']
            ?? data_get($metadata, 'preflight_self_check.production_import_allowed')
            ?? false
        );
        if (! $productionAllowed) {
            $warnings[] = 'production_import_blocked_by_asset_governance';
        } else {
            $errors[] = 'production_import_allowed_must_be_false_for_phase_0';
        }

        $stagingPreviewAllowed = (bool) (
            $metadata['staging_merge_preview_allowed']
            ?? data_get($metadata, 'preflight_self_check.staging_merge_preview_allowed')
            ?? str_contains((string) ($metadata['import_policy'] ?? ''), 'staging')
        );
        if (! $stagingPreviewAllowed) {
            $errors[] = 'staging_preview_allowed_must_be_true_for_phase_0';
        }

        $publicLeakProbe = $this->publicPayloadSanitizer->internalMetadataLeaks(
            $this->publicPayloadSanitizer->sanitizeItem($items[0] ?? [])
        );
        foreach ($publicLeakProbe as $leak) {
            $errors[] = 'public_payload_internal_metadata_leak:'.$leak;
        }

        ksort($categoryCounts);
        ksort($typeCounts);

        return [
            'status' => $errors === [] ? 'PASS' : 'FAIL',
            'source_file' => $sourceFile,
            'asset_version' => (string) ($metadata['version'] ?? ''),
            'asset_count' => count($items),
            'declared_asset_count' => $declaredCount,
            'production_import_allowed' => false,
            'staging_preview_allowed' => $stagingPreviewAllowed,
            'full_replacement_allowed' => false,
            'counts' => [
                'asset_key_count' => count($assetKeys),
                'unique_body_zh_count' => count($bodyHashes),
                'category_counts' => $categoryCounts,
                'type_counts' => $typeCounts,
                'duplicate_asset_key_count' => count($items) - count($assetKeys),
                'duplicate_body_zh_count' => count($items) - count($bodyHashes),
                'banned_phrase_hit_count' => count($bannedHits),
                'copy_joining_error_count' => count($copyJoiningErrors),
                'body_length_error_count' => count($bodyLengthErrors),
            ],
            'blocked_reasons' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }
}
