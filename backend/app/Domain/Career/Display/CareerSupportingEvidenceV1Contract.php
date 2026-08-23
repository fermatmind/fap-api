<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

final class CareerSupportingEvidenceV1Contract
{
    public const CONTRACT_VERSION = 'career.detail.supporting_evidence.v1';

    private const QUICK_KEYS = ['does', 'difference', 'salary'];

    private const ONET_KEYS = ['tasks', 'skills', 'abilities', 'knowledge', 'work_context', 'job_zone'];

    private const MARKET_KEYS = ['annual_openings', 'hot_skills', 'china_openings'];

    /** @param array<string,mixed> $evidence @param list<array<string,mixed>> $sources */
    public static function assert(array $evidence, array $sources): void
    {
        self::exactKeys($evidence, [
            'contract_version', 'quick_answers', 'onet', 'ai_cases', 'career_path',
            'china_reference', 'market_facts', 'charts',
        ]);
        if (($evidence['contract_version'] ?? null) !== self::CONTRACT_VERSION) {
            self::fail();
        }

        $knownSources = [];
        foreach ($sources as $source) {
            if (! is_array($source)) {
                self::fail();
            }
            $key = self::string($source['source_key'] ?? $source['label'] ?? null);
            if ($key !== null) {
                $knownSources[$key] = true;
            }
        }

        self::assertKeyedTables($evidence['quick_answers'] ?? null, self::QUICK_KEYS, $knownSources, true);

        $onet = $evidence['onet'] ?? null;
        if (! is_array($onet)) {
            self::fail();
        }
        self::exactKeys($onet, ['tables', 'reviewed_at']);
        self::nullableString($onet['reviewed_at'] ?? null);
        self::assertKeyedTables($onet['tables'] ?? null, self::ONET_KEYS, $knownSources, false);

        $cases = $evidence['ai_cases'] ?? null;
        if (! is_array($cases) || ! array_is_list($cases)) {
            self::fail();
        }
        foreach ($cases as $case) {
            if (! is_array($case)) {
                self::fail();
            }
            self::exactKeys($case, ['organization', 'summary', 'source_label', 'source_url', 'reviewed_at']);
            foreach (['organization', 'summary', 'source_label', 'reviewed_at'] as $key) {
                self::requiredString($case[$key] ?? null);
            }
            self::httpsUrl($case['source_url'] ?? null);
        }

        self::nullableTable($evidence['career_path'] ?? null, $knownSources);
        self::assertChinaReference($evidence['china_reference'] ?? null, $knownSources);

        $marketFacts = $evidence['market_facts'] ?? null;
        if (! is_array($marketFacts) || ! array_is_list($marketFacts)) {
            self::fail();
        }
        $seenMarket = [];
        foreach ($marketFacts as $fact) {
            if (! is_array($fact)) {
                self::fail();
            }
            self::exactKeys($fact, ['key', 'label', 'value', 'source_keys']);
            $key = self::requiredString($fact['key'] ?? null);
            if (! in_array($key, self::MARKET_KEYS, true) || isset($seenMarket[$key])) {
                self::fail();
            }
            $seenMarket[$key] = true;
            self::requiredString($fact['label'] ?? null);
            self::requiredString($fact['value'] ?? null);
            self::sourceKeys($fact['source_keys'] ?? null, $knownSources);
        }

        $charts = $evidence['charts'] ?? null;
        if (! is_array($charts)) {
            self::fail();
        }
        self::exactKeys($charts, ['task_automation', 'riasec']);
        self::nullableChart($charts['task_automation'] ?? null, $knownSources);
        self::nullableChart($charts['riasec'] ?? null, $knownSources);
    }

    /** @param array<string,bool> $knownSources */
    private static function assertKeyedTables(mixed $value, array $allowedKeys, array $knownSources, bool $withAnswer): void
    {
        if (! is_array($value) || ! array_is_list($value)) {
            self::fail();
        }
        $seen = [];
        foreach ($value as $table) {
            if (! is_array($table)) {
                self::fail();
            }
            self::exactKeys($table, $withAnswer
                ? ['key', 'title', 'answer', 'rows', 'source_keys']
                : ['key', 'title', 'rows', 'source_keys']);
            $key = self::requiredString($table['key'] ?? null);
            if (! in_array($key, $allowedKeys, true) || isset($seen[$key])) {
                self::fail();
            }
            $seen[$key] = true;
            if ($withAnswer) {
                self::requiredString($table['answer'] ?? null);
            }
            self::table($table, $knownSources);
        }
        $actual = array_keys($seen);
        sort($actual, SORT_STRING);
        sort($allowedKeys, SORT_STRING);
        if ($actual !== $allowedKeys) {
            self::fail();
        }
    }

