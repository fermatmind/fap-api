<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use Throwable;

/** Display metadata only: never consumed by Council admission or version hashing. */
final class SeoAgentRolePresentation
{
    private const DISPLAY = [
        'seo.orchestrator' => ['copy' => 'orchestrator', 'icon' => 'squares-2x2', 'tone' => 'accent', 'order' => 1],
        'seo.expert.technical_search_authority' => ['copy' => 'technical', 'icon' => 'wrench-screwdriver', 'tone' => 'info', 'order' => 2],
        'seo.expert.search_analytics_measurement' => ['copy' => 'analytics', 'icon' => 'chart-bar', 'tone' => 'info', 'order' => 3],
        'seo.expert.content_entity_quality' => ['copy' => 'quality', 'icon' => 'document-text', 'tone' => 'accent', 'order' => 4],
        'seo.expert.competitor_research' => ['copy' => 'research', 'icon' => 'globe-alt', 'tone' => 'info', 'order' => 5],
        'seo.expert.public_content_stability' => ['copy' => 'stability', 'icon' => 'server-stack', 'tone' => 'neutral', 'order' => 6],
        'seo.expert.commercial_funnel_cro' => ['copy' => 'cro', 'icon' => 'arrow-trending-up', 'tone' => 'accent', 'order' => 7],
        'seo.independent_reviewer' => ['copy' => 'reviewer', 'icon' => 'shield-check', 'tone' => 'neutral', 'order' => 8],
        'career.content_agent' => ['copy' => 'career', 'icon' => 'briefcase', 'tone' => 'neutral', 'order' => 9],
    ];

    public function snapshot(): array
    {
        try {
            return $this->project(app(SeoRoleCapabilityRegistry::class)->registry()['roles'] ?? null);
        } catch (Throwable) {
            return $this->project(null);
        }
    }

    public function project(?array $roles): array
    {
        if ($roles === null) {
            return ['available' => false, 'roles' => []];
        }

        $cards = [];
        foreach ($roles as $role) {
            $id = $role['role_id'] ?? null;
            if (! is_string($id) || $id === '' || isset($cards[$id])) {
                return ['available' => false, 'roles' => []];
            }
            $display = self::DISPLAY[$id] ?? ['copy' => null, 'icon' => 'user-group', 'tone' => 'neutral', 'order' => 100];
            $cards[$id] = $display + [
                'role_id' => $id,
                // Classification (including active_agent) is not execution evidence.
                'state' => ($role['runtime_state'] ?? null) === 'dormant_not_authorized'
                    ? 'dormant_not_authorized' : 'unknown',
            ];
        }
        uasort($cards, fn (array $a, array $b): int => [$a['order'], $a['role_id']] <=> [$b['order'], $b['role_id']]);

        return ['available' => true, 'roles' => array_values($cards)];
    }

    public static function globalState(array $runtime): string
    {
        $state = $runtime['state'] ?? null;
        if (! is_string($state) || in_array($state, ['UNAVAILABLE', 'SHARED_CACHE_HOLD'], true)) {
            return 'unavailable';
        }
        if (($runtime['pause_intent'] ?? null) === 'PAUSED' || $state === 'PAUSED') {
            return 'paused';
        }

        return match ($state) {
            'ACTIVE_READ_ONLY' => 'read_only',
            'DISABLED', 'DEPLOYED_DISABLED' => 'disabled',
            default => str_ends_with($state, '_HOLD') ? 'restricted' : 'unavailable',
        };
    }
}
