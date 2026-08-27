<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

final class CareerPresentationV1Contract
{
    public const CONTRACT_VERSION = 'career.detail.presentation.v1';

    public const DESIGN_AUTHORITY_ID = 'accountants-career-page-v1.2';

    public const DESIGN_AUTHORITY_SHA256 = '85c71abac0180a6807222b297e66b0dd611ca79a5cc4bd17db5da416459eafe7';

    private const BADGE_KEYS = ['interest', 'scene', 'risk'];

    private const STAT_KEYS = ['us_median_pay', 'us_growth', 'employment', 'annual_openings', 'ai_exposure'];

    /** @param array<string,mixed> $presentation */
    public static function assert(array $presentation): void
    {
        self::assertExactKeys($presentation, ['contract_version', 'design_authority', 'hero', 'notices']);
        if (($presentation['contract_version'] ?? null) !== self::CONTRACT_VERSION) {
            self::fail();
        }

        $design = $presentation['design_authority'] ?? null;
        if (! is_array($design)) {
            self::fail();
        }
        self::assertExactKeys($design, ['id', 'sha256']);
        if (($design['id'] ?? null) !== self::DESIGN_AUTHORITY_ID
            || ($design['sha256'] ?? null) !== self::DESIGN_AUTHORITY_SHA256) {
            self::fail();
        }

        $hero = $presentation['hero'] ?? null;
        if (! is_array($hero)) {
            self::fail();
        }
        self::assertExactKeys($hero, [
            'title_zh', 'title_en', 'soc_code', 'onet_code', 'badges', 'lead', 'ai_exposure', 'stats', 'cta',
        ]);
        foreach (['title_zh', 'title_en', 'soc_code', 'onet_code', 'lead'] as $key) {
            self::assertNullableString($hero[$key] ?? null);
        }

        $badges = $hero['badges'] ?? null;
        if (! is_array($badges) || ! array_is_list($badges) || count($badges) !== 3) {
            self::fail();
        }
        foreach ($badges as $index => $badge) {
            if (! is_array($badge)) {
                self::fail();
            }
            self::assertExactKeys($badge, ['key', 'text', 'availability']);
            if (($badge['key'] ?? null) !== self::BADGE_KEYS[$index]) {
                self::fail();
            }
            self::assertNullableString($badge['text'] ?? null);
            self::assertAvailability($badge['availability'] ?? null, $badge['text'] ?? null);
        }

        $aiExposure = $hero['ai_exposure'] ?? null;
        if (! is_array($aiExposure)) {
            self::fail();
        }
        self::assertExactKeys($aiExposure, [
            'value', 'scale', 'display_value', 'label', 'note', 'metric_kind', 'source_label', 'availability',
        ]);
        $value = $aiExposure['value'] ?? null;
        if ($value !== null && (! is_int($value) || $value < 0 || $value > 10)) {
            self::fail();
        }
        $labelAndSource = [$aiExposure['label'] ?? null, $aiExposure['source_label'] ?? null];
        $allowedLabelAndSource = [
            ['AI 曝光评分', 'FermatMind 内部 rubric'],
            ['AI任务暴露', 'FermatMind 任务级 rubric'],
        ];
        if (($aiExposure['scale'] ?? null) !== 10
            || ($aiExposure['display_value'] ?? null) !== ($value === null ? null : $value.'/10')
            || ! in_array($labelAndSource, $allowedLabelAndSource, true)
            || ($aiExposure['metric_kind'] ?? null) !== 'fermatmind_internal_rubric') {
            self::fail();
        }
        self::assertNullableString($aiExposure['note'] ?? null);
        self::assertAvailability($aiExposure['availability'] ?? null, $value);

        $stats = $hero['stats'] ?? null;
        if (! is_array($stats) || ! array_is_list($stats)) {
            self::fail();
        }
        $previous = -1;
        foreach ($stats as $stat) {
            if (! is_array($stat)) {
                self::fail();
            }
            self::assertExactKeys($stat, ['key', 'value', 'label', 'source_label', 'source_keys', 'availability']);
            $position = array_search($stat['key'] ?? null, self::STAT_KEYS, true);
            if (! is_int($position) || $position <= $previous
                || ! is_string($stat['value'] ?? null) || trim($stat['value']) === ''
                || ! is_string($stat['label'] ?? null) || trim($stat['label']) === ''
                || ! is_array($stat['source_keys'] ?? null) || ! array_is_list($stat['source_keys'])
                || ($stat['availability'] ?? null) !== 'published') {
                self::fail();
            }
            self::assertNullableString($stat['source_label'] ?? null);
            foreach ($stat['source_keys'] as $sourceKey) {
                if (! is_string($sourceKey) || trim($sourceKey) === '') {
                    self::fail();
                }
            }
            $previous = $position;
        }

        $cta = $hero['cta'] ?? null;
        if (! is_array($cta)) {
            self::fail();
        }
        self::assertExactKeys($cta, ['label', 'href', 'availability']);
        self::assertNullableString($cta['label'] ?? null);
        self::assertNullableString($cta['href'] ?? null);
        $ctaValue = ($cta['label'] ?? null) !== null && ($cta['href'] ?? null) !== null ? true : null;
        self::assertAvailability($cta['availability'] ?? null, $ctaValue);

        $notices = $presentation['notices'] ?? null;
        if (! is_array($notices)) {
            self::fail();
        }
        self::assertExactKeys($notices, ['snapshot_callout', 'salary_boundary', 'usage_boundary']);
        self::assertNullableString($notices['snapshot_callout'] ?? null);
        self::assertNullableString($notices['salary_boundary'] ?? null);
        if (! is_array($notices['usage_boundary'] ?? null) || ! array_is_list($notices['usage_boundary'])) {
            self::fail();
        }
        foreach ($notices['usage_boundary'] as $notice) {
            if (! is_string($notice) || trim($notice) === '') {
                self::fail();
            }
        }
    }

    /** @param list<string> $expected */
    private static function assertExactKeys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            self::fail();
        }
    }

    private static function assertNullableString(mixed $value): void
    {
        if ($value !== null && (! is_string($value) || trim($value) === '')) {
            self::fail();
        }
    }

    private static function assertAvailability(mixed $availability, mixed $value): void
    {
        if ($availability !== ($value === null ? 'missing' : 'published')) {
            self::fail();
        }
    }

    private static function fail(): never
    {
        throw new CareerCurrentAuthorityPackageFailure('CURRENT_PRESENTATION_V1_INVALID');
    }
}
