<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Services\Template\TemplateLintService;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ContentLintService
{
    private const CARD_ACCESS_LEVELS = ['free', 'preview', 'paid'];

    public function __construct(private readonly TemplateLintService $templateLint)
    {
    }

    /**
     * @return array{ok:bool,packs:list<array<string,mixed>>}
     */
    public function lintAll(?string $packId = null): array
    {
        $packs = $this->discoverPacks();
        if (is_string($packId) && trim($packId) !== '') {
            $packId = trim($packId);
            $packs = array_values(array_filter($packs, fn (array $pack): bool => (string) ($pack['pack_id'] ?? '') === $packId));
        }

        $results = [];
        $ok = true;
        foreach ($packs as $pack) {
            $result = $this->lintPack($pack);
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
    public function lintPack(array $pack): array
    {
        $baseDir = (string) ($pack['base_dir'] ?? '');
        $packId = (string) ($pack['pack_id'] ?? '');
        $version = (string) ($pack['version'] ?? '');

        $errors = [];
        $variablesUsed = [];

        $sectionsSpecPath = $baseDir . DIRECTORY_SEPARATOR . 'report_section_policies.json';
        $sectionsAllowed = [];
        if (is_file($sectionsSpecPath)) {
            $sectionsDoc = $this->readJsonFile($sectionsSpecPath);
            if (!is_array($sectionsDoc)) {
                $errors[] = $this->error($sectionsSpecPath, 'sections.spec', 'Invalid JSON.');
            } else {
                $rawItems = $sectionsDoc['items'] ?? ($sectionsDoc['sections'] ?? null);
                if (is_array($rawItems)) {
                    $sectionsAllowed = array_values(array_filter(array_map('strval', array_keys($rawItems)), fn (string $k): bool => $k !== ''));
                }
            }
        }

        $rulesPath = $baseDir . DIRECTORY_SEPARATOR . 'report_select_rules.json';
        if (is_file($rulesPath)) {
            $rulesDoc = $this->readJsonFile($rulesPath);
            $rulesNode = is_array($rulesDoc) ? ($rulesDoc['rules'] ?? $rulesDoc) : null;
            if (!is_array($rulesNode)) {
                $errors[] = $this->error($rulesPath, 'rules', 'Invalid rules JSON.');
            }
        }

        $cardFiles = glob($baseDir . DIRECTORY_SEPARATOR . 'report_cards_*.json') ?: [];
        foreach ($cardFiles as $cardFile) {
            $doc = $this->readJsonFile($cardFile);
            if (!is_array($doc)) {
                $errors[] = $this->error($cardFile, 'cards.doc', 'Invalid JSON.');
                continue;
            }

            $items = $doc['items'] ?? null;
            if (!is_array($items)) {
                $errors[] = $this->error($cardFile, 'cards.items', 'items must be an array.');
                continue;
            }

            foreach ($items as $index => $item) {
                $blockId = is_array($item) ? (string) ($item['id'] ?? "idx:{$index}") : "idx:{$index}";
                $blockRef = basename($cardFile) . '#' . $blockId;

                if (!is_array($item)) {
                    $errors[] = $this->error($cardFile, $blockRef, 'Card item must be an object.');
                    continue;
                }

                foreach (['id', 'section', 'tags', 'priority', 'access_level', 'module_code'] as $required) {
                    if (!array_key_exists($required, $item)) {
                        $errors[] = $this->error($cardFile, $blockRef, "Missing required field: {$required}.");
                    }
                }

                $accessLevel = strtolower(trim((string) ($item['access_level'] ?? '')));
                if ($accessLevel === '' || !in_array($accessLevel, self::CARD_ACCESS_LEVELS, true)) {
                    $errors[] = $this->error($cardFile, $blockRef, 'Invalid access_level. Allowed: free|preview|paid.');
                }

                $section = trim((string) ($item['section'] ?? ''));
                if ($sectionsAllowed !== [] && $section !== '' && !in_array($section, $sectionsAllowed, true)) {
                    $errors[] = $this->error($cardFile, $blockRef, "Section '{$section}' is not declared in section policies.");
                }

                $templateFields = [];
                foreach (['title', 'desc', 'body'] as $field) {
                    if (is_string($item[$field] ?? null)) {
                        $templateFields["{$blockRef}.{$field}"] = (string) $item[$field];
                    }
                }
                foreach (['bullets', 'tips'] as $field) {
                    if (!is_array($item[$field] ?? null)) {
                        continue;
                    }
                    foreach ($item[$field] as $idx => $value) {
                        if (is_string($value)) {
                            $templateFields["{$blockRef}.{$field}.{$idx}"] = $value;
                        }
                    }
                }

                foreach ($templateFields as $fieldRef => $template) {
                    foreach ($this->extractTemplateVariables($template) as $varName) {
                        $variablesUsed[$varName] = true;
                    }

                    $tplLint = $this->templateLint->lintTemplateString($template, $fieldRef);
                    foreach ($tplLint as $tplIssue) {
                        $unknown = is_array($tplIssue['unknown'] ?? null) ? $tplIssue['unknown'] : [];
                        if ($unknown !== []) {
                            $errors[] = $this->error(
                                $cardFile,
                                (string) ($tplIssue['block_id'] ?? $fieldRef),
                                'Unknown template variables: ' . implode(', ', $unknown)
                            );
                        }
                    }
                }
            }
        }

        return [
            'pack_id' => $packId,
            'version' => $version,
            'base_dir' => $baseDir,
            'ok' => $errors === [],
            'errors' => $errors,
            'variables_used' => array_keys($variablesUsed),
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
     * @return list<string>
     */
    private function extractTemplateVariables(string $template): array
    {
        if (!str_contains($template, '{{')) {
            return [];
        }

        preg_match_all('/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}/', $template, $matches);
        $vars = is_array($matches[1] ?? null) ? $matches[1] : [];

        $out = [];
        foreach ($vars as $varName) {
            $varName = trim((string) $varName);
            if ($varName === '') {
                continue;
            }
            $out[$varName] = true;
        }

        return array_keys($out);
    }

    /**
     * @return array{file:string,block_id:string,message:string}
     */
    private function error(string $file, string $blockId, string $message): array
    {
        return [
            'file' => $file,
            'block_id' => $blockId,
            'message' => $message,
        ];
    }
}
