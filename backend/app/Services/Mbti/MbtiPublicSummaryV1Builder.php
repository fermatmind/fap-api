<?php

declare(strict_types=1);

namespace App\Services\Mbti;

use App\Models\Result;

final class MbtiPublicSummaryV1Builder
{
    /**
     * @var list<string>
     */
    private const AXIS_ORDER = ['EI', 'SN', 'TF', 'JP', 'AT'];

    /**
     * @var array<string, array{
     *   letters: array{left:string,right:string},
     *   locales: array<string, array{label:string,left:string,right:string}>
     * }>
     */
    private const AXIS_DEFINITIONS = [
        'EI' => [
            'letters' => ['left' => 'E', 'right' => 'I'],
            'locales' => [
                'zh-CN' => ['label' => '能量方向', 'left' => '外倾', 'right' => '内倾'],
                'en' => ['label' => 'Energy', 'left' => 'Extraversion', 'right' => 'Introversion'],
            ],
        ],
        'SN' => [
            'letters' => ['left' => 'S', 'right' => 'N'],
            'locales' => [
                'zh-CN' => ['label' => '信息偏好', 'left' => '实感', 'right' => '直觉'],
                'en' => ['label' => 'Information', 'left' => 'Sensing', 'right' => 'Intuition'],
            ],
        ],
        'TF' => [
            'letters' => ['left' => 'T', 'right' => 'F'],
            'locales' => [
                'zh-CN' => ['label' => '决策偏好', 'left' => '思考', 'right' => '情感'],
                'en' => ['label' => 'Decision', 'left' => 'Thinking', 'right' => 'Feeling'],
            ],
        ],
        'JP' => [
            'letters' => ['left' => 'J', 'right' => 'P'],
            'locales' => [
                'zh-CN' => ['label' => '生活方式', 'left' => '判断', 'right' => '感知'],
                'en' => ['label' => 'Lifestyle', 'left' => 'Judging', 'right' => 'Perceiving'],
            ],
        ],
        'AT' => [
            'letters' => ['left' => 'A', 'right' => 'T'],
            'locales' => [
                'zh-CN' => ['label' => '稳定度', 'left' => '果断', 'right' => '敏感'],
                'en' => ['label' => 'Identity', 'left' => 'Assertive', 'right' => 'Turbulent'],
            ],
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public function scaffold(?string $runtimeTypeCode = null): array
    {
        $identity = $this->normalizeTypeIdentity($runtimeTypeCode);

        return [
            'runtime_type_code' => $identity['runtime_type_code'],
            'canonical_type_16' => $identity['canonical_type_16'],
            'display_type' => $identity['display_type'],
            'variant' => $identity['variant'],
            'profile' => [
                'type_name' => null,
                'nickname' => null,
                'subtitle' => null,
                'summary' => null,
                'rarity' => null,
                'keywords' => [],
            ],
            'summary_card' => [
                'title' => null,
                'subtitle' => null,
                'share_text' => null,
                'tags' => [],
            ],
            'dimensions' => [],
            'sections' => [],
            'offer_set' => [
                'offer_key' => null,
                'upgrade_sku' => null,
                'modules_allowed' => [],
                'modules_preview' => [],
                'view_policy' => null,
                'cta' => null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $reportEnvelope
     * @return array<string, mixed>
     */
    public function buildFromReportEnvelope(Result $result, array $reportEnvelope, ?string $locale = null): array
    {
        $report = $this->arrayOrEmpty($reportEnvelope['report'] ?? null);
        $resultJson = $this->arrayOrEmpty($result->result_json ?? null);
        $profile = $this->arrayOrEmpty($report['profile'] ?? null);
        $identityCard = $this->arrayOrEmpty($report['identity_card'] ?? null);
        $identityLayer = $this->arrayOrEmpty(data_get($report, 'layers.identity'));
        $resolvedLocale = $this->normalizeLocale($locale);
        $payload = $this->scaffold($this->firstNonEmpty(
            $profile['type_code'] ?? null,
            $resultJson['type_code'] ?? null,
            $result->type_code ?? null,
            $identityCard['type_code'] ?? null,
        ));

        $payload['profile'] = [
            'type_name' => $this->nullableText(
                $profile['type_name'] ?? $resultJson['type_name'] ?? null
            ),
            'nickname' => $this->nullableText(
                $profile['tagline'] ?? $resultJson['tagline'] ?? null
            ),
            'subtitle' => $this->nullableText(
                $identityLayer['subtitle'] ?? $identityCard['subtitle'] ?? $resultJson['subtitle'] ?? null
            ),
            'summary' => $this->nullableText(
                $profile['short_summary'] ?? $identityCard['summary'] ?? $resultJson['summary'] ?? null
            ),
            'rarity' => $this->nullableScalar(
                $profile['rarity'] ?? $resultJson['rarity'] ?? null
            ),
            'keywords' => $this->stringList(
                $profile['keywords'] ?? $resultJson['keywords'] ?? null
            ),
        ];

        $payload['summary_card'] = [
            'title' => $this->nullableText(
                $identityCard['title'] ?? $identityLayer['title'] ?? null
            ),
            'subtitle' => $this->nullableText(
                $identityCard['subtitle'] ?? $identityLayer['subtitle'] ?? null
            ),
            'share_text' => $this->nullableText(
                $identityCard['share_text'] ?? $identityCard['summary'] ?? $resultJson['summary'] ?? null
            ),
            'tags' => $this->stringList(
                $identityCard['tags'] ?? $profile['keywords'] ?? $resultJson['keywords'] ?? null
            ),
        ];

        $payload['dimensions'] = $this->buildDimensionsFromScoreMap(
            $this->arrayOrEmpty($report['scores_pct'] ?? null),
            $resolvedLocale,
            $this->arrayOrEmpty($result->scores_pct ?? null)
        );
        $payload['sections'] = $this->buildOverviewSections(
            $payload['summary_card']['title'],
            $this->firstNonEmpty(
                $identityCard['share_text'] ?? null,
                $identityCard['summary'] ?? null,
                $identityLayer['one_liner'] ?? null,
                $profile['short_summary'] ?? null,
                $resultJson['summary'] ?? null
            )
        );
        $payload['offer_set'] = $this->buildOfferSet($reportEnvelope);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $sharePayload
     * @param  array<string, mixed>|null  $reportPayload
     * @return array<string, mixed>
     */
    public function buildFromSharePayload(array $sharePayload, ?array $reportPayload = null, ?string $locale = null): array
    {
        $report = $this->arrayOrEmpty($reportPayload);
        $profile = $this->arrayOrEmpty($report['profile'] ?? null);
        $identityCard = $this->arrayOrEmpty($report['identity_card'] ?? null);
        $identityLayer = $this->arrayOrEmpty(data_get($report, 'layers.identity'));
        $resolvedLocale = $this->normalizeLocale(
            $locale ?? $this->nullableText($sharePayload['locale'] ?? null)
        );
        $payload = $this->scaffold($this->firstNonEmpty(
            $sharePayload['type_code'] ?? null,
            $sharePayload['runtime_type_code'] ?? null,
            $profile['type_code'] ?? null,
            $identityCard['type_code'] ?? null,
        ));

        $payload['profile'] = [
            'type_name' => $this->nullableText(
                $sharePayload['type_name'] ?? $profile['type_name'] ?? null
            ),
            'nickname' => $this->nullableText(
                $sharePayload['tagline'] ?? $profile['tagline'] ?? null
            ),
            'subtitle' => $this->nullableText(
                $sharePayload['subtitle'] ?? $identityLayer['subtitle'] ?? $identityCard['subtitle'] ?? null
            ),
            'summary' => $this->nullableText(
                $sharePayload['summary'] ?? $profile['short_summary'] ?? $identityCard['summary'] ?? null
            ),
            'rarity' => $this->nullableScalar(
                $sharePayload['rarity'] ?? $profile['rarity'] ?? null
            ),
            'keywords' => $this->stringList(
                $profile['keywords'] ?? null
            ),
        ];

        $payload['summary_card'] = [
            'title' => $this->nullableText(
                $sharePayload['title'] ?? $identityCard['title'] ?? $identityLayer['title'] ?? null
            ),
            'subtitle' => $this->nullableText(
                $sharePayload['subtitle'] ?? $identityCard['subtitle'] ?? $identityLayer['subtitle'] ?? null
            ),
            'share_text' => $this->nullableText(
                $sharePayload['summary'] ?? $identityCard['share_text'] ?? $identityCard['summary'] ?? null
            ),
            'tags' => $this->stringList(
                $sharePayload['tags'] ?? $identityCard['tags'] ?? null
            ),
        ];

        $payload['dimensions'] = $this->buildDimensionsFromSharePayload(
            $sharePayload,
            $report,
            $resolvedLocale
        );
        $payload['sections'] = $this->buildOverviewSections(
            $payload['summary_card']['title'],
            $this->firstNonEmpty(
                $identityCard['share_text'] ?? null,
                $identityCard['summary'] ?? null,
                $identityLayer['one_liner'] ?? null,
                $sharePayload['summary'] ?? null,
                $profile['short_summary'] ?? null
            )
        );

        return $payload;
    }

    /**
     * @param  array<string, mixed>|null  $comparePayload
     * @return array<string, mixed>
     */
    public function buildFromComparePayload(?array $comparePayload, ?string $locale = null): array
    {
        $payload = $this->scaffold();
        $compare = $this->arrayOrEmpty($comparePayload);
        $resolvedLocale = $this->normalizeLocale($locale);

        $payload['summary_card'] = [
            'title' => $this->nullableText($compare['title'] ?? null),
            'subtitle' => null,
            'share_text' => $this->nullableText($compare['summary'] ?? null),
            'tags' => [],
        ];
        $payload['dimensions'] = $this->buildDimensionsFromCompareAxes(
            $this->arrayValues($compare['axes'] ?? null),
            $resolvedLocale
        );
        $payload['sections'] = $this->buildOverviewSections(
            $payload['summary_card']['title'],
            $payload['summary_card']['share_text']
        );

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $reportEnvelope
     * @return array<string, mixed>
     */
    private function buildOfferSet(array $reportEnvelope): array
    {
        $offers = $this->arrayValues($reportEnvelope['offers'] ?? null);
        $cta = $this->arrayOrNull($reportEnvelope['cta'] ?? null);
        $firstOffer = is_array($offers[0] ?? null) ? $offers[0] : [];

        return [
            'offer_key' => $this->nullableText(
                $firstOffer['sku'] ?? $cta['target_sku_effective'] ?? $cta['target_sku'] ?? null
            ),
            'upgrade_sku' => $this->nullableText(
                $reportEnvelope['upgrade_sku_effective'] ?? $reportEnvelope['upgrade_sku'] ?? $cta['target_sku_effective'] ?? $cta['target_sku'] ?? null
            ),
            'modules_allowed' => $this->stringList($reportEnvelope['modules_allowed'] ?? null),
            'modules_preview' => $this->stringList($reportEnvelope['modules_preview'] ?? null),
            'view_policy' => $this->arrayOrNull($reportEnvelope['view_policy'] ?? null),
            'cta' => $cta,
        ];
    }

    /**
     * @param  array<string, mixed>  $sharePayload
     * @param  array<string, mixed>  $reportPayload
     * @return list<array<string, mixed>>
     */
    private function buildDimensionsFromSharePayload(array $sharePayload, array $reportPayload, string $locale): array
    {
        $scoreMap = $this->normalizeScoreMap($this->arrayOrEmpty($reportPayload['scores_pct'] ?? null));
        if ($scoreMap !== []) {
            return $this->buildDimensionsFromScoreMap($scoreMap, $locale);
        }

        $dimensions = [];
        foreach ($this->arrayValues($sharePayload['dimensions'] ?? null) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $axisId = $this->normalizeAxisId((string) ($item['id'] ?? $item['code'] ?? ''));
            if ($axisId === null) {
                continue;
            }

            $definition = self::AXIS_DEFINITIONS[$axisId]['locales'][$locale];
            $letters = self::AXIS_DEFINITIONS[$axisId]['letters'];
            $valuePct = null;

            if (is_numeric($item['value_pct'] ?? null)) {
                $valuePct = $this->normalizePercentValue($item['value_pct']);
            } else {
                $dominantPct = $this->normalizePercentValue($item['pct'] ?? $item['percent'] ?? null);
                $side = strtoupper(trim((string) ($item['side'] ?? '')));
                if ($dominantPct !== null) {
                    if ($side === $letters['left']) {
                        $valuePct = $dominantPct;
                    } elseif ($side === $letters['right']) {
                        $valuePct = 100 - $dominantPct;
                    }
                }
            }

            $dimensions[$axisId] = [
                'id' => $axisId,
                'label' => $this->nullableText($item['label'] ?? $item['axis_label'] ?? null) ?? $definition['label'],
                'axis_left' => $definition['left'],
                'axis_right' => $definition['right'],
                'summary' => $this->nullableText($item['summary'] ?? null),
                'value_pct' => $valuePct,
            ];
        }

        return $this->orderedDimensions($dimensions);
    }

    /**
     * @param  array<string, mixed>  $scoresPct
     * @param  array<string, mixed>  $fallbackScoresPct
     * @return list<array<string, mixed>>
     */
    private function buildDimensionsFromScoreMap(array $scoresPct, string $locale, array $fallbackScoresPct = []): array
    {
        $normalized = $this->normalizeScoreMap($scoresPct);
        if ($normalized === []) {
            $normalized = $this->normalizeScoreMap($fallbackScoresPct);
        }

        $dimensions = [];
        foreach (self::AXIS_ORDER as $axisId) {
            if (! array_key_exists($axisId, $normalized)) {
                continue;
            }

            $definition = self::AXIS_DEFINITIONS[$axisId]['locales'][$locale];
            $dimensions[$axisId] = [
                'id' => $axisId,
                'label' => $definition['label'],
                'axis_left' => $definition['left'],
                'axis_right' => $definition['right'],
                'summary' => null,
                'value_pct' => $normalized[$axisId],
            ];
        }

        return $this->orderedDimensions($dimensions);
    }

    /**
     * @param  list<mixed>  $axes
     * @return list<array<string, mixed>>
     */
    private function buildDimensionsFromCompareAxes(array $axes, string $locale): array
    {
        $dimensions = [];
        foreach ($axes as $item) {
            if (! is_array($item)) {
                continue;
            }

            $axisId = $this->normalizeAxisId((string) ($item['id'] ?? $item['code'] ?? $item['key'] ?? ''));
            if ($axisId === null) {
                continue;
            }

            $definition = self::AXIS_DEFINITIONS[$axisId]['locales'][$locale];
            $dimensions[$axisId] = [
                'id' => $axisId,
                'label' => $this->nullableText($item['label'] ?? $item['title'] ?? null) ?? $definition['label'],
                'axis_left' => $definition['left'],
                'axis_right' => $definition['right'],
                'summary' => $this->nullableText($item['summary'] ?? $item['description'] ?? $item['text'] ?? null),
                'value_pct' => null,
            ];
        }

        return $this->orderedDimensions($dimensions);
    }

    /**
     * @param  array<string, array<string, mixed>>  $dimensions
     * @return list<array<string, mixed>>
     */
    private function orderedDimensions(array $dimensions): array
    {
        $ordered = [];
        foreach (self::AXIS_ORDER as $axisId) {
            if (isset($dimensions[$axisId])) {
                $ordered[] = $dimensions[$axisId];
            }
        }

        return $ordered;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildOverviewSections(?string $title, ?string $paragraph): array
    {
        $normalizedParagraph = $this->nullableText($paragraph);
        $normalizedTitle = $this->nullableText($title);
        if ($normalizedTitle === null && $normalizedParagraph === null) {
            return [];
        }

        return [[
            'key' => 'overview',
            'render' => 'paragraphs',
            'title' => $normalizedTitle,
            'is_premium' => false,
            'is_preview' => false,
            'payload' => $normalizedParagraph === null
                ? null
                : ['paragraphs' => [$normalizedParagraph]],
        ]];
    }

    /**
     * @return array{runtime_type_code:?string,canonical_type_16:?string,display_type:?string,variant:?string}
     */
    private function normalizeTypeIdentity(?string $runtimeTypeCode): array
    {
        $raw = trim((string) $runtimeTypeCode);
        if ($raw === '') {
            return [
                'runtime_type_code' => null,
                'canonical_type_16' => null,
                'display_type' => null,
                'variant' => null,
            ];
        }

        $candidate = strtoupper($raw);
        if (preg_match('/^(?<base>[EI][SN][TF][JP])(?:-(?<variant>[AT]))?$/', $candidate, $matches) === 1) {
            $base = (string) $matches['base'];
            $variant = isset($matches['variant']) && $matches['variant'] !== ''
                ? (string) $matches['variant']
                : null;
            $resolved = $variant !== null ? $base.'-'.$variant : $base;

            return [
                'runtime_type_code' => $resolved,
                'canonical_type_16' => $base,
                'display_type' => $resolved,
                'variant' => $variant,
            ];
        }

        return [
            'runtime_type_code' => $raw,
            'canonical_type_16' => null,
            'display_type' => $raw,
            'variant' => null,
        ];
    }

    private function normalizeLocale(?string $locale): string
    {
        return trim((string) $locale) === 'zh-CN' ? 'zh-CN' : 'en';
    }

    /**
     * @param  array<string, mixed>  $scores
     * @return array<string, int>
     */
    private function normalizeScoreMap(array $scores): array
    {
        $normalized = [];
        foreach ($scores as $axisCode => $value) {
            $axisId = $this->normalizeAxisId((string) $axisCode);
            $percent = $this->normalizePercentValue($value);
            if ($axisId === null || $percent === null) {
                continue;
            }

            $normalized[$axisId] = $percent;
        }

        return $normalized;
    }

    private function normalizeAxisId(string $axisCode): ?string
    {
        return match (strtoupper(trim($axisCode))) {
            'EI' => 'EI',
            'SN', 'NS' => 'SN',
            'TF', 'FT' => 'TF',
            'JP' => 'JP',
            'AT' => 'AT',
            default => null,
        };
    }

    private function normalizePercentValue(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $normalized = (float) $value;
        if ($normalized <= 1.0) {
            $normalized *= 100.0;
        }

        return max(0, min(100, (int) round($normalized)));
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayOrEmpty(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function arrayOrNull(mixed $value): ?array
    {
        $normalized = $this->arrayOrEmpty($value);

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @return list<mixed>
     */
    private function arrayValues(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function nullableScalar(mixed $value): string|int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = trim($value);

            return $normalized === '' ? null : $normalized;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $normalized = $this->nullableText($item);
            if ($normalized === null) {
                continue;
            }

            $items[$normalized] = true;
        }

        return array_keys($items);
    }

    private function firstNonEmpty(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $normalized = $this->nullableText($value);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }
}
