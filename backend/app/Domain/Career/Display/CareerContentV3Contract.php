<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

final class CareerContentV3Contract
{
    public const CONTRACT_VERSION = 'career.detail.content.v3';

    /** @var list<string> */
    public const PRIMITIVES = [
        'prose', 'list', 'metrics', 'cards', 'table', 'matrix', 'timeline', 'faq', 'links', 'notice', 'sources',
    ];

    /** @param array<string,mixed> $content */
    public static function assert(array $content): void
    {
        self::assertEnvelope($content);

        $blocks = $content['blocks'];
        $seenBlocks = [];
        $seenItems = [];
        foreach ($blocks as $block) {
            self::assertBlock($block, $seenBlocks, $seenItems);
        }
        self::assertEvidenceBindings($content);
    }

    /** Validate the page envelope without evaluating individual blocks. @param array<string,mixed> $content */
    public static function assertEnvelope(array $content): void
    {
        self::exactKeysWithOptional($content, [
            'contract_version', 'locale', 'subject', 'content_state', 'source_content_sha256', 'blocks',
        ], ['fact_register']);
        if (($content['contract_version'] ?? null) !== self::CONTRACT_VERSION
            || ! in_array($content['locale'] ?? null, CareerCurrentAuthorityPackage::LOCALES, true)
            || ! in_array($content['content_state'] ?? null, ['enhanced', 'legacy'], true)
            || ! is_string($content['source_content_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $content['source_content_sha256']) !== 1) {
            self::fail();
        }

        $subject = $content['subject'] ?? null;
        if (! is_array($subject)) {
            self::fail();
        }
        self::exactKeys($subject, ['canonical_slug', 'name', 'summary']);
        if (! self::nonEmpty($subject['canonical_slug'] ?? null)
            || ! self::nonEmpty($subject['name'] ?? null)
            || (($subject['summary'] ?? null) !== null && ! self::nonEmpty($subject['summary']))) {
            self::fail();
        }

        $blocks = $content['blocks'] ?? null;
        if (! is_array($blocks) || ! array_is_list($blocks)) {
            self::fail();
        }

        if (array_key_exists('fact_register', $content)) {
            $register = $content['fact_register'];
            if (! is_array($register) || array_is_list($register)) {
                self::fail();
            }
            self::exactKeys($register, ['facts']);
            self::entryList($register['facts'] ?? null);
            $seenFacts = [];
            foreach ($register['facts'] as $fact) {
                if (! is_array($fact) || array_is_list($fact)) {
                    self::fail();
                }
                self::exactKeys($fact, [
                    'fact_id', 'display_value', 'market', 'period', 'measure', 'occupation_scope',
                    'source_refs', 'derivation',
                ]);
                $factId = $fact['fact_id'] ?? null;
                if (! self::key($factId) || isset($seenFacts[$factId])
                    || ! self::nonEmpty($fact['display_value'] ?? null)
                    || ! self::nonEmpty($fact['market'] ?? null)
                    || ! self::nonEmpty($fact['period'] ?? null)
                    || ! self::nonEmpty($fact['measure'] ?? null)
                    || ! self::nonEmpty($fact['occupation_scope'] ?? null)
                    || ! is_array($fact['source_refs'] ?? null)
                    || ! array_is_list($fact['source_refs']) || $fact['source_refs'] === []
                    || (($fact['derivation'] ?? null) !== null && ! self::nonEmpty($fact['derivation']))) {
                    self::fail();
                }
                foreach ($fact['source_refs'] as $sourceRef) {
                    if (! self::key($sourceRef)) {
                        self::fail();
                    }
                }
                $seenFacts[$factId] = true;
            }
        }
    }

    /** @param array<string,true> $seenBlocks @param array<string,true> $seenItems */
    public static function assertBlock(mixed $block, array &$seenBlocks, array &$seenItems): void
    {
        if (! is_array($block)) {
            self::fail();
        }
        self::exactKeys($block, ['id', 'copy_key', 'content_state', 'availability', 'items']);
        $id = $block['id'] ?? null;
        if (! self::key($id) || isset($seenBlocks[$id])
            || ! self::key($block['copy_key'] ?? null)
            || ! in_array($block['content_state'] ?? null, ['enhanced', 'legacy'], true)
            || ! in_array($block['availability'] ?? null, ['available', 'missing'], true)
            || ! is_array($block['items'] ?? null) || ! array_is_list($block['items'])) {
            self::fail();
        }
        $seenBlocks[$id] = true;
        foreach ($block['items'] as $item) {
            if (! is_array($item)) {
                self::fail();
            }
            self::exactKeysWithOptional($item, ['id', 'copy_key', 'type', 'availability', 'data'], ['fact_refs', 'source_refs']);
            $itemId = $item['id'] ?? null;
            if (! self::key($itemId) || isset($seenItems[$itemId])
                || ! self::key($item['copy_key'] ?? null)
                || ! in_array($item['type'] ?? null, self::PRIMITIVES, true)
                || ! in_array($item['availability'] ?? null, ['available', 'missing'], true)
                || ! is_array($item['data'] ?? null)) {
                self::fail();
            }
            foreach (['fact_refs', 'source_refs'] as $referenceKey) {
                if (! array_key_exists($referenceKey, $item)) {
                    continue;
                }
                if (! is_array($item[$referenceKey]) || ! array_is_list($item[$referenceKey]) || $item[$referenceKey] === []) {
                    self::fail();
                }
                foreach ($item[$referenceKey] as $reference) {
                    if (! self::key($reference)) {
                        self::fail();
                    }
                }
            }
            $seenItems[$itemId] = true;
            self::assertPrimitive((string) $item['type'], $item['data'], (string) $item['availability']);
        }
        if (($block['availability'] === 'missing') !== ($block['items'] === [])) {
            self::fail();
        }
    }

    /** @param array<string,mixed> $data */
    private static function assertPrimitive(string $type, array $data, string $availability): void
    {
        if ($availability === 'missing') {
            if ($data !== []) {
                self::fail();
            }

            return;
        }
        if ($type === 'prose' || $type === 'notice') {
            self::exactKeys($data, ['paragraphs']);
            self::stringList($data['paragraphs'] ?? null);
        } elseif ($type === 'list') {
            self::exactKeys($data, ['entries']);
            self::stringList($data['entries'] ?? null);
        } elseif ($type === 'cards' || $type === 'timeline') {
            self::exactKeys($data, ['entries']);
            self::entryList($data['entries'] ?? null);
            $seenEntries = [];
            foreach ($data['entries'] as $entry) {
                if (! is_array($entry)) {
                    self::fail();
                }
                self::exactKeys($entry, ['id', 'values']);
                if (! self::key($entry['id'] ?? null) || isset($seenEntries[$entry['id']])) {
                    self::fail();
                }
                $seenEntries[$entry['id']] = true;
                self::stringList($entry['values'] ?? null);
            }
        } elseif ($type === 'faq') {
            self::exactKeys($data, ['entries']);
            self::entryList($data['entries'] ?? null);
            $seenEntries = [];
            foreach ($data['entries'] as $entry) {
                if (! is_array($entry)) {
                    self::fail();
                }
                self::exactKeysWithOptional($entry, ['id', 'question_key', 'answer'], ['fact_refs', 'source_refs']);
                if (! self::key($entry['id'] ?? null) || isset($seenEntries[$entry['id']])
                    || ! self::key($entry['question_key'] ?? null)
                    || ! self::nonEmpty($entry['answer'] ?? null)) {
                    self::fail();
                }
                foreach (['fact_refs', 'source_refs'] as $referenceKey) {
                    if (! array_key_exists($referenceKey, $entry)) {
                        continue;
                    }
                    if (! is_array($entry[$referenceKey]) || ! array_is_list($entry[$referenceKey]) || $entry[$referenceKey] === []) {
                        self::fail();
                    }
                    foreach ($entry[$referenceKey] as $reference) {
                        if (! self::key($reference)) {
                            self::fail();
                        }
                    }
                }
                $seenEntries[$entry['id']] = true;
            }
        } elseif ($type === 'links') {
            self::exactKeys($data, ['entries']);
            self::entryList($data['entries'] ?? null);
            $seenEntries = [];
            foreach ($data['entries'] as $entry) {
                if (! is_array($entry)) {
                    self::fail();
                }
                self::exactKeys($entry, ['id', 'entity', 'relation', 'url']);
                if (! self::key($entry['id'] ?? null) || isset($seenEntries[$entry['id']])
                    || ! self::nonEmpty($entry['entity'] ?? null)
                    || ! self::key($entry['relation'] ?? null) || ! self::safeUrl($entry['url'] ?? null)) {
                    self::fail();
                }
                $seenEntries[$entry['id']] = true;
            }
        } elseif ($type === 'sources') {
            self::exactKeys($data, ['entries']);
            self::entryList($data['entries'] ?? null);
            $seenEntries = [];
            foreach ($data['entries'] as $entry) {
                if (! is_array($entry)) {
                    self::fail();
                }
                self::exactKeysWithOptional($entry, ['id', 'name', 'url', 'details'], [
                    'publisher', 'market', 'period', 'evidence_type', 'scope', 'limitation', 'accessed_at',
                ]);
                if (! self::key($entry['id'] ?? null) || isset($seenEntries[$entry['id']])
                    || ! self::nonEmpty($entry['name'] ?? null)
                    || (($entry['url'] ?? null) !== null && ! self::safeUrl($entry['url']))) {
                    self::fail();
                }
                if (! is_array($entry['details']) || ! array_is_list($entry['details'])) {
                    self::fail();
                }
                foreach ($entry['details'] as $detail) {
                    if (! self::nonEmpty($detail)) {
                        self::fail();
                    }
                }
                foreach (['publisher', 'market', 'period', 'evidence_type', 'scope', 'limitation', 'accessed_at'] as $metadataKey) {
                    if (array_key_exists($metadataKey, $entry)
                        && $entry[$metadataKey] !== null && ! self::nonEmpty($entry[$metadataKey])) {
                        self::fail();
                    }
                }
                $seenEntries[$entry['id']] = true;
            }
        } elseif ($type === 'metrics') {
            self::exactKeys($data, ['entries']);
            self::entryList($data['entries'] ?? null);
            foreach ($data['entries'] as $entry) {
                if (! is_array($entry)) {
                    self::fail();
                }
                self::exactKeys($entry, ['key', 'value']);
                if (! self::key($entry['key'] ?? null) || ! self::nonEmpty($entry['value'] ?? null)) {
                    self::fail();
                }
            }
        } elseif ($type === 'table' || $type === 'matrix') {
            self::exactKeys($data, ['column_keys', 'rows']);
            if (! is_array($data['column_keys'] ?? null) || ! array_is_list($data['column_keys'])
                || $data['column_keys'] === [] || ! is_array($data['rows'] ?? null)
                || ! array_is_list($data['rows']) || $data['rows'] === []) {
                self::fail();
            }
            foreach ($data['column_keys'] as $key) {
                if (! self::key($key)) {
                    self::fail();
                }
            }
            foreach ($data['rows'] as $row) {
                self::stringList($row);
                if (count($row) !== count($data['column_keys'])) {
                    self::fail();
                }
            }
        }
    }

    private static function entryList(mixed $value): void
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            self::fail();
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

    /** @param list<string> $required @param list<string> $optional */
    private static function exactKeysWithOptional(array $value, array $required, array $optional): void
    {
        $actual = array_keys($value);
        $allowed = array_fill_keys([...$required, ...$optional], true);
        foreach ($required as $key) {
            if (! array_key_exists($key, $value)) {
                self::fail();
            }
        }
        foreach ($actual as $key) {
            if (! isset($allowed[$key])) {
                self::fail();
            }
        }
    }

    /** @param array<string,mixed> $content */
    private static function assertEvidenceBindings(array $content): void
    {
        $sources = [];
        $facts = [];
        foreach ((array) data_get($content, 'fact_register.facts', []) as $fact) {
            if (is_array($fact) && is_string($fact['fact_id'] ?? null)) {
                $facts[$fact['fact_id']] = $fact;
            }
        }
        foreach ($content['blocks'] as $block) {
            foreach ($block['items'] as $item) {
                if (($item['type'] ?? null) === 'sources') {
                    foreach ((array) data_get($item, 'data.entries', []) as $source) {
                        $id = is_array($source) ? ($source['id'] ?? null) : null;
                        if (! is_string($id) || isset($sources[$id])) {
                            self::fail();
                        }
                        $sources[$id] = true;
                    }
                }
            }
        }
        foreach ($facts as $fact) {
            foreach ($fact['source_refs'] as $sourceRef) {
                if (! isset($sources[$sourceRef])) {
                    self::fail();
                }
            }
        }

        $walk = function (mixed $value) use (&$walk, $facts, $sources): void {
            if (is_string($value)) {
                if (str_contains($value, '{{fact:')) {
                    preg_match_all('/\{\{fact:([a-z0-9]+(?:[._-][a-z0-9]+)*)\}\}/', $value, $matches);
                    if (($matches[0] ?? []) === []
                        || preg_replace('/\{\{fact:[a-z0-9]+(?:[._-][a-z0-9]+)*\}\}/', '', $value) === null) {
                        self::fail();
                    }
                    foreach ($matches[1] as $factId) {
                        if (! isset($facts[$factId])) {
                            self::fail();
                        }
                    }
                    $withoutKnown = preg_replace('/\{\{fact:[a-z0-9]+(?:[._-][a-z0-9]+)*\}\}/', '', $value);
                    if (! is_string($withoutKnown) || str_contains($withoutKnown, '{{fact:')) {
                        self::fail();
                    }
                }

                return;
            }
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $child) {
                if ($key === 'fact_refs' || $key === 'source_refs') {
                    foreach ((array) $child as $reference) {
                        if (($key === 'fact_refs' && ! isset($facts[$reference]))
                            || ($key === 'source_refs' && ! isset($sources[$reference]))) {
                            self::fail();
                        }
                    }

                    continue;
                }
                $walk($child);
            }
        };
        $walk($content['subject']);
        $walk($content['blocks']);
        $walk($content['fact_register'] ?? []);
    }

    private static function stringList(mixed $value): void
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            self::fail();
        }
        foreach ($value as $item) {
            if (! self::nonEmpty($item)) {
                self::fail();
            }
        }
    }

    private static function key(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-z0-9]+(?:[._-][a-z0-9]+)*\z/', $value) === 1;
    }

    private static function nonEmpty(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private static function safeUrl(mixed $value): bool
    {
        return is_string($value) && (preg_match('/\Ahttps:\/\/[^\s|]+\z/', $value) === 1
            || preg_match('/\A\/(?:en|zh)\/[^\s|]+\z/', $value) === 1
            || preg_match('/\A#[a-z0-9][a-z0-9_-]*\z/', $value) === 1);
    }

    private static function fail(): never
    {
        throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_INVALID');
    }
}
