<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Resolver;

use App\Services\BigFive\ReportEngine\Contracts\ReportContext;
use App\Services\BigFive\ReportEngine\Contracts\ResolvedBlock;

final class ModifierInjector
{
    /**
     * @param  array<string,list<ResolvedBlock>>  $blocksBySection
     * @param  array<string,mixed>  $registry
     * @return array<string,list<ResolvedBlock>>
     */
    public function inject(ReportContext $context, array $blocksBySection, array $registry): array
    {
        foreach ($blocksBySection as $sectionKey => $blocks) {
            foreach ($blocks as $index => $block) {
                $traitCode = (string) ($block->analytics['trait_code'] ?? '');
                if ($traitCode === '') {
                    continue;
                }
                $gradientId = $context->domainGradientId($traitCode);
                $gradient = $registry['modifiers'][$traitCode]['gradients'][$gradientId] ?? null;
                if (! is_array($gradient)) {
                    continue;
                }

                if (array_key_exists('replace_map', $gradient)) {
                    throw new \RuntimeException('Big Five report engine modifiers must not use replace_map.');
                }

                $injections = is_array($gradient['injections'] ?? null) ? $gradient['injections'] : [];
                $copy = $block->resolvedCopy;
                foreach ($injections as $slot => $sentence) {
                    if (! is_string($slot) || ! str_starts_with($slot, $sectionKey.'.')) {
                        continue;
                    }

                    $copy['injections'][substr($slot, strlen($sectionKey) + 1)] = (string) $sentence;
                }

                $blocksBySection[$sectionKey][$index] = new ResolvedBlock(
                    blockUid: $block->blockUid,
                    kind: $block->kind,
                    component: $block->component,
                    blockId: $block->blockId,
                    resolvedCopy: $copy,
                    provenance: [
                        'atomic_refs' => $block->provenance['atomic_refs'] ?? [],
                        'modifier_refs' => array_values(array_unique(array_merge(
                            $block->provenance['modifier_refs'] ?? [],
                            ["modifiers/{$traitCode}.json#gradients.{$gradientId}"]
                        ))),
                        'synergy_refs' => $block->provenance['synergy_refs'] ?? [],
                        'facet_refs' => $block->provenance['facet_refs'] ?? [],
                        'action_refs' => $block->provenance['action_refs'] ?? [],
                    ],
                    analytics: array_merge($block->analytics, [
                        'modifier_gradient_id' => $gradientId,
                        'modifier_label' => (string) ($gradient['user_label'] ?? ''),
                    ]),
                );
            }
        }

        return $blocksBySection;
    }
}
