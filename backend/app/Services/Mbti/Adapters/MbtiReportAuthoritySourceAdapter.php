<?php

declare(strict_types=1);

namespace App\Services\Mbti\Adapters;

use App\Contracts\MbtiPublicResultAuthoritySource;
use App\Support\Mbti\MbtiCanonicalSectionRegistry;
use App\Support\Mbti\MbtiPublicTypeIdentity;

final readonly class MbtiReportAuthoritySourceAdapter implements MbtiPublicResultAuthoritySource
{
    /**
     * @param  array<string, mixed>  $reportPayload
     */
    public function __construct(
        private array $reportPayload,
        private string $sourceKeyLabel = 'report.v0_3.pilot',
    ) {}

    public function sourceKey(): string
    {
        return $this->sourceKeyLabel;
    }

    /**
     * @return array{
     *   resolved_type_code:string,
     *   profile:array<string,mixed>,
     *   sections:array<string,array<string,mixed>>,
     *   premium_teaser:array<string,array<string,mixed>>,
     *   seo_meta:array<string,mixed>,
     *   meta:array<string,mixed>
     * }
     */
    public function read(MbtiPublicTypeIdentity $identity): array
    {
        $profile = $this->arrayOrEmpty($this->reportPayload['profile'] ?? null);
        $layers = $this->arrayOrEmpty($this->reportPayload['layers'] ?? null);
        $identityLayer = $this->arrayOrEmpty($layers['identity'] ?? null);
        $sections = $this->arrayOrEmpty($this->reportPayload['sections'] ?? null);

        return [
            'resolved_type_code' => (string) ($profile['type_code'] ?? $identity->typeCode),
            'profile' => [
                'hero_summary' => $profile['short_summary'] ?? null,
            ],
            'sections' => [
                'overview' => [
                    'title' => $identityLayer['title'] ?? 'Overview',
                    'body' => $identityLayer['one_liner'] ?? null,
                ],
                'trait_overview' => [
                    'payload' => [
                        'summary' => $identityLayer['subtitle'] ?? null,
                        'dimensions' => $this->buildTraitDimensions(),
                    ],
                ],
                'traits.why_this_type' => [
                    'body' => null,
                ],
                'traits.close_call_axes' => [
                    'body' => null,
                ],
                'traits.adjacent_type_contrast' => [
                    'body' => null,
                ],
                'traits.decision_style' => [
                    'body' => null,
                ],
                'career.summary' => [
                    'body' => $this->extractFirstCardBody($sections['career'] ?? null),
                ],
                'career.collaboration_fit' => [
                    'body' => null,
                ],
                'career.work_environment' => [
                    'body' => null,
                ],
                'career.work_experiments' => [
                    'body' => null,
                ],
                'growth.summary' => [
                    'body' => $this->extractFirstCardBody($sections['growth'] ?? null),
                ],
                'growth.stability_confidence' => [
                    'body' => null,
                ],
                'growth.next_actions' => [
                    'body' => null,
                ],
                'growth.weekly_experiments' => [
                    'body' => null,
                ],
                'growth.stress_recovery' => [
                    'body' => null,
                ],
                'growth.watchouts' => [
                    'body' => null,
                ],
                'relationships.summary' => [
                    'body' => $this->extractFirstCardBody($sections['relationships'] ?? null),
                ],
                'relationships.communication_style' => [
                    'body' => null,
                ],
                'relationships.try_this_week' => [
                    'body' => null,
                ],
                'career.next_step' => [
                    'body' => null,
                ],
            ],
            'premium_teaser' => [
                'growth.motivators' => [
                    'render_variant' => MbtiCanonicalSectionRegistry::RENDER_VARIANT_PREMIUM_TEASER,
                    'title' => 'Growth motivators',
                    'teaser' => null,
                ],
                'growth.drainers' => [
                    'render_variant' => MbtiCanonicalSectionRegistry::RENDER_VARIANT_PREMIUM_TEASER,
                    'title' => 'Growth drainers',
                    'teaser' => null,
                ],
                'relationships.rel_advantages' => [
                    'render_variant' => MbtiCanonicalSectionRegistry::RENDER_VARIANT_PREMIUM_TEASER,
                    'title' => 'Relationship advantages',
                    'teaser' => null,
                ],
                'relationships.rel_risks' => [
                    'render_variant' => MbtiCanonicalSectionRegistry::RENDER_VARIANT_PREMIUM_TEASER,
                    'title' => 'Relationship risks',
                    'teaser' => null,
                ],
            ],
            'seo_meta' => [],
            'meta' => [
                'adapter' => $this->sourceKey(),
                'pilot' => true,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildTraitDimensions(): array
    {
        $scoresPct = $this->arrayOrEmpty($this->reportPayload['scores_pct'] ?? null);
        $dimensions = [];

        foreach ($scoresPct as $axisCode => $pct) {
            $axisKey = strtoupper(trim((string) $axisCode));

            $dimensions[] = [
                'axis_code' => $axisKey,
                'normalized_axis_code' => MbtiCanonicalSectionRegistry::normalizeTraitAxisCode($axisKey),
                'percent' => is_numeric($pct) ? (float) $pct : null,
            ];
        }

        return $dimensions;
    }

    private function extractFirstCardBody(mixed $section): ?string
    {
        $sectionData = $this->arrayOrEmpty($section);
        $cards = $sectionData['cards'] ?? null;
        if (! is_array($cards) || $cards === []) {
            return null;
        }

        $firstCard = is_array($cards[0] ?? null) ? $cards[0] : [];

        foreach (['body', 'summary', 'title'] as $key) {
            $value = trim((string) ($firstCard[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayOrEmpty(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
