<?php

namespace App\Services\Report;

use App\Models\Attempt;
use App\Models\Result;

use App\Services\Report\TagBuilder;
use App\Services\Report\SectionCardGenerator;
use App\Services\Report\HighlightsGenerator;
use App\Services\Overrides\HighlightsOverridesApplier;
use App\Services\Report\IdentityLayerBuilder;

use App\Domain\Score\AxisScore;

class ReportComposer
{
    public function __construct(
        private TagBuilder $tagBuilder,
        private SectionCardGenerator $cardGen,
        private HighlightsGenerator $highGen,
        private HighlightsOverridesApplier $overridesApplier,
        private IdentityLayerBuilder $identityLayerBuilder,
    ) {}

    /**
     * 组合报告（Controller 只负责调用它）
     *
     * @param string $attemptId
     * @param array $ctx 通过闭包注入 Controller 的私有 loader/helper（避免 composer 依赖 Controller）
     * @return array 统一返回：['ok'=>true,'type_code'=>...,'report'=>...] 或 ['ok'=>false,...]
     */
    public function compose(string $attemptId, array $ctx): array
    {
        // 1) Load Attempt + Result
        $attempt = Attempt::where('id', $attemptId)->first();
        $result  = Result::where('attempt_id', $attemptId)->first();

        if (!$result) {
            return [
                'ok' => false,
                'error' => 'RESULT_NOT_FOUND',
                'message' => 'Result not found for given attempt_id',
                'status' => 404,
            ];
        }

        // 版本信息：全部从 results/配置里取（不依赖前端）
        $profileVersion = $result->profile_version
            ?? ($ctx['defaultProfileVersion'] ?? 'mbti32-v2.5');

        $contentPackageVersion = $result->content_package_version
            ?? ($ctx['currentContentPackageVersion'] ? ($ctx['currentContentPackageVersion'])() : null);

        if (!is_string($contentPackageVersion) || $contentPackageVersion === '') {
            $contentPackageVersion = (string) ($ctx['defaultContentPackageVersion'] ?? 'MBTI-CN-v0.2.1-TEST');
        }

        $typeCode = (string) $result->type_code;

        // 2) Score（你当前版本：复用 results.scores_pct/axis_states；不现场重算）
        $dims = ['EI', 'SN', 'TF', 'JP', 'AT'];
        $scoresPct  = is_array($result->scores_pct ?? null) ? $result->scores_pct : [];
        $axisStates = is_array($result->axis_states ?? null) ? $result->axis_states : [];

        foreach ($dims as $d) {
            if (!array_key_exists($d, $scoresPct))  $scoresPct[$d]  = 50;
            if (!array_key_exists($d, $axisStates)) $axisStates[$d] = 'moderate';
        }

        // 3) Load Profile / IdentityCard
        $loadTypeProfile = $ctx['loadTypeProfile'] ?? null;
        $loadAssetItems  = $ctx['loadReportAssetItems'] ?? null;

        $profile = is_callable($loadTypeProfile)
            ? (array) $loadTypeProfile($contentPackageVersion, $typeCode)
            : [];

        // 6) identity_card（旧 assets：report_identity_cards.json）
        $identityItems = is_callable($loadAssetItems)
            ? (array) $loadAssetItems($contentPackageVersion, 'report_identity_cards.json', 'type_code')
            : [];
        $identityCard  = is_array($identityItems[$typeCode] ?? null) ? $identityItems[$typeCode] : null;

        // scores（稳定结构：pct/state/side/delta）
        $scores = $this->buildScoresValueObject($scoresPct, $dims);

        // 1) role/strategy（必须先算出来）
        $roleCard = is_callable($ctx['buildRoleCard'] ?? null)
            ? (array) ($ctx['buildRoleCard'])($contentPackageVersion, $typeCode)
            : [];

        $strategyCard = is_callable($ctx['buildStrategyCard'] ?? null)
            ? (array) ($ctx['buildStrategyCard'])($contentPackageVersion, $typeCode)
            : [];

        // 4) Build Tags（依赖 role/strategy）
        $tags = $this->tagBuilder->build($scores, [
            'role_card'     => $roleCard,
            'strategy_card' => $strategyCard,
        ]);

        // 6) Build Highlights（base + overrides）
        $baseHighlights = $this->highGen->generate($contentPackageVersion, $typeCode, $scores, [
            'role_card'     => $roleCard,
            'strategy_card' => $strategyCard,
            'tags'          => $tags,
        ]);

        $highlights = $this->overridesApplier->apply(
            $contentPackageVersion,
            $typeCode,
            $baseHighlights
        );

        // 8) borderline_note（给 identity micro 用）
        $borderlineNote = is_callable($ctx['buildBorderlineNote'] ?? null)
            ? (array) ($ctx['buildBorderlineNote'])($scoresPct, $contentPackageVersion)
            : ['items' => []];

        if (!is_array($borderlineNote['items'] ?? null)) $borderlineNote['items'] = [];

        // 8) Build layers.identity
        $identityLayer = $this->identityLayerBuilder->build(
            $contentPackageVersion,
            $typeCode,
            $scoresPct,
            $borderlineNote
        );

        // 5) Build Sections Cards
        $sections = [
            'traits' => [
                'cards' => $this->cardGen->generate('traits', $contentPackageVersion, $tags, $scores),
            ],
            'career' => [
                'cards' => $this->cardGen->generate('career', $contentPackageVersion, $tags, $scores),
            ],
            'growth' => [
                'cards' => $this->cardGen->generate('growth', $contentPackageVersion, $tags, $scores),
            ],
            'relationships' => [
                'cards' => $this->cardGen->generate('relationships', $contentPackageVersion, $tags, $scores),
            ],
        ];

        // 9) recommended_reads（放最后）
        $recommendedReads = is_callable($ctx['buildRecommendedReads'] ?? null)
            ? (array) ($ctx['buildRecommendedReads'])($contentPackageVersion, $typeCode, $scoresPct)
            : [];

        // 最终 report payload（schema 不变）
        $reportPayload = [
            'versions' => [
                'engine'                  => 'v1.2',
                'profile_version'         => $profileVersion,
                'content_package_version' => $contentPackageVersion,
            ],
            'scores' => $scores,
            'tags'   => $tags,
            'profile' => [
                'type_code'     => $profile['type_code'] ?? $typeCode,
                'type_name'     => $profile['type_name'] ?? null,
                'tagline'       => $profile['tagline'] ?? null,
                'rarity'        => $profile['rarity'] ?? null,
                'keywords'      => $profile['keywords'] ?? [],
                'short_summary' => $profile['short_summary'] ?? null,
            ],
            'identity_card'   => $identityCard,
            'highlights'      => $highlights,
            'borderline_note' => $borderlineNote,
            'layers' => [
                'role_card'     => $roleCard,
                'strategy_card' => $strategyCard,
                'identity'      => $identityLayer,
            ],
            'sections' => $sections,
            'recommended_reads' => $recommendedReads,
        ];

        return [
            'ok' => true,
            'attempt_id' => $attemptId,
            'type_code'  => $typeCode,
            'report'     => $reportPayload,
        ];
    }

    private function buildScoresValueObject(array $scoresPct, array $dims): array
    {
        $out = [];

        foreach ($dims as $dim) {
            $rawPct = (int) ($scoresPct[$dim] ?? 50);

            [$p1, $p2] = match ($dim) {
                'EI' => ['E','I'],
                'SN' => ['S','N'],
                'TF' => ['T','F'],
                'JP' => ['J','P'],
                'AT' => ['A','T'],
                default => ['',''],
            };

            $side = $rawPct >= 50 ? $p1 : $p2;
            $displayPct = $rawPct >= 50 ? $rawPct : (100 - $rawPct); // 50..100

            // 复用你现有 AxisScore（保持 state/delta 口径一致）
            $axis = AxisScore::fromPctAndSide($displayPct, $side);

            $out[$dim] = [
                'pct'   => $axis->pct,
                'state' => $axis->state,
                'side'  => $axis->side,
                'delta' => $axis->delta,
            ];
        }

        return $out;
    }
}