<?php

declare(strict_types=1);

namespace App\Services\Cms\SeoContentPackage;

use Illuminate\Support\Str;
use RuntimeException;

final class SeoContentPackageCompiler
{
    private const REQUIRED_PASSTHROUGH = [
        'contracts/DYNAMIC_CTA_CONTRACT.json',
        'contracts/INTERNAL_LINK_PLAN.json',
        'review/claim_gate.md',
        'review/operator_review.md',
    ];

    public function __construct(private readonly SeoContentPackageDraftImporter $draftImporter) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function compile(array $options): array
    {
        $source = $this->realDirectory((string) ($options['package'] ?? ''));
        $output = $this->absolutePath((string) ($options['output_dir'] ?? ''));
        $locales = $this->locales($options['locales'] ?? []);
        $dryRun = (bool) ($options['dry_run'] ?? true);

        if ($source === null) {
            throw new RuntimeException('Source package directory does not exist.');
        }
        if ($output === '') {
            throw new RuntimeException('--output-dir is required.');
        }
        if ($this->samePath($source, $output)) {
            throw new RuntimeException('Output directory must not overwrite the source package.');
        }
        if ($locales !== ['zh-CN', 'en']) {
            throw new RuntimeException('Daily Mode C compiler requires locales zh-CN,en.');
        }

        $manifest = $this->json($source.'/manifest.json', 'manifest.json');
        $translationGroupId = trim((string) ($manifest['translation_group_id'] ?? ''));
        if ($translationGroupId === '' || mb_strlen($translationGroupId) > 64) {
            throw new RuntimeException('translation_group_id is required and must be 64 characters or fewer.');
        }

        $files = $this->sourceFiles($source);
        foreach (self::REQUIRED_PASSTHROUGH as $required) {
            if (! isset($files[$required])) {
                throw new RuntimeException($required.' is required and cannot be inferred safely.');
            }
        }

        $identities = [];
        foreach ($locales as $locale) {
            $draft = $this->localeJson($source, 'CMS_IMPORT_DRAFT', $locale);
            $fields = $this->localeJson($source, 'CMS_FIELDS', $locale);
            $slug = trim((string) ($draft['slug'] ?? $fields['slug'] ?? ''));
            if ($slug === '' || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                throw new RuntimeException($locale.' slug is missing or invalid.');
            }
            $canonical = trim((string) ($draft['canonical_path'] ?? $draft['canonical_url'] ?? $fields['canonical_path'] ?? $fields['canonical_url'] ?? ''));
            $expectedCanonical = ($locale === 'zh-CN' ? '/zh/articles/' : '/en/articles/').$slug;
            if ($canonical !== $expectedCanonical) {
                throw new RuntimeException($locale.' canonical must match '.$expectedCanonical.'.');
            }
            $itemGroup = trim((string) ($draft['translation_group_id'] ?? $fields['translation_group_id'] ?? $translationGroupId));
            if ($itemGroup !== $translationGroupId) {
                throw new RuntimeException($locale.' translation_group_id does not match manifest.');
            }

            $pageSource = $this->pageSource($source, $locale, $slug, $draft, $manifest);
            $pageRelative = 'pages/'.$locale.'-'.$slug.'.md';
            $files[$pageRelative] = (string) file_get_contents($pageSource);

            $normalizedDraft = $this->normalizeProjection($draft, $fields, $locale, $slug, $canonical, $translationGroupId, $pageRelative);
            $normalizedFields = $this->normalizeProjection($fields, $draft, $locale, $slug, $canonical, $translationGroupId, $pageRelative);
            unset($files['cms/CMS_IMPORT_DRAFT_'.$locale.'.json'], $files['cms/CMS_FIELDS_'.$locale.'.json']);
            $files['cms/CMS_IMPORT_DRAFT_'.$locale.'_'.$slug.'.json'] = $this->encode($normalizedDraft);
            $files['cms/CMS_FIELDS_'.$locale.'_'.$slug.'.json'] = $this->encode($normalizedFields);
            $identities[$locale] = ['slug' => $slug, 'canonical_path' => $canonical];
        }

        $manifest['locales'] = $locales;
        $manifest['translation_group_id'] = $translationGroupId;
        $manifest['compiler_status'] = 'FINAL_DERIVED_IMPORT_READY_PACKAGE';
        $files['manifest.json'] = $this->encode($manifest);
        $files['contracts/PUBLIC_CANONICAL_ROUTE_CONTRACT.json'] = $this->encode([
            'routes' => array_values(array_column($identities, 'canonical_path')),
        ]);
        $files['contracts/ROUTE_ALIAS_CONTRACT.json'] ??= $this->encode([
            'known_alias_autofix_allowed' => false,
            'unknown_alias_requires_operator_input' => true,
            'known_aliases' => (object) [],
        ]);
        $files['contracts/SOCIAL_IMAGE_METADATA_REQUIREMENTS.json'] ??= $this->encode([
            'required' => true,
            'source' => 'media/IMAGE_ASSET_MANIFEST.json',
        ]);
        $files['contracts/PRIVATE_URL_GUARD.json'] ??= $this->encode([
            'forbidden_paths' => ['/result', '/results', '/orders', '/order', '/share', '/pay', '/payment', '/history', '/take'],
            'forbidden_query_keys' => ['result_id', 'order_id', 'payment_id', 'token', 'score', 'user_id', 'report_id'],
        ]);
        $files['codex/qa_checklist.md'] ??= "- [ ] importer dry-run passed\n- [ ] no production writes\n";

        if (isset($files['media/IMAGE_ASSET_MANIFEST.json'])) {
            $media = json_decode($files['media/IMAGE_ASSET_MANIFEST.json'], true);
            if (! is_array($media)) {
                throw new RuntimeException('media/IMAGE_ASSET_MANIFEST.json must be valid JSON.');
            }
            $media['assets'] = array_map(fn (mixed $asset): mixed => is_array($asset) ? $this->normalizeAsset($asset) : $asset, (array) ($media['assets'] ?? []));
            $files['media/IMAGE_ASSET_MANIFEST.json'] = $this->encode($media);
        }

        ksort($files);
        $this->validateWithImporter($files, $translationGroupId, $identities);
        $sourceSha = $this->directorySha($source);
        $derivedSha = $this->filesSha($files);
        $report = [
            'status' => 'FINAL_DERIVED_IMPORT_READY_PACKAGE',
            'source_package' => $source,
            'source_sha256' => $sourceSha,
            'derived_package' => $output,
            'derived_sha256' => $derivedSha,
            'translation_group_id' => $translationGroupId,
            'locales' => $locales,
            'identities' => $identities,
            'deterministic_changes' => [
                'importer_compatible_file_names',
                'cms_field_projection',
                'required_contract_projection',
                'media_manifest_normalization',
                'hold_state_normalization',
            ],
            'unchanged_surfaces' => [
                'article_body' => true,
                'claims' => true,
                'faq_text' => true,
                'cta_destinations' => true,
                'internal_link_destinations' => true,
                'source_images' => true,
                'release_state' => true,
            ],
            'importer_dry_run_ready' => true,
        ];
        $files['PACKAGE_COMPILATION_REPORT.json'] = $this->encode($report);

        if (! $dryRun) {
            $this->writeOutput($output, $files);
        }

        return $report + [
            'ok' => true,
            'dry_run' => $dryRun,
            'writes_attempted' => ! $dryRun,
            'writes_committed' => ! $dryRun,
            'file_count' => count($files),
        ];
    }

