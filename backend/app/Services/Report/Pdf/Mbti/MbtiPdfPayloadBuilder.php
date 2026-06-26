<?php

declare(strict_types=1);

namespace App\Services\Report\Pdf\Mbti;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Legacy\Mbti\Content\LegacyMbtiPackRepository;
use App\Services\Legacy\Mbti\Report\V2\LegacyMbtiReportPayloadBuilderV2Facade;

final class MbtiPdfPayloadBuilder
{
    public const PAYLOAD_KEY = 'mbti_pdf_payload';

    public const SCHEMA_VERSION = 'fap.mbti.report_pdf.payload.v0_1';

    /**
     * These fields are either internal, raw scoring material, or unsafe to carry into
     * a user-facing PDF projection.
     */
    private const FORBIDDEN_KEYS = [
        'attempt_id',
        'attemptId',
        'raw_answer',
        'raw_answers',
        'answers',
        'answers_json',
        'raw_score',
        'raw_scores',
        'scores_json',
        'raw_mean',
        'z',
        't',
        'debug',
        'debug_info',
        'qa_notes',
        'editor_notes',
        'internal_metadata',
        'internal_path',
        'storage_path',
        'source_trace',
        'source_reference',
        'quality',
        'quality_level',
    ];

    public function __construct(
        private readonly LegacyMbtiPackRepository $packRepository,
        private readonly LegacyMbtiReportPayloadBuilderV2Facade $legacyComposer,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function build(Attempt $attempt, ?Result $result = null): array
    {
        $locale = trim((string) ($attempt->locale ?? 'zh-CN'));
        $region = trim((string) ($attempt->region ?? 'CN_MAINLAND'));
        $dirVersion = trim((string) ($attempt->dir_version ?? $result?->dir_version ?? ''));
        $typeCode = $this->resolveTypeCode($result);
        $scoresPct = $this->resolveScoresPct($result);
        $axisStates = $this->resolveAxisStates($result);
        $contentDir = $this->packRepository->resolveContentDir(
            is_string($attempt->pack_id ?? null) ? (string) $attempt->pack_id : null,
            $dirVersion !== '' ? $dirVersion : null,
            $region !== '' ? $region : null,
            $locale !== '' ? $locale : null,
        );
        $typeProfile = $this->resolveTypeProfile($contentDir, $typeCode);
        $legacyPayload = $this->legacyComposer->build([
            'contentDir' => $contentDir,
            'scores_pct' => $scoresPct,
            'axis_states' => $axisStates,
            'type_profile' => $typeProfile,
            'opts' => [
                'type_code' => $typeCode,
                'recommended_reads_max' => 4,
            ],
        ]);

        return [
            self::PAYLOAD_KEY => [
                'schema_version' => self::SCHEMA_VERSION,
                'surface_key' => 'pdf',
                'source_payload_key' => 'legacy_mbti_report_payload_v2',
                'scale_code' => 'MBTI',
                'locale' => $locale !== '' ? $locale : 'zh-CN',
                'region' => $region !== '' ? $region : 'CN_MAINLAND',
                'dir_version' => $dirVersion,
                'type' => $this->publicTypeProfile($typeCode, $typeProfile, $legacyPayload),
                'axis_scores' => $this->publicAxisScores($scoresPct, $axisStates),
                'highlights' => $this->publicHighlights((array) ($legacyPayload['highlights'] ?? [])),
                'sections' => $this->publicSections((array) ($legacyPayload['cards'] ?? [])),
                'recommended_reads' => $this->publicRecommendedReads((array) ($legacyPayload['recommended_reads'] ?? [])),
                'adapter_policy' => [
                    'source' => 'backend_mbti_content_package_and_result_projection',
                    'frontend_authored_body_allowed' => false,
                    'metadata_filter_required' => true,
                    'internal_fields_allowed' => false,
                    'production_enablement_allowed' => false,
                ],
            ],
        ];
    }

    private function resolveTypeCode(?Result $result): string
    {
        $payload = is_array($result?->result_json ?? null) ? $result->result_json : [];
        $typeCode = strtoupper(trim((string) (
            $result?->type_code
            ?? data_get($payload, 'type_code')
            ?? data_get($payload, 'result.type_code')
            ?? ''
        )));

        return $typeCode !== '' ? $typeCode : 'UNKNOWN';
    }

    /**
     * @return array<string,int>
     */
    private function resolveScoresPct(?Result $result): array
    {
        $payload = is_array($result?->result_json ?? null) ? $result->result_json : [];
        $scores = is_array($result?->scores_pct ?? null) ? $result->scores_pct : [];
        if ($scores === []) {
            $candidate = data_get($payload, 'axis_scores_json.scores_pct');
            $scores = is_array($candidate) ? $candidate : [];
        }

        $out = [];
        foreach (['EI', 'SN', 'TF', 'JP', 'AT'] as $axis) {
            $value = $scores[$axis] ?? null;
            if (is_numeric($value)) {
                $out[$axis] = max(0, min(100, (int) round((float) $value)));
            }
        }

        return $out;
    }

    /**
     * @return array<string,string>
     */
    private function resolveAxisStates(?Result $result): array
    {
        $payload = is_array($result?->result_json ?? null) ? $result->result_json : [];
        $states = is_array($result?->axis_states ?? null) ? $result->axis_states : [];
        if ($states === []) {
            $candidate = data_get($payload, 'axis_scores_json.axis_states');
            $states = is_array($candidate) ? $candidate : [];
        }

        $out = [];
        foreach (['EI', 'SN', 'TF', 'JP', 'AT'] as $axis) {
            $state = trim((string) ($states[$axis] ?? ''));
            if ($state !== '') {
                $out[$axis] = $state;
            }
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveTypeProfile(string $contentDir, string $typeCode): array
    {
        if ($typeCode === 'UNKNOWN') {
            return [];
        }

        $profiles = $this->packRepository->loadJsonFromPack($contentDir, 'type_profiles.json');
        $items = is_array($profiles['items'] ?? null) ? $profiles['items'] : [];
        $profile = $items[$typeCode] ?? null;

        return is_array($profile) ? $profile : [];
    }

    /**
     * @param  array<string,mixed>  $typeProfile
     * @param  array<string,mixed>  $legacyPayload
     * @return array<string,mixed>
     */
    private function publicTypeProfile(string $typeCode, array $typeProfile, array $legacyPayload): array
    {
        $identity = is_array($legacyPayload['identity_layer'] ?? null) ? $legacyPayload['identity_layer'] : [];
        $profile = [
            'type_code' => $typeCode,
            'type_name' => $this->stringOrNull($typeProfile['type_name'] ?? null),
            'tagline' => $this->stringOrNull($typeProfile['tagline'] ?? null),
            'rarity' => $this->stringOrNull($typeProfile['rarity'] ?? null),
            'keywords' => $this->stringList($typeProfile['keywords'] ?? []),
            'short_summary' => $this->stringOrNull($typeProfile['short_summary'] ?? null),
            'identity_layer' => $this->filterPublicContent($identity),
        ];

        return $this->dropNulls($profile);
    }

    /**
     * @param  array<string,int>  $scoresPct
     * @param  array<string,string>  $axisStates
     * @return list<array<string,mixed>>
     */
    private function publicAxisScores(array $scoresPct, array $axisStates): array
    {
        $out = [];
        foreach (['EI', 'SN', 'TF', 'JP', 'AT'] as $axis) {
            if (! array_key_exists($axis, $scoresPct)) {
                continue;
            }

            $out[] = $this->dropNulls([
                'axis' => $axis,
                'percent' => $scoresPct[$axis],
                'state' => $axisStates[$axis] ?? null,
            ]);
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function publicHighlights(array $highlights): array
    {
        $out = [];
        foreach ($highlights as $highlight) {
            if (! is_array($highlight)) {
                continue;
            }

            $out[] = $this->filterPublicContent([
                'id' => $highlight['id'] ?? null,
                'kind' => $highlight['kind'] ?? null,
                'title' => $highlight['title'] ?? null,
                'text' => $highlight['text'] ?? $highlight['desc'] ?? null,
                'tips' => $highlight['tips'] ?? [],
                'tags' => $highlight['tags'] ?? [],
            ]);
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $cardsBySection
     * @return list<array<string,mixed>>
     */
    private function publicSections(array $cardsBySection): array
    {
        $sections = [];
        foreach (['traits', 'career', 'growth', 'relationships'] as $sectionKey) {
            $cards = is_array($cardsBySection[$sectionKey] ?? null) ? $cardsBySection[$sectionKey] : [];
            $publicCards = [];
            foreach ($cards as $card) {
                if (! is_array($card)) {
                    continue;
                }

                $publicCards[] = $this->filterPublicContent([
                    'id' => $card['id'] ?? null,
                    'title' => $card['title'] ?? null,
                    'description' => $card['desc'] ?? $card['description'] ?? null,
                    'bullets' => $card['bullets'] ?? [],
                    'tips' => $card['tips'] ?? [],
                    'tags' => $card['tags'] ?? [],
                ]);
            }

            if ($publicCards !== []) {
                $sections[] = [
                    'section_key' => $sectionKey,
                    'cards' => $publicCards,
                ];
            }
        }

        return $sections;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function publicRecommendedReads(array $reads): array
    {
        $out = [];
        foreach ($reads as $read) {
            if (! is_array($read)) {
                continue;
            }

            $out[] = $this->filterPublicContent([
                'title' => $read['title'] ?? null,
                'description' => $read['desc'] ?? $read['description'] ?? null,
                'category' => $read['category'] ?? null,
            ]);
        }

        return $out;
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

            if (is_array($value)) {
                $value = $this->filterPublicContent($value);
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $filtered[$key] = $value;
        }

        return $filtered;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $value),
            static fn (string $item): bool => $item !== '',
        ));
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    private function dropNulls(array $value): array
    {
        return array_filter($value, static fn (mixed $item): bool => $item !== null && $item !== [] && $item !== '');
    }
}
