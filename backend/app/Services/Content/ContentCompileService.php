<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Services\Content\ContentPack;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ContentCompileService
{
    public function __construct(private readonly ContentLintService $lint)
    {
    }

    /**
     * @return array{ok:bool,packs:list<array<string,mixed>>}
     */
    public function compileAll(?string $packId = null): array
    {
        $packs = $this->discoverPacks();
        if (is_string($packId) && trim($packId) !== '') {
            $packId = trim($packId);
            $packs = array_values(array_filter($packs, fn (array $pack): bool => (string) ($pack['pack_id'] ?? '') === $packId));
        }

        $results = [];
        $ok = true;
        foreach ($packs as $pack) {
            $result = $this->compilePack($pack);
            $results[] = $result;
            if (!($result['ok'] ?? false)) {
                $ok = false;
            }
        }

        return [
            'ok' => $ok,
            'packs' => $results,
        ];
    }

    /**
     * @param array<string,mixed> $pack
     * @return array<string,mixed>
     */
    public function compilePack(array $pack): array
    {
        $lintResult = $this->lint->lintPack($pack);
        if (!($lintResult['ok'] ?? false)) {
            return [
                'pack_id' => (string) ($pack['pack_id'] ?? ''),
                'version' => (string) ($pack['version'] ?? ''),
                'ok' => false,
                'errors' => $lintResult['errors'] ?? [],
            ];
        }

        $manifest = is_array($pack['manifest'] ?? null) ? $pack['manifest'] : [];
        $baseDir = (string) ($pack['base_dir'] ?? '');
        $compiledDir = $baseDir . DIRECTORY_SEPARATOR . 'compiled';
        if (!is_dir($compiledDir)) {
            mkdir($compiledDir, 0775, true);
        }

        $packModel = new ContentPack(
            (string) ($pack['pack_id'] ?? ''),
            (string) ($manifest['scale_code'] ?? 'MBTI'),
            (string) ($manifest['region'] ?? ''),
            (string) ($manifest['locale'] ?? ''),
            (string) ($manifest['content_package_version'] ?? ''),
            $baseDir,
            $manifest,
        );

        $store = new \App\Services\Content\ContentStore([$packModel], [], basename($baseDir));

        $sections = [];
        $tagIndex = [];

        $cardFiles = glob($baseDir . DIRECTORY_SEPARATOR . 'report_cards_*.json') ?: [];
        sort($cardFiles);
        foreach ($cardFiles as $cardFile) {
            $base = basename($cardFile);
            if (str_starts_with($base, 'report_cards_fallback_')) {
                continue;
            }
            $section = str_replace(['report_cards_', '.json'], '', $base);
            $doc = $store->loadCardsDoc($section);
            $sections[$section] = $doc;

            $tagIndex[$section] = [];
            foreach ((array) ($doc['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = trim((string) ($item['id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                foreach ((array) ($item['tags'] ?? []) as $tag) {
                    $tag = trim((string) $tag);
                    if ($tag === '') {
                        continue;
                    }
                    $tagIndex[$section][$tag] = $tagIndex[$section][$tag] ?? [];
                    $tagIndex[$section][$tag][] = $id;
                }
            }

            foreach ($tagIndex[$section] as $tag => $ids) {
                $tagIndex[$section][$tag] = array_values(array_unique(array_map('strval', $ids)));
            }
            ksort($tagIndex[$section]);
        }

        $rules = $store->loadSelectRules();
        $sectionsSpec = $store->loadSectionPolicies();
        $variablesUsed = array_values(array_unique(array_map('strval', (array) ($lintResult['variables_used'] ?? []))));

        $sourceFiles = array_merge(
            [$baseDir . DIRECTORY_SEPARATOR . 'manifest.json'],
            glob($baseDir . DIRECTORY_SEPARATOR . 'report_cards_*.json') ?: [],
            glob($baseDir . DIRECTORY_SEPARATOR . 'report_select_rules.json') ?: [],
            glob($baseDir . DIRECTORY_SEPARATOR . 'report_section_policies.json') ?: []
        );
        $checksum = $this->computeChecksum($sourceFiles);
        $generatedAt = now()->toIso8601String();

        $this->writeJson($compiledDir . DIRECTORY_SEPARATOR . 'cards.normalized.json', [
            'schema' => 'fap.content.compiled.cards.v1',
            'pack_id' => (string) ($pack['pack_id'] ?? ''),
            'version' => (string) ($pack['version'] ?? ''),
            'generated_at' => $generatedAt,
            'sections' => $sections,
        ]);

        $this->writeJson($compiledDir . DIRECTORY_SEPARATOR . 'cards.tag_index.json', [
            'schema' => 'fap.content.compiled.tag_index.v1',
            'pack_id' => (string) ($pack['pack_id'] ?? ''),
            'version' => (string) ($pack['version'] ?? ''),
            'generated_at' => $generatedAt,
            'sections' => $tagIndex,
        ]);

        $this->writeJson($compiledDir . DIRECTORY_SEPARATOR . 'rules.normalized.json', [
            'schema' => 'fap.content.compiled.rules.v1',
            'pack_id' => (string) ($pack['pack_id'] ?? ''),
            'version' => (string) ($pack['version'] ?? ''),
            'generated_at' => $generatedAt,
            'rules' => $rules,
        ]);

        $this->writeJson($compiledDir . DIRECTORY_SEPARATOR . 'sections.spec.json', [
            'schema' => (string) ($sectionsSpec['schema'] ?? 'fap.report.section_policies.v1'),
            'pack_id' => (string) ($pack['pack_id'] ?? ''),
            'version' => (string) ($pack['version'] ?? ''),
            'generated_at' => $generatedAt,
            'items' => (array) ($sectionsSpec['items'] ?? []),
        ]);

        $this->writeJson($compiledDir . DIRECTORY_SEPARATOR . 'variables.used.json', [
            'schema' => 'fap.content.compiled.variables.v1',
            'pack_id' => (string) ($pack['pack_id'] ?? ''),
            'version' => (string) ($pack['version'] ?? ''),
            'generated_at' => $generatedAt,
            'variables' => $variablesUsed,
        ]);

        $this->writeJson($compiledDir . DIRECTORY_SEPARATOR . 'manifest.json', [
            'schema' => 'fap.content.compiled.manifest.v1',
            'pack_id' => (string) ($pack['pack_id'] ?? ''),
            'source_version' => (string) ($manifest['content_package_version'] ?? ''),
            'generated_at' => $generatedAt,
            'checksum' => $checksum,
            'files' => [
                'cards.normalized.json',
                'cards.tag_index.json',
                'rules.normalized.json',
                'sections.spec.json',
                'variables.used.json',
            ],
        ]);

        return [
            'pack_id' => (string) ($pack['pack_id'] ?? ''),
            'version' => (string) ($pack['version'] ?? ''),
            'ok' => true,
            'compiled_dir' => $compiledDir,
            'checksum' => $checksum,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function discoverPacks(): array
    {
        $root = (string) config('content_packs.root', base_path('content_packages'));
        if (!is_dir($root)) {
            return [];
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $out = [];
        foreach ($it as $file) {
            if (strtolower((string) $file->getFilename()) !== 'manifest.json') {
                continue;
            }

            $manifestPath = (string) $file->getPathname();
            $normalizedPath = str_replace('\\\\', '/', $manifestPath);
            if (str_contains($normalizedPath, '/_deprecated/')) {
                continue;
            }
            if (str_contains($normalizedPath, '/compiled/')) {
                continue;
            }
            if (!str_contains($normalizedPath, '/default/')) {
                continue;
            }
            $manifest = $this->readJsonFile($manifestPath);
            if (!is_array($manifest)) {
                continue;
            }

            $out[] = [
                'pack_id' => (string) ($manifest['pack_id'] ?? ''),
                'version' => (string) ($manifest['content_package_version'] ?? ''),
                'manifest_path' => $manifestPath,
                'base_dir' => dirname($manifestPath),
                'manifest' => $manifest,
            ];
        }

        usort($out, fn (array $a, array $b): int => strcmp((string) ($a['pack_id'] ?? ''), (string) ($b['pack_id'] ?? '')));

        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJsonFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param list<string> $files
     */
    private function computeChecksum(array $files): string
    {
        $hash = hash_init('sha256');
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            hash_update($hash, (string) $file);
            hash_update($hash, (string) file_get_contents($file));
        }

        return hash_final($hash);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        file_put_contents(
            $path,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }
}
