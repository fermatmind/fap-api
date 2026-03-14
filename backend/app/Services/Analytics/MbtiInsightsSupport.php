<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Services\Scale\ScaleIdentityResolver;

final class MbtiInsightsSupport
{
    public function __construct(
        private readonly ScaleIdentityResolver $scaleIdentityResolver,
    ) {}

    /**
     * @return array{
     *     scale_code:string,
     *     scale_code_v2:string,
     *     scale_uid:?string
     * }
     */
    public function canonicalScale(): array
    {
        $resolved = $this->scaleIdentityResolver->resolveByAnyCode('MBTI');

        $legacy = strtoupper(trim((string) ($resolved['scale_code_v1'] ?? 'MBTI')));
        $v2 = strtoupper(trim((string) ($resolved['scale_code_v2'] ?? (config('scale_identity.code_map_v1_to_v2.MBTI') ?? 'MBTI'))));
        $uid = trim((string) ($resolved['scale_uid'] ?? (config('scale_identity.scale_uid_map.MBTI') ?? '')));

        return [
            'scale_code' => $legacy !== '' ? $legacy : 'MBTI',
            'scale_code_v2' => $v2 !== '' ? $v2 : 'MBTI_PERSONALITY_TEST_16_TYPES',
            'scale_uid' => $uid !== '' ? $uid : null,
        ];
    }

    /**
     * @return array<string, array{
     *     label:string,
     *     first_code:string,
     *     second_code:string,
     *     side_labels:array<string,string>
     * }>
     */
    public function axisDefinitions(bool $includeAt = true): array
    {
        $definitions = [
            'EI' => [
                'label' => 'E / I',
                'first_code' => 'E',
                'second_code' => 'I',
                'side_labels' => [
                    'E' => 'Extraversion',
                    'I' => 'Introversion',
                ],
            ],
            'SN' => [
                'label' => 'S / N',
                'first_code' => 'S',
                'second_code' => 'N',
                'side_labels' => [
                    'S' => 'Sensing',
                    'N' => 'Intuition',
                ],
            ],
            'TF' => [
                'label' => 'T / F',
                'first_code' => 'T',
                'second_code' => 'F',
                'side_labels' => [
                    'T' => 'Thinking',
                    'F' => 'Feeling',
                ],
            ],
            'JP' => [
                'label' => 'J / P',
                'first_code' => 'J',
                'second_code' => 'P',
                'side_labels' => [
                    'J' => 'Judging',
                    'P' => 'Perceiving',
                ],
            ],
        ];

        if ($includeAt) {
            $definitions['AT'] = [
                'label' => 'A / T',
                'first_code' => 'A',
                'second_code' => 'T',
                'side_labels' => [
                    'A' => 'Assertive',
                    'T' => 'Turbulent',
                ],
            ];
        }

        return $definitions;
    }

    /**
     * @return array{base:string,suffix:?string,raw:string}|null
     */
    public function normalizeTypeVariant(string $typeCode): ?array
    {
        $normalized = strtoupper(trim($typeCode));
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^([EI][SN][TF][JP])(?:-([AT]))?$/', $normalized, $matches) !== 1) {
            return null;
        }

        $base = (string) ($matches[1] ?? '');
        $suffix = trim((string) ($matches[2] ?? ''));

        return [
            'base' => $base,
            'suffix' => $suffix !== '' ? $suffix : null,
            'raw' => $normalized,
        ];
    }

    public function normalizeBaseTypeCode(string $typeCode): ?string
    {
        return $this->normalizeTypeVariant($typeCode)['base'] ?? null;
    }

    /**
     * @param  array<string,mixed>  $scoresPct
     * @return array<string,string>
     */
    public function deriveAxisSides(array $scoresPct, string $typeCode): array
    {
        $variant = $this->normalizeTypeVariant($typeCode);
        $base = $variant['base'] ?? '';
        $suffix = $variant['suffix'] ?? null;
        $definitions = $this->axisDefinitions();
        $sides = [];

        foreach ($definitions as $axisCode => $definition) {
            $resolved = null;

            if (array_key_exists($axisCode, $scoresPct) && is_numeric($scoresPct[$axisCode])) {
                $pct = max(0.0, min(100.0, (float) $scoresPct[$axisCode]));
                $resolved = $pct >= 50.0 ? $definition['first_code'] : $definition['second_code'];
            } elseif ($base !== '' && strlen($base) === 4) {
                $resolved = match ($axisCode) {
                    'EI' => $base[0],
                    'SN' => $base[1],
                    'TF' => $base[2],
                    'JP' => $base[3],
                    'AT' => $suffix,
                    default => null,
                };
            } elseif ($axisCode === 'AT' && $suffix !== null) {
                $resolved = $suffix;
            }

            $candidate = strtoupper(trim((string) $resolved));
            $allowed = [$definition['first_code'], $definition['second_code']];
            if ($candidate !== '' && in_array($candidate, $allowed, true)) {
                $sides[$axisCode] = $candidate;
            }
        }

        return $sides;
    }
}
