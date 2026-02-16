<?php

namespace App\Services\Report;

trait ReportGatekeeperTeaserTrait
{
    private function applyTeaser(array $report, array $policy): array
    {
        $free = $policy['free_sections'] ?? [];
        $blur = (bool) ($policy['blur_others'] ?? true);
        $pct = (float) ($policy['teaser_percent'] ?? self::DEFAULT_VIEW_POLICY['teaser_percent']);

        if (isset($report['sections']) && is_array($report['sections'])) {
            $report['sections'] = $this->teaseSections($report['sections'], $free, $blur, $pct);

            return $report;
        }

        return $this->teaseSections($report, $free, $blur, $pct);
    }

    private function teaseSections(array $sections, array $freeSections, bool $blurOthers, float $pct): array
    {
        $out = [];
        $freeSet = [];
        foreach ($freeSections as $sec) {
            if (is_string($sec) && $sec !== '') {
                $freeSet[$sec] = true;
            }
        }

        foreach ($sections as $key => $value) {
            if (isset($freeSet[$key])) {
                $out[$key] = $value;
                continue;
            }

            if (!$blurOthers) {
                $out[$key] = null;
                continue;
            }

            $out[$key] = $this->blurValue($value, $pct);
        }

        return $out;
    }

    private function blurValue(mixed $value, float $pct): mixed
    {
        if (is_string($value)) {
            return '[LOCKED]';
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                $count = count($value);
                $take = $this->teaserCount($count, $pct);
                $slice = array_slice($value, 0, $take);
                $out = [];
                foreach ($slice as $item) {
                    $out[] = $this->blurValue($item, $pct);
                }

                return $out;
            }

            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = $this->blurValue($item, $pct);
            }

            return $out;
        }

        return null;
    }

    private function teaserCount(int $count, float $pct): int
    {
        if ($count <= 0 || $pct <= 0) {
            return 0;
        }

        $take = (int) ceil($count * $pct);
        if ($take < 1) {
            $take = 1;
        }
        if ($take > $count) {
            $take = $count;
        }

        return $take;
    }
}
