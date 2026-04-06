<?php

declare(strict_types=1);

namespace App\Services\PublicSurface;

final class AnswerSurfaceContractService
{
    /**
     * @param  array{
     *   answer_scope:string,
     *   surface_type:string,
     *   summary_blocks?:array<int,array<string,mixed>>,
     *   faq_blocks?:array<int,array<string,mixed>>,
     *   compare_blocks?:array<int,array<string,mixed>>,
     *   scene_summary_blocks?:array<int,array<string,mixed>>,
     *   next_step_blocks?:array<int,array<string,mixed>>,
     *   answer_bundle?:array<int,array<string,mixed>>,
     *   evidence_refs?:array<int,string>,
     *   public_safety_state?:?string,
     *   indexability_state?:?string,
     *   attribution_scope?:?string,
     *   seo_surface_ref?:?string,
     *   landing_surface_ref?:?string,
     *   public_surface_ref?:?string,
     *   primary_content_ref?:?string,
     *   related_surface_keys?:array<int,string>,
     *   runtime_artifact_ref?:?string,
     *   fingerprint_seed?:array<string,mixed>
     * } $context
     * @return array<string,mixed>
     */
    public function build(array $context): array
    {
        $answerScope = $this->normalizeString($context['answer_scope'] ?? null) ?? 'public_indexable_detail';
        $surfaceType = $this->normalizeString($context['surface_type'] ?? null) ?? 'public_answer_surface';
        $summaryBlocks = $this->normalizeContentBlocks($context['summary_blocks'] ?? []);
        $faqBlocks = $this->normalizeFaqBlocks($context['faq_blocks'] ?? []);
        $compareBlocks = $this->normalizeContentBlocks($context['compare_blocks'] ?? []);
        $sceneSummaryBlocks = $this->normalizeContentBlocks($context['scene_summary_blocks'] ?? []);
        $nextStepBlocks = $this->normalizeActionBlocks($context['next_step_blocks'] ?? []);
        $answerBundle = $this->normalizeBundle(
            $context['answer_bundle'] ?? $this->defaultAnswerBundle(
                $summaryBlocks,
                $faqBlocks,
                $compareBlocks,
                $sceneSummaryBlocks,
                $nextStepBlocks
            )
        );
        $evidenceRefs = $this->normalizeStringList($context['evidence_refs'] ?? []);
        $publicSafetyState = $this->normalizeString($context['public_safety_state'] ?? null)
            ?? ($answerScope === 'public_share_safe' ? 'public_share_safe' : 'public_indexable');
        $indexabilityState = $this->normalizeString($context['indexability_state'] ?? null)
            ?? ($answerScope === 'public_share_safe' ? 'noindex' : 'indexable');
        $attributionScope = $this->normalizeString($context['attribution_scope'] ?? null)
            ?? ($answerScope === 'public_share_safe' ? 'share_public_surface' : 'public_answer_surface');
        $seoSurfaceRef = $this->normalizeString($context['seo_surface_ref'] ?? null);
        $landingSurfaceRef = $this->normalizeString($context['landing_surface_ref'] ?? null);
        $publicSurfaceRef = $this->normalizeString($context['public_surface_ref'] ?? null);
        $primaryContentRef = $this->normalizeString($context['primary_content_ref'] ?? null);
        $relatedSurfaceKeys = $this->normalizeStringList($context['related_surface_keys'] ?? []);
        $runtimeArtifactRef = $this->normalizeString($context['runtime_artifact_ref'] ?? null);

        $fingerprintSeed = is_array($context['fingerprint_seed'] ?? null)
            ? $context['fingerprint_seed']
            : [];
        $fingerprintSeed['answer_scope'] = $answerScope;
        $fingerprintSeed['surface_type'] = $surfaceType;
        $fingerprintSeed['summary_blocks'] = $summaryBlocks;
        $fingerprintSeed['faq_blocks'] = $faqBlocks;
        $fingerprintSeed['compare_blocks'] = $compareBlocks;
        $fingerprintSeed['scene_summary_blocks'] = $sceneSummaryBlocks;
        $fingerprintSeed['next_step_blocks'] = $nextStepBlocks;
        $fingerprintSeed['answer_bundle'] = $answerBundle;
        $fingerprintSeed['evidence_refs'] = $evidenceRefs;
        $fingerprintSeed['public_safety_state'] = $publicSafetyState;
        $fingerprintSeed['indexability_state'] = $indexabilityState;
        $fingerprintSeed['attribution_scope'] = $attributionScope;
        $fingerprintSeed['seo_surface_ref'] = $seoSurfaceRef;
        $fingerprintSeed['landing_surface_ref'] = $landingSurfaceRef;
        $fingerprintSeed['public_surface_ref'] = $publicSurfaceRef;
        $fingerprintSeed['primary_content_ref'] = $primaryContentRef;
        $fingerprintSeed['related_surface_keys'] = $relatedSurfaceKeys;
        $fingerprintSeed['runtime_artifact_ref'] = $runtimeArtifactRef;

        return [
            'version' => 'answer.surface.v1',
            'answer_contract_version' => 'answer.surface.v1',
            'answer_fingerprint' => sha1((string) json_encode($fingerprintSeed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'answer_scope' => $answerScope,
            'surface_type' => $surfaceType,
            'summary_blocks' => $summaryBlocks,
            'faq_blocks' => $faqBlocks,
            'compare_blocks' => $compareBlocks,
            'scene_summary_blocks' => $sceneSummaryBlocks,
            'next_step_blocks' => $nextStepBlocks,
            'answer_bundle' => $answerBundle,
            'evidence_refs' => $evidenceRefs,
            'public_safety_state' => $publicSafetyState,
            'indexability_state' => $indexabilityState,
            'attribution_scope' => $attributionScope,
            'seo_surface_ref' => $seoSurfaceRef,
            'landing_surface_ref' => $landingSurfaceRef,
            'public_surface_ref' => $publicSurfaceRef,
            'primary_content_ref' => $primaryContentRef,
            'related_surface_keys' => $relatedSurfaceKeys,
            'runtime_artifact_ref' => $runtimeArtifactRef,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sections
     * @return list<array<string,string|null>>
     */
    public function extractFaqBlocksFromSectionPayloads(array $sections, int $limit = 4): array
    {
        $faqBlocks = [];

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $sectionKey = strtolower($this->normalizeString($section['section_key'] ?? null) ?? '');
            $renderVariant = strtolower($this->normalizeString($section['render_variant'] ?? null) ?? '');
            if ($sectionKey !== 'faq' && $renderVariant !== 'faq') {
                continue;
            }

            $payload = $this->normalizeArray($section['payload_json'] ?? null);
            $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $question = $this->normalizeString($item['question'] ?? $item['q'] ?? null);
                $answer = $this->normalizeString($item['answer'] ?? $item['a'] ?? null);
                if ($question === null && $answer === null) {
                    continue;
                }

                $faqBlocks[] = [
                    'key' => $this->normalizeString($item['key'] ?? null) ?? (($sectionKey !== '' ? $sectionKey : 'faq').'-'.$index),
                    'question' => $question,
                    'answer' => $answer,
                ];

                if (count($faqBlocks) >= $limit) {
                    return $faqBlocks;
                }
            }
        }

        return $faqBlocks;
    }

    /**
     * @param  array<int,array<string,mixed>>  $ctas
     * @return list<array<string,string|null>>
     */
    public function buildNextStepBlocksFromCtas(array $ctas, int $limit = 3): array
    {
        $blocks = [];

        foreach ($ctas as $cta) {
            if (! is_array($cta)) {
                continue;
            }

            $label = $this->normalizeString($cta['label'] ?? null);
            $href = $this->normalizeString($cta['href'] ?? null);
            if ($label === null || $href === null) {
                continue;
            }

            $blocks[] = [
                'key' => $this->normalizeString($cta['key'] ?? null) ?? $href,
                'title' => $label,
                'body' => $this->normalizeString($cta['body'] ?? null),
                'href' => $href,
                'kind' => $this->normalizeString($cta['kind'] ?? null),
            ];

            if (count($blocks) >= $limit) {
                break;
            }
        }

        return $blocks;
    }

    /**
     * @param  array<int,array<string,mixed>>  $dimensions
     * @return list<array<string,string|null>>
     */
    public function buildCompareBlocksFromDimensionPayloads(array $dimensions, int $limit = 2): array
    {
        $blocks = [];

        foreach ($dimensions as $dimension) {
            if (! is_array($dimension)) {
                continue;
            }

            $title = $this->normalizeString($dimension['label'] ?? $dimension['name'] ?? null);
            $body = $this->normalizeString($dimension['summary'] ?? $dimension['description'] ?? null);
            if ($title === null && $body === null) {
                continue;
            }

            $blocks[] = [
                'key' => $this->normalizeString($dimension['id'] ?? $dimension['code'] ?? null) ?? 'dimension_'.count($blocks),
                'title' => $title,
                'body' => $body,
                'kind' => 'dimension_compare',
            ];

            if (count($blocks) >= $limit) {
                break;
            }
        }

        return $blocks;
    }

    /**
     * @param  array<int,mixed>  $blocks
     * @return list<array<string,string|null>>
     */
    private function normalizeContentBlocks(array $blocks): array
    {
        $normalized = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $key = $this->normalizeString($block['key'] ?? null);
            $title = $this->normalizeString($block['title'] ?? null);
            $body = $this->normalizeString($block['body'] ?? null);
            $href = $this->normalizeString($block['href'] ?? null);
            $kind = $this->normalizeString($block['kind'] ?? null);

            if ($key === null && $title === null && $body === null && $href === null) {
                continue;
            }

            $normalized[] = [
                'key' => $key ?? $title ?? $href ?? $body,
                'title' => $title,
                'body' => $body,
                'href' => $href,
                'kind' => $kind,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int,mixed>  $blocks
     * @return list<array<string,string|null>>
     */
    private function normalizeFaqBlocks(array $blocks): array
    {
        $normalized = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $key = $this->normalizeString($block['key'] ?? null);
            $question = $this->normalizeString($block['question'] ?? $block['q'] ?? null);
            $answer = $this->normalizeString($block['answer'] ?? $block['a'] ?? null);

            if ($key === null && $question === null && $answer === null) {
                continue;
            }

            $normalized[] = [
                'key' => $key ?? $question ?? $answer,
                'question' => $question,
                'answer' => $answer,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int,mixed>  $blocks
     * @return list<array<string,string|null>>
     */
    private function normalizeActionBlocks(array $blocks): array
    {
        $normalized = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $key = $this->normalizeString($block['key'] ?? null);
            $title = $this->normalizeString($block['title'] ?? null);
            $body = $this->normalizeString($block['body'] ?? null);
            $href = $this->normalizeString($block['href'] ?? null);
            $kind = $this->normalizeString($block['kind'] ?? null);

            if ($key === null && $title === null && $body === null && $href === null) {
                continue;
            }

            $normalized[] = [
                'key' => $key ?? $title ?? $href ?? $body,
                'title' => $title,
                'body' => $body,
                'href' => $href,
                'kind' => $kind,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int,mixed>  $bundle
     * @return list<array<string,int|string>>
     */
    private function normalizeBundle(array $bundle): array
    {
        $normalized = [];

        foreach ($bundle as $item) {
            if (! is_array($item)) {
                continue;
            }

            $key = $this->normalizeString($item['key'] ?? null);
            if ($key === null) {
                continue;
            }

            $title = $this->normalizeString($item['title'] ?? null) ?? $key;
            $count = max(0, (int) ($item['count'] ?? 0));

            $normalized[] = [
                'key' => $key,
                'title' => $title,
                'count' => $count,
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array<string,string|null>>  $summaryBlocks
     * @param  list<array<string,string|null>>  $faqBlocks
     * @param  list<array<string,string|null>>  $compareBlocks
     * @param  list<array<string,string|null>>  $sceneSummaryBlocks
     * @param  list<array<string,string|null>>  $nextStepBlocks
     * @return list<array<string,int|string>>
     */
    private function defaultAnswerBundle(
        array $summaryBlocks,
        array $faqBlocks,
        array $compareBlocks,
        array $sceneSummaryBlocks,
        array $nextStepBlocks
    ): array {
        $bundle = [];

        foreach ([
            'summary' => count($summaryBlocks),
            'faq' => count($faqBlocks),
            'compare' => count($compareBlocks),
            'scene_summary' => count($sceneSummaryBlocks),
            'next_step' => count($nextStepBlocks),
        ] as $key => $count) {
            if ($count <= 0) {
                continue;
            }

            $bundle[] = [
                'key' => $key,
                'title' => $key,
                'count' => $count,
            ];
        }

        return $bundle;
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param  array<int,mixed>  $values
     * @return list<string>
     */
    private function normalizeStringList(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $item = $this->normalizeString($value);
            if ($item === null) {
                continue;
            }

            $normalized[$item] = true;
        }

        return array_keys($normalized);
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
