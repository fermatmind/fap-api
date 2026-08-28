<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use Throwable;

class CareerContentV3CanonicalReader
{
    /** @var array<string,array<string,mixed>> */
    private array $indexes = [];

    /** @var array<string,array<string,mixed>> */
    private array $pages = [];

    public function __construct(
        private readonly CareerContentV3AuthorityPackage $package,
        private readonly ?string $backendRoot = null,
    ) {}

    /** @return array<string,mixed> */
    public function authority(?string $backendRoot = null): array
    {
        $root = $backendRoot ?? $this->backendRoot ?? base_path();
        $resolved = realpath($root);
        if (! is_string($resolved)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_BACKEND_ROOT_INVALID');
        }

        return $this->indexes[$resolved] ??= $this->package->manifestIndex($resolved);
    }

    /** @return array<string,mixed> */
    public function page(string $slug, string $locale, ?string $backendRoot = null): array
    {
        $slug = strtolower(trim($slug));
        $locale = $this->locale($locale);
        $index = $this->authority($backendRoot);
        $key = $index['root'].'|'.$slug.'|'.$locale;

        if (! isset($this->pages[$key])) {
            $raw = $this->package->pageFromIndexForRuntime($index, $slug, $locale);
            $this->pages[$key] = $this->isolateInvalidBlocks($raw)['content'];
        }

        return $this->pages[$key];
    }

    /** @return array<string,mixed> */
    public function fileEntry(string $slug, string $locale, ?string $backendRoot = null): array
    {
        $slug = strtolower(trim($slug));
        $locale = $this->locale($locale);
        $entry = data_get($this->authority($backendRoot), 'entries.'.$slug.'.'.$locale);
        if (is_array($entry)) {
            return $entry;
        }

        throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_PAGE_MISSING');
    }

    /**
     * Replace any cached/DB-derived v3 body with the installed per-page authority.
     * The legacy surface is accepted only when it matches the manifest-bound compatibility projection.
     *
     * @param  array<string,mixed>  $surface
     * @return array<string,mixed>|null
     */
    public function hydrate(array $surface, string $slug, string $locale, ?string $backendRoot = null): ?array
    {
        try {
            $page = $this->page($slug, $locale, $backendRoot);
            $entry = $this->fileEntry($slug, $locale, $backendRoot);
            $legacy = $surface;
            unset($legacy['content_v3']);
            $projection = $this->compatibilityProjection($legacy);
            $sourcePage = data_get($projection, 'page.content');
            if (! is_array($sourcePage)
                || ! hash_equals((string) $page['source_content_sha256'], CareerCurrentAuthorityPackage::hashValue($sourcePage))
                || ! hash_equals((string) $entry['legacy_projection_sha256'], CareerCurrentAuthorityPackage::hashValue($projection))) {
                return null;
            }
            $surface['content_v3'] = $page;

            return $surface;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $surface @return array<string,mixed> */
    public function compatibilityProjection(array $surface): array
    {
        $required = [
            'surface_version', 'asset_type', 'asset_role', 'status', 'available_locales', 'page',
            'component_order', 'sources', 'structured_data_from_visible_content', 'implementation_contract',
        ];
        $projection = [];
        foreach ($required as $field) {
            if (! array_key_exists($field, $surface)) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_COMPATIBILITY_INVALID');
            }
            $projection[$field] = $surface[$field];
        }
        foreach (['presentation_v1', 'presentation_v2'] as $field) {
            if (array_key_exists($field, $surface)) {
                $projection[$field] = $surface[$field];
            }
        }

        return $projection;
    }

    /**
     * Runtime defense for untrusted hydrated copies. Invalid blocks are isolated and never become
     * metadata, FAQ JSON-LD, summaries, or quotable answers. Installed authority still validates strictly.
     *
     * @param  array<string,mixed>  $content
     * @return array{content:array<string,mixed>,isolated_block_ids:list<string>}
     */
    public function isolateInvalidBlocks(array $content): array
    {
        if (! is_array($content['blocks'] ?? null) || ! array_is_list($content['blocks'])) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_INVALID');
        }
        $blocks = $content['blocks'];
        $content['blocks'] = [];
        CareerContentV3Contract::assert($content);
        $seenBlocks = [];
        $seenItems = [];
        $isolated = [];
        foreach ($blocks as $index => $block) {
            try {
                $candidateBlocks = $seenBlocks;
                $candidateItems = $seenItems;
                CareerContentV3Contract::assertBlock($block, $candidateBlocks, $candidateItems);
                $seenBlocks = $candidateBlocks;
                $seenItems = $candidateItems;
                $content['blocks'][] = $block;
            } catch (Throwable) {
                $id = is_array($block) && is_string($block['id'] ?? null)
                    ? $block['id'] : 'block-'.($index + 1);
                $isolated[] = $id;
            }
        }
        CareerContentV3Contract::assert($content);

        return ['content' => $content, 'isolated_block_ids' => $isolated];
    }

    public function forgetLoadedAuthority(): void
    {
        $this->indexes = [];
        $this->pages = [];
    }

    private function locale(string $locale): string
    {
        $normalized = strtolower(trim($locale));
        if ($normalized === 'en') {
            return 'en';
        }
        if (in_array($normalized, ['zh', 'zh-cn'], true)) {
            return 'zh-CN';
        }

        throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_LOCALE_INVALID');
    }
}
