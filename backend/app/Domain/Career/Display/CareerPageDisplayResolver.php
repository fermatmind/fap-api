<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

/** Resolves same-file content references; never reads legacy display rows. */
final class CareerPageDisplayResolver
{
    public const VERSION = 'career.detail.display.v1';

    public function resolve(array $content): ?array
    {
        $display = $content['display'] ?? null;
        if ($display === null) {
            return null;
        }
        if (! is_array($display) || ($display['contract_version'] ?? null) !== self::VERSION
            || ! is_array($display['components'] ?? null)
            || ! is_array($display['component_order'] ?? null)
            || ! CareerDisplayAssetComponentContract::supports($display['component_order'])
            || count(array_unique($display['component_order'])) !== count($display['component_order'])
            || array_diff($display['component_order'], array_keys($display['components'])) !== []
            || array_diff(array_keys($display['components']), $display['component_order']) !== []) {
            $this->fail();
        }
        $titles = $display['section_titles'] ?? null;
        if (! is_array($titles) || array_diff(array_column($content['blocks'], 'id'), array_keys($titles)) !== []) {
            $this->fail();
        }
        foreach ($titles as $title) {
            if (! is_string($title) || trim($title) === '') {
                $this->fail();
            }
        }
        $items = [];
        foreach ($content['blocks'] as $block) {
            foreach ($block['items'] as $item) {
                if (($item['visibility'] ?? 'public') !== 'internal') {
                    $items[$item['id']] = ['block' => $block['id'], ...$item];
                }
            }
        }
        $used = [];
        $links = [];
        $facts = array_column($content['fact_register']['facts'] ?? [], null, 'fact_id');
        $read = function (array $reference, string $id, string $type) use ($items): array {
            $item = $items[$id] ?? null;
            if (! is_array($item) || $item['availability'] !== 'available' || $item['type'] !== $type
                || $item['block'] !== ($reference['block'] ?? null)
                || $item['copy_key'] !== ($reference['copy_key'] ?? null)) {
                $this->fail();
            }

            return $item;
        };
        $resolve = function (mixed $node) use (&$resolve, &$used, &$links, $read, $facts, $content): mixed {
            if (! is_array($node)) {
                return $node;
            }
            if (isset($node['$item'])) {
                $id = $node['$item'];
                $item = $read($node, $id, $node['type'] ?? '');
                if (isset($used[$id]) || ! in_array($item['type'], ['prose', 'list', 'faq'], true)) {
                    $this->fail();
                }
                $used[$id] = true;
                if ($item['type'] === 'prose') {
                    if (count($item['data']['paragraphs']) !== 1) {
                        $this->fail();
                    }

                    return $item['data']['paragraphs'][0];
                }
                if ($item['type'] === 'faq') {
                    foreach ($item['data']['entries'] as $entry) {
                        if (! is_string($entry['question'] ?? null) || trim($entry['question']) === '') {
                            $this->fail();
                        }
                    }

                    return array_map(static fn (array $entry): array => ['question' => $entry['question'], 'answer' => $entry['answer']], $item['data']['entries']);
                }

                return $item['data']['entries'];
            }
            if (isset($node['$link'])) {
                $id = $node['$link'];
                $item = $read($node, $id, 'links');
                $entryId = $node['entry'] ?? '';
                $field = $node['field'] ?? '';
                $entry = array_column($item['data']['entries'], null, 'id')[$entryId] ?? null;
                if (! in_array($field, ['entity', 'url'], true) || ! is_string($entry[$field] ?? null)
                    || isset($links[$id][$entryId][$field])) {
                    $this->fail();
                }
                $used[$id] = true;
                $links[$id][$entryId][$field] = true;

                return $entry[$field];
            }
            if (isset($node['$fact'])) {
                $field = $node['field'] ?? '';
                $fact = $facts[$node['$fact']] ?? null;
                if (! in_array($field, ['period', 'display_value', 'derivation'], true)
                    || ! is_string($fact[$field] ?? null)) {
                    $this->fail();
                }

                return $fact[$field];
            }
            if (isset($node['$subject'])) {
                $field = $node['$subject'];
                if (! in_array($field, ['name', 'canonical_slug', 'summary'], true)) {
                    $this->fail();
                }

                return $content['subject'][$field];
            }
            $resolved = [];
            foreach ($node as $key => $value) {
                if (is_string($key) && str_starts_with($key, '$')) {
                    $this->fail();
                }
                $resolved[$key] = $resolve($value);
            }

            return $resolved;
        };
        $result = $resolve($display);
        foreach ($display['native_items'] ?? [] as $native) {
            $id = $native['id'] ?? '';
            $item = $read($native, $id, $native['type'] ?? '');
            $location = $native['location'] ?? '';
            $entryTypes = [
                'career.item.entry-role-comparison' => 'table', 'career.item.employer-evidence' => 'cards',
                'career.item.entry-work-sample-data' => 'cards', 'career.item.entry-portfolio' => 'cards',
                'career.item.interview-probation' => 'table', 'career.item.seven-day-trial' => 'timeline',
                'career.item.seven-day-decision' => 'list', 'career.item.credential-decision' => 'table',
                'career.item.credential-boundary' => 'notice',
            ];
            $valid = $location === 'sources' && $item['type'] === 'sources'
                || $location === 'entry_decisions' && $item['block'] === 'path'
                && ($entryTypes[$item['copy_key']] ?? null) === $item['type'];
            if (! $valid || isset($used[$id])) {
                $this->fail();
            }
            $used[$id] = true;
        }
        foreach ($links as $id => $entries) {
            foreach ($items[$id]['data']['entries'] as $entry) {
                if (! isset($entries[$entry['id']]['url'])) {
                    $this->fail();
                }
            }
        }
        if (array_diff(array_keys($items), array_keys($used)) !== []
            || $result['path'] !== '/'.($content['locale'] === 'zh-CN' ? 'zh' : 'en').'/career/jobs/'.$content['subject']['canonical_slug']) {
            $this->fail();
        }

        return $result;
    }

    private function fail(): never
    {
        throw new CareerCurrentAuthorityPackageFailure('CAREER_PAGE_DISPLAY_INVALID');
    }
}
