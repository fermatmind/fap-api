<?php

declare(strict_types=1);

namespace App\Services\AI;

final class MockNarrativeProvider implements NarrativeProviderInterface
{
    public function name(): string
    {
        return 'mock';
    }

    public function generate(NarrativeGenerationRequest $request): NarrativeGenerationResponse
    {
        $typeCode = trim((string) ($request->authority['type_code'] ?? $request->context['type_code'] ?? ''));
        $identity = trim((string) ($request->authority['identity'] ?? $request->context['identity'] ?? ''));
        $dominantTraits = array_values(array_filter(array_map(
            static fn (mixed $trait): string => is_array($trait)
                ? trim((string) ($trait['label'] ?? $trait['key'] ?? ''))
                : '',
            is_array($request->authority['dominant_traits'] ?? null) ? $request->authority['dominant_traits'] : []
        )));
        $focusKey = trim((string) data_get($request->authority, 'working_life_v1.career_focus_key', ''))
            ?: trim((string) ($request->authority['action_focus_key'] ?? ''))
            ?: trim((string) data_get($request->authority, 'orchestration.primary_focus_key', ''));

        $summary = $this->extractSummaryText($request->authority['action_plan_summary'] ?? null);
        if ($summary === '') {
            $summary = $this->extractSummaryText($request->authority['explainability_summary'] ?? null);
        }
        if ($summary === '') {
            $summary = $this->extractSummaryText($request->authority['work_style_summary'] ?? null);
        }

        $sectionKeys = $this->extractNarrativeSectionKeys($request->authority, 6);

        $introParts = array_values(array_filter([
            $typeCode !== '' ? $typeCode : null,
            $identity !== '' ? "identity {$identity}" : null,
            $dominantTraits !== [] ? 'traits '.implode('/', array_slice($dominantTraits, 0, 2)) : null,
            $focusKey !== '' ? "focus {$focusKey}" : null,
        ]));

        $intro = $introParts !== []
            ? 'Controlled narrative runtime ready for '.implode(' / ', $introParts).'.'
            : 'Controlled narrative runtime ready from structured authority.';

        $output = [
            'narrative_intro' => $intro,
            'narrative_summary' => $summary !== '' ? $summary : 'Structured authority is available for controlled narrative assembly.',
            'section_narrative_keys' => $sectionKeys,
        ];

        $payload = [
            'provider' => $this->name(),
            'surface' => $request->surface,
            'scale_code' => $request->scaleCode,
            'locale' => $request->locale,
            'authority' => $request->fingerprintPayload(),
            'output' => $output,
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $tokensIn = max(1, (int) ceil(strlen($encoded) / 4));
        $tokensOut = max(1, (int) ceil(strlen(json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') / 4));
        $costPer1k = (float) config('ai.cost_per_1k_tokens_usd', 0.0);

        return new NarrativeGenerationResponse(
            runtimeMode: 'mock',
            providerName: $this->name(),
            modelVersion: trim((string) config('ai.narrative.model', config('ai.model', 'mock-model'))),
            promptVersion: trim((string) config('ai.narrative.prompt_version', config('ai.prompt_version', 'v1.0.0'))),
            failOpenMode: trim((string) config('ai.narrative.fail_open_mode', 'deterministic')),
            narrativeFingerprint: hash('sha256', $encoded),
            output: $output,
            errorCode: null,
            tokensIn: $tokensIn,
            tokensOut: $tokensOut,
            costUsd: round((($tokensIn + $tokensOut) / 1000.0) * $costPer1k, 6),
        );
    }

    /**
     * @param  array<string, mixed>  $authority
     * @return list<string>
     */
    private function extractNarrativeSectionKeys(array $authority, int $limit = 4): array
    {
        $variantKeys = $authority['variant_keys'] ?? [];
        $sectionKeys = [];

        if (is_array($variantKeys)) {
            $isList = array_is_list($variantKeys);
            foreach ($variantKeys as $key => $value) {
                $candidate = null;

                if (! $isList && is_string($key) && trim($key) !== '') {
                    $candidate = trim($key);
                } elseif (is_scalar($value)) {
                    $candidate = trim((string) $value);
                }

                if ($candidate === null || $candidate === '') {
                    continue;
                }

                $sectionKeys[] = $candidate;
                if (count($sectionKeys) >= $limit) {
                    return $sectionKeys;
                }
            }
        }

        foreach ([
            data_get($authority, 'working_life_v1.career_journey_keys', []),
            $authority['ordered_section_keys'] ?? [],
        ] as $values) {
            if (! is_array($values)) {
                continue;
            }

            foreach ($values as $value) {
                if (! is_scalar($value)) {
                    continue;
                }

                $candidate = trim((string) $value);
                if ($candidate === '' || in_array($candidate, $sectionKeys, true)) {
                    continue;
                }

                $sectionKeys[] = $candidate;
                if (count($sectionKeys) >= $limit) {
                    return $sectionKeys;
                }
            }
        }

        return $sectionKeys;
    }

    private function extractSummaryText(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (! is_array($value)) {
            return '';
        }

        foreach (['headline', 'summary', 'label'] as $key) {
            $candidate = trim((string) ($value[$key] ?? ''));
            if ($candidate !== '') {
                return $candidate;
            }
        }

        foreach (['reasons', 'actions', 'bullets'] as $key) {
            $items = $value[$key] ?? null;
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (! is_scalar($item)) {
                    continue;
                }

                $candidate = trim((string) $item);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return '';
    }
}
