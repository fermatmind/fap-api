<?php

declare(strict_types=1);

namespace App\Domain\Career\Compilation;

use App\Domain\Career\Display\CareerContentV3Contract;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;

final class CareerContentV3Projector
{
    private const ENHANCED_SLUGS = ['accountants-and-auditors'];

    /** @var list<string> */
    private const FRONTEND_STRUCTURAL_COMPONENTS = [
        'breadcrumb', 'hero', 'primary_cta', 'secondary_cta', 'final_cta',
    ];

    /** @var list<string> */
    private const STRUCTURAL_KEYS = [
        'heading', 'h1', 'title', 'label', 'question', 'cta_label', 'badge', 'caption',
        '问题', '标题', '表头', '风险', '概念', '步骤', '方向', '指标', '类型',
    ];

    /** @param array<string,mixed> $page @param array<string,mixed>|null $presentationV2 @param array<string,mixed>|list<mixed> $sources */
    public function project(string $slug, string $locale, array $page, ?array $presentationV2, array $sources = []): array
    {
        if (! in_array($locale, CareerCurrentAuthorityPackage::LOCALES, true)) {
            throw new CareerTenBlockCompileFailure('CONTENT_V3_LOCALE_INVALID');
        }
        $hero = is_array($page['hero'] ?? null) ? $page['hero'] : [];
        $name = $this->string($hero['h1'] ?? null) ?? $this->string($hero['title'] ?? null);
        if ($name === null) {
            throw new CareerTenBlockCompileFailure('CONTENT_V3_IDENTITY_INVALID');
        }
        $state = in_array($slug, self::ENHANCED_SLUGS, true) ? 'enhanced' : 'legacy';
        $groups = is_array($presentationV2['groups'] ?? null) ? $presentationV2['groups'] : [];
        $blocks = [];
        $consumedComponents = [];
        foreach ($groups as $groupIndex => $group) {
            if (! is_array($group)) {
                continue;
            }
            $groupId = $this->key($group['id'] ?? null) ?? 'block-'.($groupIndex + 1);
            $items = [];
            foreach (array_values((array) ($group['component_ids'] ?? [])) as $componentId) {
                if (! is_string($componentId) || ! array_key_exists($componentId, $page)
                    || in_array($componentId, self::FRONTEND_STRUCTURAL_COMPONENTS, true)) {
                    continue;
                }
                $consumedComponents[$componentId] = true;
                foreach ($this->items($componentId, $page[$componentId], $slug) as $item) {
                    $items[] = $item;
                }
            }
            if ($items === []) {
                continue;
            }
            $blocks[] = [
                'id' => $groupId,
                'copy_key' => 'career.block.'.$groupId,
                'content_state' => $state,
                'availability' => 'available',
                'items' => $items,
            ];
        }
        foreach ($this->orderedMap($page) as $componentId => $value) {
            if (! is_string($componentId) || isset($consumedComponents[$componentId])
                || in_array($componentId, [...self::FRONTEND_STRUCTURAL_COMPONENTS, 'path'], true)) {
                continue;
            }
            $id = $this->key(str_replace('_', '-', $componentId));
            if ($id === null) {
                continue;
            }
            $items = $this->items($componentId, $value, $slug);
            $blocks[] = [
                'id' => $id,
                'copy_key' => 'career.block.'.$id,
                'content_state' => $state,
                'availability' => $items === [] ? 'missing' : 'available',
                'items' => $items,
            ];
        }
        $sourceItems = $this->sourceItems($sources);
        if ($sourceItems !== []) {
            $blocks[] = [
                'id' => 'source-register',
                'copy_key' => 'career.block.source-register',
                'content_state' => $state,
                'availability' => 'available',
                'items' => [[
                    'id' => 'published-sources', 'copy_key' => 'career.item.published-sources',
                    'type' => 'sources', 'availability' => 'available',
                    'data' => ['entries' => $sourceItems],
                ]],
            ];
        }
        $content = [
            'contract_version' => CareerContentV3Contract::CONTRACT_VERSION,
            'locale' => $locale,
            'subject' => [
                'canonical_slug' => $slug,
                'name' => $name,
                'summary' => $this->string($hero['quick_answer'] ?? null),
            ],
            'content_state' => $state,
            'source_content_sha256' => CareerCurrentAuthorityPackage::hashValue($page),
            'blocks' => $blocks,
        ];
        CareerContentV3Contract::assert($content);

        return $content;
    }

