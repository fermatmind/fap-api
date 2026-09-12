<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

/** Internal same-file authoring positions. Never a public display or publication signal. */
final class CareerAuthoringStructure
{
    public const VERSION = 'career.authoring_structure.v1';

    public const SCHEMA = __DIR__.'/../../../../docs/career/contracts/career-authoring-structure.v1.json';

    public static function schema(): array
    {
        static $schema;

        return $schema ??= json_decode(file_get_contents(self::SCHEMA), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function publicContent(array $content): array
    {
        unset($content['authoring_structure']);

        return $content;
    }

    /** Resolve an explicit reference, without similarity matching or editorial inference. */
    public static function reference(array $page, array $ref): mixed
    {
        $keys = array_keys($ref);
        if (array_diff($keys, ['source', 'id', 'path', 'parts', 'key']) !== []
            || ! isset($ref['source'], $ref['path']) || ! is_array($ref['path']) || ! array_is_list($ref['path'])
            || (isset($ref['parts']) && (! is_array($ref['parts']) || ! array_is_list($ref['parts']) || count($ref['parts']) > 2))
            || (in_array($ref['source'] ?? null, ['item', 'fact'], true)
                ? ! is_string($ref['id'] ?? null) || $ref['id'] === ''
                : array_key_exists('id', $ref))
            || (isset($ref['key']) && $ref['source'] !== 'display')) {
            self::fail();
        }
        $node = match ($ref['source']) {
            'page' => array_intersect_key($page, array_flip(['subject', 'hero', 'seo'])),
            'display' => $page['display'] ?? null,
            'item' => self::items($page)[$ref['id'] ?? ''] ?? null,
            'fact' => array_column($page['fact_register']['facts'] ?? [], null, 'fact_id')[$ref['id'] ?? ''] ?? null,
            default => null,
        };
        $node = self::atPath($node, $ref['path']);
        if ($ref['source'] === 'display' && is_array($node) && array_key_exists('$join', $node)) {
            // Use the production resolver's exact composition, including its validation.
            $node = self::atPath((new CareerPageDisplayResolver)->resolve($page), $ref['path']);
        }
        if (isset($ref['key'])) {
            if (! is_array($node) || ! is_string($ref['key']) || ! array_key_exists($ref['key'], $node)) {
                self::fail();
            }
            $node = $ref['key'];
        }
        foreach ($ref['parts'] ?? [] as $part) {
            if (! is_string($node) || ! is_array($part) || count($part) !== 3
                || ! is_string($part[0]) || $part[0] === '' || ! is_int($part[1]) || $part[1] < 0
                || ! is_int($part[2]) || $part[2] < 1) {
                self::fail();
            }
            $pieces = explode($part[0], $node, $part[2]);
            if (! array_key_exists($part[1], $pieces)) {
                self::fail();
            }
            $node = trim($pieces[$part[1]]);
        }

        return $node;
    }

    private static function atPath(mixed $node, array $path): mixed
    {
        foreach ($path as $segment) {
            if (! is_array($node)) {
                self::fail();
            }
            if (is_array($segment) && array_keys($segment) === ['id']) {
                $matches = array_values(array_filter($node, static fn ($entry): bool => is_array($entry) && ($entry['id'] ?? null) === $segment['id']));
                if (count($matches) !== 1) {
                    self::fail();
                }
                $node = $matches[0];
            } elseif ((is_string($segment) || is_int($segment)) && array_key_exists($segment, $node)) {
                $node = $node[$segment];
            } else {
                self::fail();
            }
        }

        return $node;
    }

    public static function items(array $page): array
    {
        $items = [];
        foreach ($page['blocks'] as $block) {
            foreach ($block['items'] as $item) {
                $items[$item['id']] = $item;
            }
        }

        return $items;
    }

    public static function inventory(array $page, array $slots = []): array
    {
        $covered = [];
        foreach ($slots as $slot) {
            $ref = $slot['ref'] ?? null;
            if (is_array($ref) && in_array($ref['source'] ?? null, ['item', 'fact'], true)) {
                $base = $ref;
                unset($base['parts']);
                $key = CareerCurrentAuthorityPackage::encodeCanonical($base);
                $covered[$key][] = $ref;
            }
        }
        // A composed field covers its inputs only if every displayed split is mapped.
        $composed = [];
        foreach ($slots as $slot) {
            $ref = $slot['ref'] ?? null;
            if (! is_array($ref) || ($ref['source'] ?? null) !== 'display' || isset($ref['key'])) {
                continue;
            }
            $node = self::atPath($page['display'] ?? null, $ref['path']);
            if (! is_array($node) || ! array_key_exists('$join', $node)) {
                continue;
            }
            $base = $ref;
            unset($base['parts']);
            $key = CareerCurrentAuthorityPackage::encodeCanonical($base);
            $composed[$key]['base'] = $base;
            $composed[$key]['node'] = $node;
            $composed[$key]['refs'][] = $ref;
        }
        foreach ($composed as $group) {
            $value = self::reference($page, $group['base']);
            if (is_string($value) && self::coversParts($value, $group['refs'])) {
                foreach (self::compositionInputs($page, $group['node']) as $ref) {
                    $key = CareerCurrentAuthorityPackage::encodeCanonical($ref);
                    $covered[$key][] = $ref;
                }
            }
        }
        $result = [];
        foreach (['item' => self::items($page), 'fact' => array_column($page['fact_register']['facts'] ?? [], null, 'fact_id')] as $source => $entries) {
            foreach ($entries as $id => $entry) {
                $leaves = [];
                self::leaves($source === 'item' ? array_intersect_key($entry, array_flip(['title', 'data'])) : $entry, ['source' => $source, 'id' => $id, 'path' => []], $leaves);
                $complete = $leaves !== [];
                foreach ($leaves as $key => $leaf) {
                    $refs = $covered[$key] ?? [];
                    if ($refs === [] || ! self::coversParts($leaf, $refs)) {
                        $complete = false;
                        break;
                    }
                }
                $result[$source.':'.$id] = ['status' => $complete ? 'mapped' : 'pending_mapping'];
            }
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** Collect actual inputs only after the production resolver has accepted the composition. */
    private static function compositionInputs(array $page, mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }
        if (isset($node['$join'])) {
            $refs = [];
            foreach ($node['$join'] as $part) {
                array_push($refs, ...self::compositionInputs($page, $part));
            }

            return $refs;
        }
        if (isset($node['$item'])) {
            $item = self::items($page)[$node['$item']];
            $leaves = [];
            self::leaves($item['data'], ['source' => 'item', 'id' => $item['id'], 'path' => ['data']], $leaves);

            return array_map(static fn (string $key): array => json_decode($key, true, 512, JSON_THROW_ON_ERROR), array_keys($leaves));
        }
        if (isset($node['$fact'])) {
            return [['source' => 'fact', 'id' => $node['$fact'], 'path' => [$node['field']]]];
        }
        if (isset($node['$source'])) {
            return [['source' => 'item', 'id' => $node['item'], 'path' => ['data', 'entries', ['id' => $node['$source']], $node['field']]]];
        }
        if (isset($node['$link'])) {
            return [['source' => 'item', 'id' => $node['$link'], 'path' => ['data', 'entries', ['id' => $node['entry']], $node['field']]]];
        }

        return [];
    }

    private static function leaves(mixed $node, array $ref, array &$leaves): void
    {
        if (is_string($node) && trim($node) !== '') {
            $leaves[CareerCurrentAuthorityPackage::encodeCanonical($ref)] = $node;
        } elseif (is_array($node)) {
            foreach ($node as $key => $child) {
                if (in_array($key, ['id', 'fact_id', 'question_key', 'question_intent', 'source_refs', 'fact_refs', 'claim_refs', 'column_keys'], true)) {
                    continue;
                }
                $selector = is_int($key) && is_array($child) && isset($child['id']) ? ['id' => $child['id']] : $key;
                self::leaves($child, [...$ref, 'path' => [...$ref['path'], $selector]], $leaves);
            }
        }
    }

    private static function coversParts(string $value, array $refs): bool
    {
        foreach ($refs as $ref) {
            if (! isset($ref['parts'])) {
                return true;
            }
        }
        // A split leaf is reconciled only when every segment in that explicit parser is covered.
        $groups = [];
        foreach ($refs as $ref) {
            $part = $ref['parts'][0];
            $group = json_encode([$part[0], $part[2]]);
            $rest = $ref;
            array_shift($rest['parts']);
            if ($rest['parts'] === []) {
                unset($rest['parts']);
            }
            $groups[$group][$part[1]][] = $rest;
        }
        foreach ($groups as $group => $segments) {
            [$delimiter, $limit] = json_decode($group, true);
            $parts = explode($delimiter, $value, $limit);
            $complete = true;
            foreach ($parts as $index => $part) {
                if (! isset($segments[$index]) || ! self::coversParts(trim($part), $segments[$index])) {
                    $complete = false;
                    break;
                }
            }
            if ($complete) {
                return true;
            }
        }

        return false;
    }

    public static function assert(array $page): void
    {
        if (! array_key_exists('authoring_structure', $page)) {
            return;
        }
        $structure = $page['authoring_structure'];
        $schema = self::schema();
        if ($page['locale'] !== 'zh-CN' || ! is_array($structure)
            || self::keys($structure) !== ['contract_version', 'inventory', 'module_order', 'slots']
            || ($structure['contract_version'] ?? null) !== self::VERSION
            || ($structure['module_order'] ?? null) !== $schema['module_order']
            || ! is_array($structure['slots'] ?? null)
            || self::keys($structure['slots']) !== self::keys($schema['slots'])
            || ! is_array($structure['inventory'] ?? null)
            || self::keys($structure['inventory']) !== self::keys(self::inventory($page))) {
            self::fail();
        }
        $bindings = [];
        $items = self::items($page);
        $aliases = [
            'interface.path.entry_decisions_heading' => 'career_path_block.entry_heading',
            'interface.navigation.test_cta_label' => 'primary_cta.label',
            'interface.sources.faq_heading' => 'sections.sources.title',
        ];
        foreach ($structure['slots'] as $id => $slot) {
            if (! is_array($slot) || self::keys($slot) !== ['ref', 'status']) {
                self::fail();
            }
            if ($slot['status'] === 'unfilled' && $slot['ref'] === null) {
                continue;
            }
            if ($slot['status'] !== 'mapped' || ! is_array($slot['ref'])) {
                self::fail();
            }
            $value = self::reference($page, $slot['ref']);
            if (! is_string($value) || trim($value) === '') {
                self::fail();
            }
            $binding = CareerCurrentAuthorityPackage::encodeCanonical($slot['ref']);
            // Source metadata is also reusable; this never permits duplicated prose.
            $sourceMetadata = $slot['ref']['source'] === 'item'
                && ($items[$slot['ref']['id']]['type'] ?? null) === 'sources'
                && count($slot['ref']['path']) === 4
                && array_slice($slot['ref']['path'], 0, 2) === ['data', 'entries']
                && in_array($slot['ref']['path'][3], ['name', 'url', 'publisher', 'period', 'scope', 'market', 'accessed_at', 'evidence_type', 'limitation'], true);
            $samePosition = isset($bindings[$binding])
                && ($aliases[$id] ?? $id) === ($aliases[$bindings[$binding]] ?? $bindings[$binding]);
            if (isset($bindings[$binding]) && ! in_array($slot['ref']['source'], ['fact', 'page'], true)
                && ! $sourceMetadata && ! $samePosition) {
                self::fail();
            }
            $bindings[$binding] = $id;
        }
        if ($structure['inventory'] !== self::inventory($page, $structure['slots'])) {
            self::fail();
        }
    }

    private static function keys(array $value): array
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);

        return $keys;
    }

    private static function fail(): never
    {
        throw new CareerCurrentAuthorityPackageFailure('CAREER_AUTHORING_STRUCTURE_INVALID');
    }
}
