<?php

declare(strict_types=1);

namespace App\Services\InsightGraph;

final class WidgetSurfaceContractService
{
    /**
     * @param  array<string,mixed>  $embedSurface
     * @param  array<string,mixed>  $partnerRead
     * @param  array<string,mixed>  $publicSurface
     * @return array<string,mixed>
     */
    public function buildForPublicShare(array $embedSurface, array $partnerRead, array $publicSurface): array
    {
        if ($embedSurface === [] || $partnerRead === [] || $publicSurface === []) {
            return [];
        }

        $widgetScope = $this->normalizeText($partnerRead['graph_scope'] ?? null, $embedSurface['graph_scope'] ?? null);
        if ($widgetScope !== 'public_share_safe') {
            return [];
        }

        return [
            'version' => 'widget.surface.v1',
            'widget_scope' => 'public_share_safe',
            'widget_contract_version' => 'widget.surface.v1',
            'surface_key' => $this->normalizeText($embedSurface['surface_key'] ?? null) ?? '',
            'host_mode' => $this->normalizeText($embedSurface['render_mode'] ?? null, 'card') ?? 'card',
            'slot_key' => 'public_share_primary',
            'size_preset' => 'summary_card',
            'entry_surface' => $this->normalizeText($publicSurface['entry_surface'] ?? null) ?? '',
            'title' => $this->normalizeText($embedSurface['title'] ?? null) ?? '',
            'summary' => $this->normalizeText($embedSurface['summary'] ?? null) ?? '',
            'primary_cta_label' => $this->normalizeText($embedSurface['primary_cta_label'] ?? null) ?? '',
            'primary_cta_path' => $this->normalizeText($embedSurface['primary_cta_path'] ?? null) ?? '',
            'continue_target' => $this->normalizeText($embedSurface['continue_target'] ?? null) ?? '',
            'allowed_node_ids' => $this->normalizeStringList($partnerRead['allowed_node_ids'] ?? []),
            'allowed_edge_types' => $this->normalizeStringList($partnerRead['allowed_edge_types'] ?? []),
            'graph_fingerprint' => $this->normalizeText($partnerRead['graph_fingerprint'] ?? null) ?? '',
            'embed_fingerprint' => $this->normalizeText($embedSurface['embed_fingerprint'] ?? null) ?? '',
            'attribution_scope' => $this->normalizeText(
                $partnerRead['attribution_scope'] ?? null,
                $publicSurface['attribution_scope'] ?? null
            ) ?? '',
        ];
    }

    private function normalizeText(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $normalized = trim((string) $value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = [];
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $item = trim((string) $value);
            if ($item === '') {
                continue;
            }

            $normalized[$item] = true;
        }

        return array_keys($normalized);
    }
}