    /** @return list<array<string,mixed>> */
    private function items(string $componentId, mixed $value, string $slug): array
    {
        if (is_array($value) && ($value['availability'] ?? null) === 'unavailable') {
            return [[
                'id' => $this->itemId($componentId, 1), 'copy_key' => $this->itemCopyKey($componentId),
                'type' => 'notice', 'availability' => 'missing', 'data' => [],
            ]];
        }
        if ($componentId === 'faq_block') {
            $faqs = $this->faqEntries($value, $slug);
            if ($faqs !== []) {
                return [[
                    'id' => $this->itemId($componentId, 1), 'copy_key' => $this->itemCopyKey($componentId),
                    'type' => 'faq', 'availability' => 'available',
                    'data' => ['entries' => $faqs],
                ]];
            }
        }
        $links = $this->links($value, $componentId);
        $items = [];
        $sequences = $this->sequences($value);
        $index = 1;
        foreach ($sequences as $sequence) {
            if ($sequence['values'] === []) {
                continue;
            }
            $items[] = [
                'id' => $this->itemId($componentId, $index++),
                'copy_key' => $this->itemCopyKey($componentId),
                'type' => $sequence['type'],
                'availability' => 'available',
                'data' => $sequence['data'],
            ];
        }
        if ($links !== []) {
            $items[] = [
                'id' => $this->itemId($componentId, $index), 'copy_key' => $this->itemCopyKey($componentId),
                'type' => 'links', 'availability' => 'available',
                'data' => ['entries' => $links],
            ];
        }

        return $items;
    }

    /** @return list<array{type:string,values:list<string>,data:array<string,mixed>}> */
    private function sequences(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            return [['type' => 'prose', 'values' => [trim($value)], 'data' => ['paragraphs' => [trim($value)]]]];
        }
        if (! is_array($value)) {
            return [];
        }
        if (array_is_list($value) && $value !== [] && collect($value)->every(fn (mixed $item): bool => is_string($item) && trim($item) !== '')) {
            $entries = array_values(array_map('trim', $value));

            return [['type' => 'list', 'values' => $entries, 'data' => ['entries' => $entries]]];
        }
        $result = [];
        foreach ($this->orderedMap($value) as $key => $child) {
            if ($this->structural((string) $key) || $this->urlKey((string) $key)) {
                continue;
            }
            if (is_array($child) && array_is_list($child) && $child !== [] && collect($child)->every('is_array')) {
                $entries = [];
                foreach ($child as $entryIndex => $entry) {
                    $values = $this->leafValues($entry);
                    if ($values !== []) {
                        $entryId = is_array($entry) ? $this->key($entry['id'] ?? null) : null;
                        $entries[] = ['id' => $entryId ?? 'entry-'.($entryIndex + 1), 'values' => $values];
                    }
                }
                if ($entries !== []) {
                    $flat = array_merge(...array_column($entries, 'values'));
                    $result[] = ['type' => 'cards', 'values' => $flat, 'data' => ['entries' => $entries]];
                }

                continue;
            }
            foreach ($this->sequences($child) as $sequence) {
                $result[] = $sequence;
            }
        }
        if ($result === []) {
            $values = $this->leafValues($value);
            if ($values !== []) {
                $result[] = ['type' => 'prose', 'values' => $values, 'data' => ['paragraphs' => $values]];
            }
        }