    /** @param array<string,bool> $knownSources */
    private static function nullableTable(mixed $value, array $knownSources): void
    {
        if ($value === null) {
            return;
        }
        if (! is_array($value)) {
            self::fail();
        }
        self::exactKeys($value, ['title', 'rows', 'source_keys']);
        self::table($value, $knownSources);
    }

    /** @param array<string,bool> $knownSources */
    private static function table(array $value, array $knownSources): void
    {
        self::requiredString($value['title'] ?? null);
        $rows = $value['rows'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || $rows === []) {
            self::fail();
        }
        foreach ($rows as $row) {
            if (! is_array($row) || array_is_list($row) || $row === []) {
                self::fail();
            }
            foreach ($row as $key => $cell) {
                self::requiredString($key);
                self::requiredString($cell);
            }
        }
        self::sourceKeys($value['source_keys'] ?? null, $knownSources);
    }

    /** @param array<string,bool> $knownSources */
    private static function assertChinaReference(mixed $value, array $knownSources): void
    {
        if ($value === null) {
            return;
        }
        if (! is_array($value)) {
            self::fail();
        }
        self::exactKeys($value, ['market', 'sample', 'captured_at', 'boundary', 'source_keys', 'tables']);
        foreach (['market', 'sample', 'captured_at', 'boundary'] as $key) {
            self::requiredString($value[$key] ?? null);
        }
        self::sourceKeys($value['source_keys'] ?? null, $knownSources);
        $tables = $value['tables'] ?? null;
        if (! is_array($tables) || ! array_is_list($tables) || $tables === []) {
            self::fail();
        }
        foreach ($tables as $table) {
            if (! is_array($table)) {
                self::fail();
            }
            self::exactKeys($table, ['title', 'rows', 'source_keys']);
            self::table($table, $knownSources);
        }
    }

    /** @param array<string,bool> $knownSources */
    private static function nullableChart(mixed $value, array $knownSources): void
    {
        if ($value === null) {
            return;
        }
        if (! is_array($value)) {
            self::fail();
        }
        self::exactKeys($value, ['title', 'aria_label', 'caption', 'source_keys', 'legend', 'points']);
        foreach (['title', 'aria_label', 'caption'] as $key) {
            self::requiredString($value[$key] ?? null);
        }
        self::sourceKeys($value['source_keys'] ?? null, $knownSources);
        $legend = $value['legend'] ?? null;
        $points = $value['points'] ?? null;
        if (! is_array($legend) || ! array_is_list($legend) || $legend === []
            || ! is_array($points) || ! array_is_list($points) || $points === []) {
            self::fail();
        }
        $categories = [];
        foreach ($legend as $item) {
            if (! is_array($item)) {
                self::fail();
            }
            self::exactKeys($item, ['label', 'color']);
            $label = self::requiredString($item['label'] ?? null);
            $color = self::requiredString($item['color'] ?? null);
            if (preg_match('/\A#[0-9a-f]{6}\z/i', $color) !== 1) {
                self::fail();
            }
            $categories[$label] = true;
        }
        $pointKeys = [];
        foreach ($points as $point) {
            if (! is_array($point)) {
                self::fail();
            }
            self::exactKeys($point, ['key', 'label', 'x', 'y', 'category']);
            $key = self::requiredString($point['key'] ?? null);
            $category = self::requiredString($point['category'] ?? null);
            if (isset($pointKeys[$key]) || ! isset($categories[$category])) {
                self::fail();
            }
            $pointKeys[$key] = true;
            self::requiredString($point['label'] ?? null);
            foreach (['x', 'y'] as $axis) {
                $number = $point[$axis] ?? null;
                if (! is_int($number) || $number < 0 || $number > 100) {
                    self::fail();
                }
            }
        }
    }

    /** @param array<string,bool> $knownSources */
    private static function sourceKeys(mixed $value, array $knownSources): void
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            self::fail();
        }
        foreach ($value as $key) {
            $normalized = self::requiredString($key);
            if (! isset($knownSources[$normalized])) {
                self::fail();
            }
        }
    }

    /** @param list<string> $expected */
    private static function exactKeys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            self::fail();
        }
    }

    private static function requiredString(mixed $value): string
    {
        $normalized = self::string($value);
        if ($normalized === null) {
            self::fail();
        }

        return $normalized;
    }

    private static function nullableString(mixed $value): void
    {
        if ($value !== null && self::string($value) === null) {
            self::fail();
        }
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function httpsUrl(mixed $value): void
    {
        $url = self::requiredString($value);
        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! str_starts_with($url, 'https://')) {
            self::fail();
        }
    }

    private static function fail(): never
    {
        throw new CareerCurrentAuthorityPackageFailure('CURRENT_SUPPORTING_EVIDENCE_V1_INVALID');
    }
}