    /** @return array<string,string> */
    private function sourceFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $files[$relative] = (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }

    /** @return array<string,mixed> */
    private function localeJson(string $root, string $prefix, string $locale): array
    {
        $generic = $root.'/cms/'.$prefix.'_'.$locale.'.json';
        $matches = is_file($generic) ? [$generic] : (glob($root.'/cms/'.$prefix.'_'.$locale.'_*.json') ?: []);
        if (count($matches) !== 1) {
            throw new RuntimeException('Exactly one '.$prefix.' projection is required for '.$locale.'.');
        }

        return $this->json($matches[0], basename($matches[0]));
    }

    /** @param array<string,mixed> $primary @param array<string,mixed> $fallback @return array<string,mixed> */
    private function normalizeProjection(array $primary, array $fallback, string $locale, string $slug, string $canonical, string $group, string $page): array
    {
        $value = array_replace($fallback, $primary);
        $value['locale'] = $locale;
        $value['slug'] = $slug;
        $value['translation_group_id'] = $group;
        $value['canonical_path'] = $canonical;
        $value['canonical_url'] = $canonical;
        $value['meta_title'] = trim((string) ($value['meta_title'] ?? $value['seo_title'] ?? ''));
        $value['meta_description'] = trim((string) ($value['meta_description'] ?? $value['seo_description'] ?? ''));
        $value['seo_description'] = $value['meta_description'];
        $value['category_name'] = trim((string) ($value['category_name'] ?? $value['category_suggestion'] ?? ''));
        $value['body_markdown_file'] = $page;
        $value['primary_cta'] = $this->primaryCta($value);
        $value['schema_eligibility'] = ['article_schema' => false, 'breadcrumb_schema' => false, 'faq_schema' => false];
        foreach (['draft_only', 'no_publish', 'no_index', 'no_sitemap', 'no_llms', 'schema_hold', 'hreflang_hold', 'search_hold', 'revalidation_hold'] as $flag) {
            $value[$flag] = true;
        }
        if ($value['meta_title'] === '' || mb_strlen($value['meta_title']) > 70) {
            throw new RuntimeException($locale.' meta_title is required and must be 70 characters or fewer.');
        }
        if ($value['meta_description'] === '' || mb_strlen($value['meta_description']) > 180) {
            throw new RuntimeException($locale.' meta_description is required and must be 180 characters or fewer.');
        }

        return $value;
    }

