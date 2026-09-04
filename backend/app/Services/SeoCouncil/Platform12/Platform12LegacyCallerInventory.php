<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class Platform12LegacyCallerInventory
{
    private const DEFINITION_PATH = 'seo-agent/council/platform12/inventory/seo.platform12_legacy_callers.v1.json';

    /** @var list<string> */
    private const SCANNABLE_EXTENSIONS = ['json', 'md', 'mjs', 'php', 'py', 'sh', 'ts', 'yaml', 'yml'];

    public function __construct(private readonly SeoRegistryHasher $hasher) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $definition = $this->definition();
        $legacy = $this->legacyEntrypoints($definition);
        $current = array_map(fn (array $caller): array => [
            ...$caller,
            'evidence_verified' => $this->evidenceVerified($caller),
        ], $definition['current_callers']);

        $inventory = [
            'schema_version' => $definition['schema_version'],
            'inventory_id' => $definition['inventory_id'],
            'inventory_version' => $definition['inventory_version'],
            'inventory_state' => $definition['inventory_state'],
            'definition_hash' => $this->hasher->hash($definition),
            'authority_owner' => $definition['authority_owner'],
            'deletion_rule' => $definition['deletion_rule'],
            'scan_domains' => $definition['scan_domains'],
            'legacy_entrypoints' => $legacy,
            'current_callers' => $current,
            'summary' => [
                'legacy_entrypoint_count' => count($legacy),
                'retired_count' => count(array_filter($legacy, static fn (array $row): bool => $row['classification'] === 'retired')),
                'deferred_count' => count(array_filter($legacy, static fn (array $row): bool => $row['classification'] === 'deferred')),
                'delete_ready_count' => count(array_filter($legacy, static fn (array $row): bool => $row['delete_ready'] === true)),
                'current_caller_count' => count($current),
                'active_not_owned_by_council_count' => count(array_filter($current, static fn (array $row): bool => $row['state'] === 'active_not_owned_by_council')),
                'unverified_current_evidence_count' => count(array_filter($current, static fn (array $row): bool => $row['evidence_verified'] === false)),
            ],
            'read_only' => true,
            'execution_allowed' => false,
            'runtime_switches_changed' => false,
            'writes' => 0,
        ];
        $inventory['inventory_hash'] = $this->hasher->hash($inventory);

        return $inventory;
    }

    /** @return array<string, mixed> */
    private function definition(): array
    {
        $decoded = json_decode((string) file_get_contents(resource_path(self::DEFINITION_PATH)), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)
            || ($decoded['schema_version'] ?? null) !== 'seo.platform12_legacy_caller_inventory.v1'
            || ($decoded['inventory_version'] ?? null) !== '1.0.0'
            || ($decoded['inventory_state'] ?? null) !== 'READ_ONLY'
            || ! is_array($decoded['scan_domains'] ?? null)
            || ! is_array($decoded['current_callers'] ?? null)) {
            throw new RuntimeException('LEGACY_CALLER_INVENTORY_DEFINITION_INVALID');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $definition @return list<array<string, mixed>> */
    private function legacyEntrypoints(array $definition): array
    {
        $definitions = [];
        foreach (glob(app_path('Console/Commands/SeoAgent*.php')) ?: [] as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match('/protected\s+\x24signature\s*=\s*\'([^\'\s]+)/', $source, $matches) !== 1) {
                continue;
            }
            $definitions[$matches[1]] = $this->relativePath($file);
        }
        ksort($definitions, SORT_STRING);

        $references = $this->referenceIndex(array_keys($definitions), $definition['scan_domains']);
        $rows = [];
        foreach ($definitions as $entrypoint => $path) {
            $byDomain = [];
            foreach (array_keys($definition['scan_domains']) as $domain) {
                $paths = array_values(array_filter(
                    $references[$domain][$entrypoint] ?? [],
                    static fn (string $reference): bool => $reference !== $path,
                ));
                sort($paths, SORT_STRING);
                $byDomain[$domain] = $paths;
            }
            $referenceCount = array_sum(array_map('count', $byDomain));
            $zeroCallProven = $referenceCount === 0;
            $replacement = 'fap-api Council Admission or the canonical domain service';
            $classification = $zeroCallProven ? 'retired' : 'deferred';

            $rows[] = [
                'entrypoint' => $entrypoint,
                'kind' => 'legacy_cli',
                'definition_path' => $path,
                'classification' => $classification,
                'authority_owner' => 'historical wrapper only; no authority',
                'replacement' => $replacement,
                'audit_history_value' => $byDomain['audit_history'] !== [] || $byDomain['documentation'] !== []
                    ? 'retained tests or documentation'
                    : 'definition history only',
                'references' => $byDomain,
                'reference_count' => $referenceCount,
                'zero_call_proven' => $zeroCallProven,
                'delete_ready' => $classification === 'retired' && $zeroCallProven && $replacement !== '',
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $entrypoints
     * @param  array<string, list<string>>  $domains
     * @return array<string, array<string, list<string>>>
     */
    private function referenceIndex(array $entrypoints, array $domains): array
    {
        $index = [];
        $known = array_fill_keys($entrypoints, true);
        $entrypointPattern = '/'.preg_quote('seo'.'-agent:', '/').'[a-z0-9-]+/';
        foreach ($domains as $domain => $roots) {
            $index[$domain] = [];
            foreach ($this->files($roots) as $path => $absolute) {
                $source = (string) file_get_contents($absolute);
                preg_match_all($entrypointPattern, $source, $matches);
                foreach (array_unique($matches[0]) as $entrypoint) {
                    if (isset($known[$entrypoint])) {
                        $index[$domain][$entrypoint][] = $path;
                    }
                }
            }
        }

        return $index;
    }

    /** @param list<string> $roots @return array<string, string> */
    private function files(array $roots): array
    {
        $files = [];
        $repositoryRoot = dirname(base_path());
        foreach ($roots as $root) {
            $absolute = $repositoryRoot.'/'.$root;
            if (is_file($absolute)) {
                $files[$root] = $absolute;

                continue;
            }
            if (! is_dir($absolute)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute));
            foreach ($iterator as $file) {
                if (! $file->isFile()
                    || $file->getSize() > 2_000_000
                    || ! in_array(strtolower($file->getExtension()), self::SCANNABLE_EXTENSIONS, true)) {
                    continue;
                }
                $path = $this->relativePath($file->getPathname());
                if (str_starts_with($path, 'docs/codex/')
                    || str_contains($path, '/generated/')
                    || str_contains($path, '/vendor/')
                    || str_contains($path, '/node_modules/')) {
                    continue;
                }
                $files[$path] = $file->getPathname();
            }
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /** @param array<string, mixed> $caller */
    private function evidenceVerified(array $caller): bool
    {
        $repositoryRoot = dirname(base_path());
        $matched = false;
        foreach ($caller['evidence_refs'] ?? [] as $path) {
            $absolute = $repositoryRoot.'/'.$path;
            if (! is_file($absolute)) {
                return false;
            }
            $matched = $matched || str_contains((string) file_get_contents($absolute), (string) $caller['entrypoint']);
        }

        return ($caller['evidence_refs'] ?? []) !== [] && $matched;
    }

    private function relativePath(string $path): string
    {
        return str_replace(dirname(base_path()).'/', '', $path);
    }
}
