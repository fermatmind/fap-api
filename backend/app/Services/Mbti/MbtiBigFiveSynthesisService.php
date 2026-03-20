<?php

declare(strict_types=1);

namespace App\Services\Mbti;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\BigFive\BigFivePublicProjectionService;

final class MbtiBigFiveSynthesisService
{
    private const VERSION = 'mbti_big5.cross_assessment.v1';

    /**
     * @var list<string>
     */
    private const TARGET_SECTION_KEYS = [
        'growth.stability_confidence',
        'growth.next_actions',
    ];

    public function __construct(
        private readonly BigFivePublicProjectionService $bigFivePublicProjectionService,
    ) {}

    /**
     * @param  array<string, mixed>  $personalization
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function attach(array $personalization, array $context = []): array
    {
        if ($personalization === []) {
            return [];
        }

        $resolved = $this->resolveLatestBigFiveSubjectResult($context);
        if ($resolved === null) {
            return $personalization;
        }

        $locale = $this->normalizeLocale((string) ($personalization['locale'] ?? $context['locale'] ?? 'zh-CN'));
        $projection = $this->bigFivePublicProjectionService->buildFromResult(
            $resolved['result'],
            $locale
        );
        $authority = $this->buildAuthorityFromProjection(
            $projection,
            $personalization,
            $locale,
            (string) ($resolved['attempt']->id ?? '')
        );

        if ($authority === []) {
            return $personalization;
        }

        $personalization['cross_assessment_v1'] = $authority;
        $personalization['synthesis_keys'] = $authority['synthesis_keys'];
        $personalization['supporting_scales'] = $authority['supporting_scales'];
        $personalization['big5_influence_keys'] = $authority['big5_influence_keys'];
        $personalization['mbti_adjusted_focus_keys'] = $authority['mbti_adjusted_focus_keys'];

        $sections = is_array($personalization['sections'] ?? null) ? $personalization['sections'] : [];
        $variantKeys = is_array($personalization['variant_keys'] ?? null) ? $personalization['variant_keys'] : [];
        foreach ((array) ($authority['section_enhancements'] ?? []) as $sectionKey => $enhancement) {
            if (! is_array($enhancement)) {
                continue;
            }

            $synthesisKey = trim((string) ($enhancement['synthesis_key'] ?? ''));
            if ($synthesisKey === '') {
                continue;
            }

            if (is_array($sections[$sectionKey] ?? null)) {
                $existingVariantKey = trim((string) ($sections[$sectionKey]['variant_key'] ?? ''));
                $sections[$sectionKey]['variant_key'] = $this->appendSynthesisSuffix($existingVariantKey, $synthesisKey);
                $sections[$sectionKey]['synthesis_key'] = $synthesisKey;
                $sections[$sectionKey]['supporting_scale'] = trim((string) ($enhancement['supporting_scale'] ?? 'BIG5_OCEAN'));
                $sections[$sectionKey]['cross_assessment_version'] = self::VERSION;
            }

            $variantKeys[$sectionKey] = $this->appendSynthesisSuffix(
                trim((string) ($variantKeys[$sectionKey] ?? ($sections[$sectionKey]['variant_key'] ?? ''))),
                $synthesisKey
            );
        }

        $personalization['sections'] = $sections;
        $personalization['variant_keys'] = $variantKeys;

        return $personalization;
    }

    /**
     * @param  array<string, mixed>  $big5Projection
     * @param  array<string, mixed>  $personalization
     * @return array<string, mixed>
     */
    public function buildAuthorityFromProjection(
        array $big5Projection,
        array $personalization,
        string $locale,
        string $supportingAttemptId = ''
    ): array {
        $traitBands = is_array($big5Projection['trait_bands'] ?? null) ? $big5Projection['trait_bands'] : [];
        if ($traitBands === []) {
            return [];
        }

        $neuroticismBand = $this->normalizeBand((string) ($traitBands['N'] ?? 'mid'));
        $conscientiousnessBand = $this->normalizeBand((string) ($traitBands['C'] ?? 'mid'));

        $stabilityEnhancement = $this->buildStabilityEnhancement($neuroticismBand, $locale);
        $actionEnhancement = $this->buildNextActionEnhancement($conscientiousnessBand, $locale);
        $dominantTraits = is_array($big5Projection['dominant_traits'] ?? null) ? $big5Projection['dominant_traits'] : [];

        $synthesisKeys = array_values(array_filter([
            (string) ($stabilityEnhancement['synthesis_key'] ?? ''),
            (string) ($actionEnhancement['synthesis_key'] ?? ''),
        ]));

        return [
            'version' => self::VERSION,
            'supporting_scales' => ['BIG5_OCEAN'],
            'supporting_attempt_id' => $supportingAttemptId,
            'synthesis_keys' => $synthesisKeys,
            'big5_influence_keys' => [
                sprintf('big5.band.n.%s', $neuroticismBand),
                sprintf('big5.band.c.%s', $conscientiousnessBand),
            ],
            'mbti_adjusted_focus_keys' => self::TARGET_SECTION_KEYS,
            'supporting_traits' => array_values(array_filter(array_map(
                static fn (array $trait): string => trim((string) ($trait['key'] ?? '')),
                array_slice($dominantTraits, 0, 3)
            ))),
            'section_enhancements' => [
                'growth.stability_confidence' => $stabilityEnhancement,
                'growth.next_actions' => $actionEnhancement,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{attempt:Attempt,result:Result}|null
     */
    private function resolveLatestBigFiveSubjectResult(array $context): ?array
    {
        $orgId = (int) ($context['org_id'] ?? 0);
        $userId = trim((string) ($context['user_id'] ?? ''));
        $anonId = trim((string) ($context['anon_id'] ?? ''));
        if ($userId === '' && $anonId === '') {
            return null;
        }

        $query = Result::query()
            ->select('results.*')
            ->join('attempts', 'attempts.id', '=', 'results.attempt_id')
            ->where('results.org_id', $orgId)
            ->where('results.scale_code', 'BIG5_OCEAN')
            ->where('results.is_valid', true);

        if ($userId !== '') {
            $query->where('attempts.user_id', $userId);
        } else {
            $query->where('attempts.anon_id', $anonId);
        }

        /** @var Result|null $result */
        $result = $query
            ->orderByDesc('attempts.submitted_at')
            ->orderByDesc('results.computed_at')
            ->first();

        if (! $result instanceof Result) {
            return null;
        }

        $attempt = Attempt::query()
            ->where('org_id', $orgId)
            ->where('id', (string) $result->attempt_id)
            ->first();

        if (! $attempt instanceof Attempt) {
            return null;
        }

        return [
            'attempt' => $attempt,
            'result' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStabilityEnhancement(string $band, string $locale): array
    {
        [$headline, $body, $synthesisKey] = match ($band) {
            'high' => [
                $locale === 'zh-CN' ? 'Big Five 补充：高情绪性会放大情境敏感' : 'Big Five add-on: high neuroticism amplifies context shifts',
                $locale === 'zh-CN'
                    ? 'Big Five 显示你的情绪性更高，这会放大 MBTI 里“情境敏感型稳定”的体感强度。更有帮助的读法不是怀疑类型本身，而是提前给恢复窗口、节奏减压和情绪缓冲留出位置。'
                    : 'Your Big Five result points to higher neuroticism, which can intensify how MBTI context-sensitive stability feels in daily life. The useful read is not to question the type itself, but to create recovery room, lower-friction pacing, and emotional buffering earlier.',
                'big5.neuroticism.high.buffer_reactivity',
            ],
            'low' => [
                $locale === 'zh-CN' ? 'Big Five 补充：低情绪性会托住稳定感' : 'Big Five add-on: low neuroticism supports steadiness',
                $locale === 'zh-CN'
                    ? 'Big Five 显示你的情绪性更低，这会帮助你在 MBTI 的近边界切换里更快回到可用区。你仍然会在情境里切换表达方式，但更容易保持内在稳定感。'
                    : 'Your Big Five result points to lower neuroticism, which helps you recover faster when MBTI boundary shifts show up. You can still switch styles across contexts, but you are more likely to keep an inner sense of steadiness.',
                'big5.neuroticism.low.reinforce_stability',
            ],
            default => [
                $locale === 'zh-CN' ? 'Big Five 补充：中段情绪性决定了稳定窗口' : 'Big Five add-on: mid neuroticism shapes the stability window',
                $locale === 'zh-CN'
                    ? 'Big Five 显示你的情绪性位于中段，这意味着 MBTI 里的稳定与切换会更受情境负荷影响。最关键的不是改掉切换，而是更早识别什么场景会把你推向边界。'
                    : 'Your Big Five result sits in the middle range for neuroticism, which means the MBTI stability pattern is more sensitive to context load. The important move is not eliminating the shift, but noticing earlier which situations push you toward the edge.',
                'big5.neuroticism.mid.context_window',
            ],
        };

        return [
            'section_key' => 'growth.stability_confidence',
            'supporting_scale' => 'BIG5_OCEAN',
            'synthesis_key' => $synthesisKey,
            'title' => $headline,
            'body' => $body,
            'influence_keys' => [sprintf('big5.band.n.%s', $band)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildNextActionEnhancement(string $band, string $locale): array
    {
        [$headline, $body, $synthesisKey] = match ($band) {
            'high' => [
                $locale === 'zh-CN' ? 'Big Five 补充：高尽责性适合结构化推进' : 'Big Five add-on: high conscientiousness favors structured follow-through',
                $locale === 'zh-CN'
                    ? 'Big Five 显示你的尽责性更高，所以 MBTI 的下一步动作最适合被压成固定节奏、明确标准和可追踪清单。比起一次做很多，持续复用同一套动作模板会更快见效。'
                    : 'Your Big Five result points to higher conscientiousness, so MBTI next actions work best when compressed into fixed rhythm, explicit standards, and trackable checklists. Reusing the same action template beats trying too many moves at once.',
                'big5.conscientiousness.high.use_structured_sprints',
            ],
            'low' => [
                $locale === 'zh-CN' ? 'Big Five 补充：低尽责性更需要外部支架' : 'Big Five add-on: low conscientiousness needs external scaffolding',
                $locale === 'zh-CN'
                    ? 'Big Five 显示你的尽责性更低，所以 MBTI 的下一步动作不要依赖纯意志力。把动作拆成更小的可逆步骤，再借助外部提醒、同伴反馈或固定触发器，执行会稳定很多。'
                    : 'Your Big Five result points to lower conscientiousness, so MBTI next actions should not rely on willpower alone. Smaller reversible steps plus reminders, social accountability, or fixed triggers will make execution much more stable.',
                'big5.conscientiousness.low.use_external_scaffolding',
            ],
            default => [
                $locale === 'zh-CN' ? 'Big Five 补充：中段尽责性适合轻量节奏' : 'Big Five add-on: mid conscientiousness favors light structure',
                $locale === 'zh-CN'
                    ? 'Big Five 显示你的尽责性位于中段，因此 MBTI 的下一步动作最适合轻量但连续的节奏。给动作一个可重复的起点和结束条件，比追求完美计划更有效。'
                    : 'Your Big Five result sits in the middle range for conscientiousness, so MBTI next actions work best with light but repeatable structure. A reliable start cue and stop condition will help more than trying to design the perfect plan.',
                'big5.conscientiousness.mid.repeat_light_structure',
            ],
        };

        return [
            'section_key' => 'growth.next_actions',
            'supporting_scale' => 'BIG5_OCEAN',
            'synthesis_key' => $synthesisKey,
            'title' => $headline,
            'body' => $body,
            'influence_keys' => [sprintf('big5.band.c.%s', $band)],
        ];
    }

    private function appendSynthesisSuffix(string $variantKey, string $synthesisKey): string
    {
        $normalizedSynthesis = trim((string) preg_replace('/[^a-z0-9]+/i', '_', strtolower($synthesisKey)));
        if ($normalizedSynthesis === '') {
            return $variantKey;
        }

        if (str_contains($variantKey, ':synth.'.$normalizedSynthesis)) {
            return $variantKey;
        }

        return $variantKey === ''
            ? 'synth.'.$normalizedSynthesis
            : $variantKey.':synth.'.$normalizedSynthesis;
    }

    private function normalizeBand(string $band): string
    {
        $normalized = strtolower(trim($band));

        return in_array($normalized, ['low', 'mid', 'high'], true) ? $normalized : 'mid';
    }

    private function normalizeLocale(string $locale): string
    {
        return str_starts_with(strtolower(trim($locale)), 'zh') ? 'zh-CN' : 'en';
    }
}
