<?php

declare(strict_types=1);

namespace App\Services\PublicSurface;

final class LandingSurfaceContractService
{
    /**
     * @param  array{
     *   landing_scope:string,
     *   entry_surface:string,
     *   entry_type:string,
     *   summary_blocks?:array<int,array<string,mixed>>,
     *   discoverability_items?:array<int,array<string,mixed>>,
     *   discoverability_keys?:array<int,string>,
     *   continue_reading_keys?:array<int,string>,
     *   start_test_target?:?string,
     *   result_resume_target?:?string,
     *   content_continue_target?:?string,
     *   cta_bundle?:array<int,array<string,mixed>>,
     *   indexability_state?:?string,
     *   attribution_scope?:?string,
     *   seo_surface_ref?:?string,
     *   public_surface_ref?:?string,
     *   surface_family?:?string,
     *   primary_content_ref?:?string,
     *   related_surface_keys?:array<int,string>,
     *   share_safety_state?:?string,
     *   runtime_artifact_ref?:?string,
     *   fingerprint_seed?:array<string,mixed>
     * }  $context
     * @return array<string,mixed>
     */
    public function build(array $context): array
    {
        $landingScope = $this->normalizeString($context['landing_scope'] ?? null) ?? 'public_indexable_detail';
        $entrySurface = $this->normalizeString($context['entry_surface'] ?? null) ?? 'public_entry';
        $entryType = $this->normalizeString($context['entry_type'] ?? null) ?? 'public_content';
        $summaryBlocks = $this->normalizeSummaryBlocks($context['summary_blocks'] ?? []);
        $discoverabilityItems = $this->normalizeDiscoverabilityItems($context['discoverability_items'] ?? []);
        $discoverabilityKeys = $this->normalizeStringList($context['discoverability_keys'] ?? []);
        $continueReadingKeys = $this->normalizeStringList($context['continue_reading_keys'] ?? []);
        $startTestTarget = $this->normalizeString($context['start_test_target'] ?? null);
        $resultResumeTarget = $this->normalizeString($context['result_resume_target'] ?? null);
        $contentContinueTarget = $this->normalizeString($context['content_continue_target'] ?? null);
        $ctaBundle = $this->normalizeCtaBundle($context['cta_bundle'] ?? []);
        $indexabilityState = $this->normalizeString($context['indexability_state'] ?? null) ?? 'indexable';
        $attributionScope = $this->normalizeString($context['attribution_scope'] ?? null) ?? 'public_landing_surface';
        $seoSurfaceRef = $this->normalizeString($context['seo_surface_ref'] ?? null);
        $publicSurfaceRef = $this->normalizeString($context['public_surface_ref'] ?? null);
        $surfaceFamily = $this->normalizeString($context['surface_family'] ?? null);
        $primaryContentRef = $this->normalizeString($context['primary_content_ref'] ?? null);
        $relatedSurfaceKeys = $this->normalizeStringList($context['related_surface_keys'] ?? []);
        $shareSafetyState = $this->normalizeString($context['share_safety_state'] ?? null);
        $runtimeArtifactRef = $this->normalizeString($context['runtime_artifact_ref'] ?? null);

        $fingerprintSeed = is_array($context['fingerprint_seed'] ?? null)
            ? $context['fingerprint_seed']
            : [];
        $fingerprintSeed['landing_scope'] = $landingScope;
        $fingerprintSeed['entry_surface'] = $entrySurface;
        $fingerprintSeed['entry_type'] = $entryType;
        $fingerprintSeed['summary_blocks'] = $summaryBlocks;
        $fingerprintSeed['discoverability_items'] = $discoverabilityItems;
        $fingerprintSeed['discoverability_keys'] = $discoverabilityKeys;
        $fingerprintSeed['continue_reading_keys'] = $continueReadingKeys;
        $fingerprintSeed['start_test_target'] = $startTestTarget;
        $fingerprintSeed['result_resume_target'] = $resultResumeTarget;
        $fingerprintSeed['content_continue_target'] = $contentContinueTarget;
        $fingerprintSeed['cta_bundle'] = $ctaBundle;
        $fingerprintSeed['indexability_state'] = $indexabilityState;
        $fingerprintSeed['attribution_scope'] = $attributionScope;
        $fingerprintSeed['seo_surface_ref'] = $seoSurfaceRef;
        $fingerprintSeed['public_surface_ref'] = $publicSurfaceRef;
        $fingerprintSeed['surface_family'] = $surfaceFamily;
        $fingerprintSeed['primary_content_ref'] = $primaryContentRef;
        $fingerprintSeed['related_surface_keys'] = $relatedSurfaceKeys;
        $fingerprintSeed['share_safety_state'] = $shareSafetyState;
        $fingerprintSeed['runtime_artifact_ref'] = $runtimeArtifactRef;

        return [
            'version' => 'landing.surface.v1',
            'landing_contract_version' => 'landing.surface.v1',
            'landing_fingerprint' => sha1((string) json_encode($fingerprintSeed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'landing_scope' => $landingScope,
            'entry_surface' => $entrySurface,
            'entry_type' => $entryType,
            'summary_blocks' => $summaryBlocks,
            'discoverability_items' => $discoverabilityItems,
            'discoverability_keys' => $discoverabilityKeys,
            'continue_reading_keys' => $continueReadingKeys,
            'start_test_target' => $startTestTarget,
            'result_resume_target' => $resultResumeTarget,
            'content_continue_target' => $contentContinueTarget,
            'cta_bundle' => $ctaBundle,
            'indexability_state' => $indexabilityState,
            'attribution_scope' => $attributionScope,
            'seo_surface_ref' => $seoSurfaceRef,
            'public_surface_ref' => $publicSurfaceRef,
            'surface_family' => $surfaceFamily,
            'primary_content_ref' => $primaryContentRef,
            'related_surface_keys' => $relatedSurfaceKeys,
            'share_safety_state' => $shareSafetyState,
            'runtime_artifact_ref' => $runtimeArtifactRef,
        ];
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
     * @param  array<int,mixed>  $items
     * @return list<array<string,string|null>>
     */
    private function normalizeDiscoverabilityItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $key = $this->normalizeString($item['key'] ?? null);
            $title = $this->normalizeString($item['title'] ?? null);
            $summary = $this->normalizeString($item['summary'] ?? $item['body'] ?? null);
            $href = $this->normalizeString($item['href'] ?? $item['url'] ?? null);
            $kind = $this->normalizeString($item['kind'] ?? null);
            $badgeLabel = $this->normalizeString($item['badge_label'] ?? $item['badge'] ?? null);

            if ($title === null || $href === null) {
                continue;
            }

            $dedupeKey = $key ?? $href;
            if (isset($normalized[$dedupeKey])) {
                continue;
            }

            $normalized[$dedupeKey] = [
                'key' => $key ?? $href,
                'title' => $title,
                'summary' => $summary,
                'href' => $href,
                'kind' => $kind,
                'badge_label' => $badgeLabel,
            ];
        }

        return array_values($normalized);
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
     * @param  array<int,mixed>  $blocks
     * @return list<array<string,string|null>>
     */
    private function normalizeSummaryBlocks(array $blocks): array
    {
        $normalized = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $key = $this->normalizeString($block['key'] ?? null);
            $title = $this->normalizeString($block['title'] ?? null);
            $body = $this->normalizeString($block['body'] ?? null);
            $kind = $this->normalizeString($block['kind'] ?? null);

            if ($key === null && $title === null && $body === null) {
                continue;
            }

            $normalized[] = [
                'key' => $key,
                'title' => $title,
                'body' => $body,
                'kind' => $kind,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int,mixed>  $ctas
     * @return list<array<string,string|null>>
     */
    private function normalizeCtaBundle(array $ctas): array
    {
        $normalized = [];

        foreach ($ctas as $cta) {
            if (! is_array($cta)) {
                continue;
            }

            $key = $this->normalizeString($cta['key'] ?? null);
            $label = $this->normalizeString($cta['label'] ?? null);
            $href = $this->normalizeString($cta['href'] ?? null);
            $kind = $this->normalizeString($cta['kind'] ?? null);

            if ($label === null || $href === null) {
                continue;
            }

            $dedupeKey = $key ?? $href;
            if (isset($normalized[$dedupeKey])) {
                continue;
            }

            $normalized[$dedupeKey] = [
                'key' => $key,
                'label' => $label,
                'href' => $href,
                'kind' => $kind,
            ];
        }

        return array_values($normalized);
    }
}