        return $result;
    }

    /** @return list<string> */
    private function leafValues(mixed $value, ?string $parentKey = null): array
    {
        if (is_string($value)) {
            return trim($value) === '' || ($parentKey !== null && ($this->structural($parentKey) || $this->urlKey($parentKey)))
                ? [] : [trim($value)];
        }
        if (is_int($value) || is_float($value)) {
            return [(string) $value];
        }
        if (! is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($this->orderedMap($value) as $key => $child) {
            $result = array_merge($result, $this->leafValues($child, (string) $key));
        }

        return array_values(array_unique($result));
    }

    /** @return list<array{id:string,question_key:string,answer:string}> */
    private function faqEntries(mixed $value, string $slug): array
    {
        if (! is_array($value)) {
            return [];
        }
        $candidateLists = [$value['items'] ?? null, $value['questions'] ?? null, $value];
        foreach ($candidateLists as $candidates) {
            if (! is_array($candidates) || ! array_is_list($candidates)) {
                continue;
            }
            $entries = [];
            foreach ($candidates as $index => $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }
                $answer = $this->string($candidate['answer'] ?? null) ?? $this->string($candidate['回答'] ?? null);
                if ($answer !== null) {
                    $entries[] = [
                        'id' => 'faq-'.($index + 1),
                        'question_key' => $this->faqQuestionKey($slug, $index),
                        'answer' => $answer,
                    ];
                }
            }
            if ($entries !== []) {
                return $entries;
            }
        }

        return [];
    }

    private function faqQuestionKey(string $slug, int $index): string
    {
        $standard = [
            'salary', 'outlook', 'daily-work', 'comparison', 'ai-replacement',
            'human-skills', 'personality-fit', 'work-setting', 'career-worth',
        ];
        $accounting = [
            'accounting.daily-work', 'accounting.comparison', 'accounting.ai-replacement',
            'accounting.automatable-tasks', 'accounting.human-skills', 'accounting.salary',
            'accounting.education', 'accounting.credentials', 'accounting.career-change',
            'accounting.career-worth',
        ];
        $semantic = in_array($slug, self::ENHANCED_SLUGS, true)
            ? ($accounting[$index] ?? null)
            : ($standard[$index] ?? null);

        return 'career.faq.'.($semantic ?? 'item-'.($index + 1));
    }

    /** @return list<array{id:string,entity:string,relation:string,url:string}> */
    private function links(mixed $value, string $componentId): array
    {
        $found = [];
        $walk = function (mixed $node) use (&$walk, &$found, $componentId): void {
            if (! is_array($node)) {
                return;
            }
            $url = $this->string($node['href'] ?? null) ?? $this->string($node['url'] ?? null) ?? $this->string($node['链接'] ?? null);
            if ($url !== null && $this->safeUrl($url)) {
                $entity = $this->string($node['entity'] ?? null) ?? $this->string($node['name'] ?? null)
                    ?? $this->string($node['label'] ?? null) ?? $this->string($node['title'] ?? null)
                    ?? $this->string($node['来源'] ?? null) ?? parse_url($url, PHP_URL_HOST) ?? $componentId;
                $found[$url] = ['entity' => $entity, 'url' => $url];
            }
            foreach ($this->orderedMap($node) as $child) {
                $walk($child);
            }
        };
        $walk($value);
        $result = [];
        foreach (array_values($found) as $index => $entry) {
            $result[] = [
                'id' => 'link-'.($index + 1), 'entity' => $entry['entity'],
                'relation' => 'related-evidence', 'url' => $entry['url'],
            ];
        }

        return $result;
    }

    /** @param array<mixed> $value @return array<mixed> */
    private function orderedMap(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /** @param array<string,mixed>|list<mixed> $sources @return list<array{id:string,name:string,url:?string}> */
    private function sourceItems(array $sources): array
    {
        $entries = array_is_list($sources)
            ? $sources
            : ($sources['references'] ?? array_values($this->orderedMap($sources)));
        if (! is_array($entries)) {
            return [];
        }
        $result = [];
        foreach (array_values($entries) as $index => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = $this->string($entry['label'] ?? null) ?? $this->string($entry['name'] ?? null) ?? $this->string($entry['来源'] ?? null);
            $url = $this->string($entry['url'] ?? null) ?? $this->string($entry['href'] ?? null) ?? $this->string($entry['链接'] ?? null);
            if ($name !== null && ($url === null || $this->safeUrl($url))) {
                $result[] = ['id' => 'source-'.($index + 1), 'name' => $name, 'url' => $url];
            }
        }

        return $result;
    }

    private function itemId(string $componentId, int $index): string
    {
        return ($this->key(str_replace('_', '-', $componentId)) ?? 'content').'-'.$index;
    }

    private function itemCopyKey(string $componentId): string
    {
        return 'career.item.'.($this->key(str_replace('_', '-', $componentId)) ?? 'content');
    }

    private function structural(string $key): bool
    {
        $normalized = strtolower(trim($key));

        return in_array($normalized, self::STRUCTURAL_KEYS, true)
            || str_ends_with($normalized, '_heading') || str_ends_with($normalized, '_title')
            || str_ends_with($normalized, '_label') || str_ends_with($normalized, '_question');
    }

    private function urlKey(string $key): bool
    {
        return in_array(strtolower($key), ['url', 'href', 'link', '链接', 'cta_href'], true);
    }

    private function safeUrl(string $value): bool
    {
        return preg_match('/\Ahttps:\/\//', $value) === 1 || preg_match('/\A\/(?:en|zh)\//', $value) === 1
            || preg_match('/\A#[a-z0-9][a-z0-9_-]*\z/', $value) === 1;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function key(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[a-z0-9]+(?:[._-][a-z0-9]+)*\z/', $value) === 1 ? $value : null;
    }
}
