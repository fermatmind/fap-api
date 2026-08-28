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
        self::exactKeys($content, [
            'contract_version', 'locale', 'subject', 'content_state', 'source_content_sha256', 'blocks',
        ]);
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
        $seenBlocks = [];
        $seenItems = [];
        foreach ($blocks as $block) {
            self::assertBlock($block, $seenBlocks, $seenItems);
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
            self::exactKeys($item, ['id', 'copy_key', 'type', 'availability', 'data']);
            $itemId = $item['id'] ?? null;
            if (! self::key($itemId) || isset($seenItems[$itemId])
                || ! self::key($item['copy_key'] ?? null)
                || ! in_array($item['type'] ?? null, self::PRIMITIVES, true)
                || ! in_array($item['availability'] ?? null, ['available', 'missing'], true)
                || ! is_array($item['data'] ?? null)) {
                self::fail();
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
                self::exactKeys($entry, ['id', 'question_key', 'answer']);
                if (! self::key($entry['id'] ?? null) || isset($seenEntries[$entry['id']])
                    || ! self::key($entry['question_key'] ?? null)
                    || ! self::nonEmpty($entry['answer'] ?? null)) {
                    self::fail();
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
                self::exactKeys($entry, ['details', 'id', 'name', 'url']);
                if (! self::key($entry['id'] ?? null) || isset($seenEntries[$entry['id']])
                    || ! self::nonEmpty($entry['name'] ?? null)
                    || ! is_array($entry['details'] ?? null) || ! array_is_list($entry['details'])
                    || (($entry['url'] ?? null) !== null && ! self::safeUrl($entry['url']))) {
                    self::fail();
                }
                foreach ($entry['details'] as $detail) {
                    if (! self::nonEmpty($detail)) {
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
