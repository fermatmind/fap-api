<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\Routing;

use InvalidArgumentException;

final class BigFiveV2BandMapper
{
    private const DOMAIN_ORDER = ['O', 'C', 'E', 'A', 'N'];

    /**
     * @var array<int,array{key:string,min:int,max:int,internal_band:string,display_label_zh:string}>
     */
    private const BANDS = [
        1 => ['key' => 'B1', 'min' => 0, 'max' => 19, 'internal_band' => 'very_low', 'display_label_zh' => '明显偏低'],
        2 => ['key' => 'B2', 'min' => 20, 'max' => 39, 'internal_band' => 'low', 'display_label_zh' => '偏低'],
        3 => ['key' => 'B3', 'min' => 40, 'max' => 59, 'internal_band' => 'mid', 'display_label_zh' => '中位'],
        4 => ['key' => 'B4', 'min' => 60, 'max' => 79, 'internal_band' => 'high', 'display_label_zh' => '中高'],
        5 => ['key' => 'B5', 'min' => 80, 'max' => 100, 'internal_band' => 'very_high', 'display_label_zh' => '明显偏高'],
    ];

    /**
     * @return array<string,int>
     */
    public function mapDomainPercentiles(array $domainPercentiles): array
    {
        $bands = [];

        foreach (self::DOMAIN_ORDER as $domain) {
            if (! array_key_exists($domain, $domainPercentiles)) {
                throw new InvalidArgumentException("missing domain percentile: {$domain}");
            }

            $bands[$domain] = $this->mapPercentile($domainPercentiles[$domain]);
        }

        return $bands;
    }

    public function mapPercentile(mixed $percentile): int
    {
        if (! is_int($percentile) && ! (is_float($percentile) && floor($percentile) === $percentile) && ! (is_string($percentile) && preg_match('/^\d+$/', $percentile) === 1)) {
            throw new InvalidArgumentException('percentile must be an integer from 0 to 100');
        }

        $value = (int) $percentile;
        if ($value < 0 || $value > 100) {
            throw new InvalidArgumentException('percentile must be within 0..100');
        }

        foreach (self::BANDS as $index => $band) {
            if ($value >= $band['min'] && $value <= $band['max']) {
                return $index;
            }
        }

        throw new InvalidArgumentException('percentile did not match a Big Five V2 route band');
    }

    /**
     * @param  array<string,int>  $domainRouteBands
     */
    public function combinationKey(array $domainRouteBands): string
    {
        $parts = [];
        foreach (self::DOMAIN_ORDER as $domain) {
            $band = (int) ($domainRouteBands[$domain] ?? 0);
            if ($band < 1 || $band > 5) {
                throw new InvalidArgumentException("invalid route band for {$domain}");
            }
            $parts[] = $domain.$band;
        }

        return implode('_', $parts);
    }

    /**
     * @param  array<string,int>  $domainRouteBands
     * @return array<string,string>
     */
    public function displayBandLabels(array $domainRouteBands): array
    {
        $labels = [];
        foreach (self::DOMAIN_ORDER as $domain) {
            $band = (int) ($domainRouteBands[$domain] ?? 0);
            if (! isset(self::BANDS[$band])) {
                throw new InvalidArgumentException("invalid route band for {$domain}");
            }
            $labels[$domain] = self::BANDS[$band]['display_label_zh'];
        }

        return $labels;
    }

    /**
     * @return array<int,array{key:string,min:int,max:int,internal_band:string,display_label_zh:string}>
     */
    public function bandDefinitions(): array
    {
        return self::BANDS;
    }
}
