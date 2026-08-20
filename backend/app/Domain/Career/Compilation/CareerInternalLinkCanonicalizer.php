<?php

declare(strict_types=1);

namespace App\Domain\Career\Compilation;

use App\Domain\Career\Audit\CareerDetailReadyTargetAuthority;

final class CareerInternalLinkCanonicalizer
{
    /**
     * @param  list<array<string,mixed>>  $links
     * @param  array<string,array<string,mixed>>  $lookup
     * @param  array<string,true>  $canonicalInventory
     * @return list<array<string,mixed>>
     */
    public function canonicalize(string $sourceSlug, array $links, array $lookup, array $canonicalInventory): array
    {
        $result = [];
        foreach ($links as $link) {
            $target = $link['slug'] ?? null;
            $row = is_string($target) ? ($lookup[$target] ?? null) : null;
            $canonical = is_array($row) ? ($row['canonical_slug'] ?? null) : null;
            if (! is_string($target) || ! is_string($canonical) || ! isset($canonicalInventory[$canonical])) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_INTERNAL_LINK_UNRESOLVED');
            }
            if (in_array($canonical, CareerDetailReadyTargetAuthority::MANUAL_HOLD_SLUGS, true)) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_INTERNAL_LINK_HOLD_TARGET');
            }
            $result[] = [
                'source_slug' => $sourceSlug,
                'input_target_slug' => $target,
                'target_kind' => $target === $canonical ? 'canonical' : 'variant',
                'canonical_target' => $canonical,
                'rewrite_applied' => $target !== $canonical,
                'resolvable' => true,
                'nofollow' => (bool) ($link['nofollow'] ?? false),
            ];
        }

        return $result;
    }
}