    /** @param array<string,mixed> $value @return array{href:string,label:string} */
    private function primaryCta(array $value): array
    {
        $cta = is_array($value['primary_cta'] ?? null) ? $value['primary_cta'] : [];
        if (($cta['href'] ?? '') === '' && is_array($value['cta_slots'] ?? null)) {
            $candidate = $value['cta_slots']['primary'] ?? $value['cta_slots'][0] ?? [];
            $cta = is_array($candidate) ? $candidate : [];
        }
        $href = trim((string) ($cta['href'] ?? $cta['url'] ?? ''));
        $label = trim((string) ($cta['label'] ?? $cta['text'] ?? ''));
        if ($href === '' || $label === '') {
            throw new RuntimeException('A deterministic primary CTA href and label are required.');
        }

        return ['href' => $href, 'label' => $label];
    }

    /** @param array<string,mixed> $asset @return array<string,mixed> */
    private function normalizeAsset(array $asset): array
    {
        if (! is_string($asset['alt_text'] ?? null) || trim($asset['alt_text']) === '') {
            throw new RuntimeException('Every media asset requires string alt_text.');
        }
        $dimensions = $asset['dimensions_expected'] ?? null;
        if (is_string($dimensions) && preg_match('/^(\d+)x(\d+)$/', $dimensions, $match)) {
            $dimensions = ['width' => (int) $match[1], 'height' => (int) $match[2], 'exact' => false];
        }
        if (! is_array($dimensions) || (int) ($dimensions['width'] ?? 0) < 1 || (int) ($dimensions['height'] ?? 0) < 1) {
            throw new RuntimeException('Every media asset requires structured dimensions_expected.');
        }
        $asset['dimensions_expected'] = ['width' => (int) $dimensions['width'], 'height' => (int) $dimensions['height'], 'exact' => (bool) ($dimensions['exact'] ?? false)];
        $provenance = $asset['provenance'] ?? [];
        if (is_string($provenance)) {
            $provenance = ['generated_by' => $provenance];
        }
        $asset['provenance'] = [
            'generated_by' => trim((string) ($provenance['generated_by'] ?? 'unknown')),
            'competitor_asset' => (bool) ($provenance['competitor_asset'] ?? false),
            'official_logo' => (bool) ($provenance['official_logo'] ?? false),
        ];
        if ($asset['provenance']['competitor_asset'] || $asset['provenance']['official_logo']) {
            throw new RuntimeException('Competitor assets and official logos are forbidden.');
        }

        return $asset;
    }

    /** @param array<string,mixed> $draft @param array<string,mixed> $manifest */
    private function pageSource(string $root, string $locale, string $slug, array $draft, array $manifest): string
    {
        $candidates = [
            (string) ($draft['body_markdown_file'] ?? ''),
            'pages/'.$locale.'-'.$slug.'.md',
            'pages/'.$locale.'/article.md',
        ];
        foreach ((array) ($manifest['pages'] ?? []) as $page) {
            if (is_array($page) && ($page['locale'] ?? null) === $locale) {
                $candidates[] = (string) ($page['file'] ?? '');
            }
        }
        foreach (array_unique($candidates) as $candidate) {
            $candidate = ltrim($candidate, '/');
            if ($candidate !== '' && is_file($root.'/'.$candidate)) {
                return $root.'/'.$candidate;
            }
        }

        throw new RuntimeException('Unable to resolve body markdown for '.$locale.'.');
    }

    /** @param array<string,string> $files */
    private function writeOutput(string $output, array $files): void
    {
        if (is_dir($output)) {
            $existing = $this->sourceFiles($output);
            unset($existing['PACKAGE_COMPILATION_REPORT.json']);
            $expected = $files;
            unset($expected['PACKAGE_COMPILATION_REPORT.json']);
            if ($this->filesSha($existing) === $this->filesSha($expected)) {
                return;
            }
            throw new RuntimeException('Output directory already exists with different contents.');
        }
        foreach ($files as $relative => $contents) {
            $path = $output.'/'.$relative;
            if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0775, true) && ! is_dir(dirname($path))) {
                throw new RuntimeException('Unable to create output directory.');
            }
            file_put_contents($path, $contents);
        }
    }

    /** @param array<string,string> $files @param array<string,array<string,string>> $identities */
    private function validateWithImporter(array $files, string $translationGroupId, array $identities): void
    {
        $root = sys_get_temp_dir().'/seo-mode-c-compiler-validation-'.bin2hex(random_bytes(8));
        try {
            $this->writeOutput($root, $files);
            $plan = $this->draftImporter->planFromDirectory([
                'package' => $root,
                'translation_group_id' => $translationGroupId,
                'locales' => ['zh-CN', 'en'],
                'dry_run' => true,
                'draft_only' => true,
                'no_publish' => true,
                'no_index' => true,
                'no_sitemap' => true,
                'no_llms' => true,
                'schema_hold' => true,
                'hreflang_hold' => true,
                'expected_slugs' => [
                    'zh-CN' => (string) ($identities['zh-CN']['slug'] ?? ''),
                    'en' => (string) ($identities['en']['slug'] ?? ''),
                ],
            ]);
            if (($plan['ok'] ?? false) !== true) {
                throw new RuntimeException('Compiled package failed importer dry-run: '.json_encode($plan['errors'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function removeDirectory(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($root);
    }

    /** @return array<string,mixed> */
    private function json(string $path, string $label): array
    {
        if (! is_file($path)) {
            throw new RuntimeException($label.' is required.');
        }
        $value = json_decode((string) file_get_contents($path), true);
        if (! is_array($value)) {
            throw new RuntimeException($label.' must be valid JSON.');
        }

        return $value;
    }

    /** @param array<string,mixed> $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    private function directorySha(string $root): string
    {
        return $this->filesSha($this->sourceFiles($root));
    }

    /** @param array<string,string> $files */
    private function filesSha(array $files): string
    {
        ksort($files);
        $context = hash_init('sha256');
        foreach ($files as $relative => $contents) {
            hash_update($context, $relative."\0".$contents."\0");
        }

        return hash_final($context);
    }

    private function realDirectory(string $path): ?string
    {
        $real = realpath($path);

        return $real !== false && is_dir($real) ? rtrim($real, '/') : null;
    }

    private function absolutePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (Str::startsWith($path, '/')) {
            return rtrim($path, '/');
        }

        return rtrim(base_path($path), '/');
    }

    private function samePath(string $source, string $output): bool
    {
        return rtrim($source, '/') === rtrim($output, '/');
    }

    /** @return list<string> */
    private function locales(mixed $value): array
    {
        $locales = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(static fn (mixed $locale): string => trim((string) $locale), $locales)));
    }
}
