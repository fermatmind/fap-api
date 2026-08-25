<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/fap-api-career-canonical-builder/scripts/assemble_sharded_current.php';

final class CareerContentMergeFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}

final class CareerContentCurrentMerger
{
    /** @var list<string> */
    private const FORBIDDEN_PUBLIC_KEYS = [
        'private_answers', 'score_vector', 'percentile', 'selector_trace', 'attempt_url',
        'report_url', 'user_id', 'order_id', 'payment_id', 'receipt_id', 'subscription_id',
    ];

    /** @return array<string,mixed> */
    public function merge(
        string $repoRoot,
        string $requestPath,
        string $receiptPath,
        string $handoffPath,
        bool $write,
    ): array {
        $repoRoot = $this->realDirectory($repoRoot, 'REPOSITORY_ROOT_INVALID');
        $currentRoot = $repoRoot.'/backend/content_assets/career/current';
        $request = $this->objectFile($requestPath, 'LOCKED_REQUEST_INVALID');
        $receipt = $this->objectFile($receiptPath, 'CONTENT_AGENT_RECEIPT_INVALID');
        $handoff = $this->objectFile($handoffPath, 'RELEASE_HANDOFF_INVALID');
        $this->assertAuthorityBinding($request, $receipt, $handoff, $receiptPath);

        $module = (string) $request['module'];
        $publicationSlugs = $handoff['publication_slugs'];
        $expectedRows = $this->indexedLocks($request['expected_row_hashes'], 'slug', 'EXPECTED_ROW_LOCK_INVALID');
        $expectedShards = $this->indexedLocks($request['expected_shard_hashes'], 'path', 'EXPECTED_SHARD_LOCK_INVALID');
        $manifestPath = $currentRoot.'/manifest.json';
        $manifest = $this->objectFile($manifestPath, 'CURRENT_MANIFEST_INVALID');
        $declarations = $this->manifestDeclarations($manifest);
        $candidateRecords = [];
        $candidateDigests = [];
        $slugResults = [];
        foreach ($receipt['slug_results'] as $row) {
            if (is_array($row) && is_string($row['slug'] ?? null)) {
                $slugResults[$row['slug']] = $row;
            }
        }
        $shardSlugs = [];
        foreach ($publicationSlugs as $slug) {
            $index = CareerLegacyCurrentSharder::shardIndex($slug);
            $relative = sprintf('%s/shard-%02d.jsonl', $module, $index);
            if (! isset($expectedRows[$slug], $expectedShards[$relative])) {
                throw new CareerContentMergeFailure('OPTIMISTIC_LOCK_SCOPE_MISSING');
            }
            $candidatePath = dirname($requestPath).'/dry-compile-'.$slug.'/candidate-row.json';
            $result = $slugResults[$slug] ?? null;
            if (! is_array($result)
                || ($result['editorial_state'] ?? null) !== 'PASS'
                || ($result['evidence_adapter_state'] ?? null) !== 'PASS'
                || ($result['dry_compile_state'] ?? null) !== 'PASS'
                || ! is_string($result['candidate_row_digest'] ?? null)
                || ! hash_equals($result['candidate_row_digest'], hash_file('sha256', $candidatePath) ?: '')) {
                throw new CareerContentMergeFailure('PUBLICATION_SLUG_GATE_INCOMPLETE');
            }
            $candidate = (new CareerLegacyCurrentSharder)->decodeCanonicalRow(rtrim(
                (string) file_get_contents($candidatePath),
                "\r\n",
            ));
            if (($candidate['canonical_slug'] ?? null) !== $slug) {
                throw new CareerContentMergeFailure('CANDIDATE_SLUG_MISMATCH');
            }
            [$split] = (new CareerLegacyCurrentSharder)->splitRow($candidate);
            $candidateRecords[$slug] = [
                'en' => $split['en'][$module],
                'zh-CN' => $split['zh-CN'][$module],
            ];
            $candidateDigests[$slug] = hash('sha256', CareerLegacyCurrentSharder::canonicalJson($candidateRecords[$slug]));
            $shardSlugs[$relative][] = $slug;
        }
        if (count($shardSlugs) > 64) {
            throw new CareerContentMergeFailure('AFFECTED_SHARD_LIMIT_EXCEEDED');
        }

        $currentRecords = [];
        $stagedBytes = [];
        $beforeHashes = [];
        foreach ($shardSlugs as $relative => $slugs) {
            $path = $currentRoot.'/'.$relative;
            $raw = $this->declaredShardBytes($path, $declarations[$relative] ?? null);
            $beforeHashes[$relative] = hash('sha256', $raw);
            if (! hash_equals($expectedShards[$relative], $beforeHashes[$relative])) {
                throw new CareerContentMergeFailure('STALE_EXPECTED_SHARD_HASH');
            }
            $rows = $this->decodeShard($raw, $module, (int) $declarations[$relative]['shard_index']);
            foreach ($rows as $row) {
                $slug = $row['canonical_slug'];
                if (in_array($slug, $slugs, true)) {
                    $currentRecords[$slug][$row['locale']] = $row;
                }
            }
            foreach ($slugs as $slug) {
                if (array_keys($currentRecords[$slug] ?? []) !== ['en', 'zh-CN']) {
                    throw new CareerContentMergeFailure('CURRENT_ROW_PAIR_MISSING');
                }
                $projection = ['module' => $module, 'rows' => $currentRecords[$slug], 'slug' => $slug];
                if (! hash_equals($expectedRows[$slug], hash('sha256', CareerLegacyCurrentSharder::canonicalJson($projection)))) {
                    throw new CareerContentMergeFailure('STALE_EXPECTED_ROW_HASH');
                }
            }
            $replacement = [];
            foreach ($rows as $row) {
                $slug = $row['canonical_slug'];
                $replacement[] = isset($candidateRecords[$slug])
                    ? $candidateRecords[$slug][$row['locale']]
                    : $row;
            }
            usort($replacement, static fn (array $a, array $b): int => strcmp(
                $a['canonical_slug']."\0".$a['locale'],
                $b['canonical_slug']."\0".$b['locale'],
            ));
            $stagedBytes[$relative] = implode("\n", array_map(
                static fn (array $row): string => CareerLegacyCurrentSharder::canonicalJson($row),
                $replacement,
            ))."\n";
        }

        $this->assertDependencies($currentRoot, $declarations, $publicationSlugs, $module, $candidateRecords);
        $updatedManifest = $manifest;
        $changed = [];
        foreach ($updatedManifest['shards'] as &$declaration) {
            $relative = $declaration['path'];
            if (! isset($stagedBytes[$relative])) {
                continue;
            }
            $newHash = hash('sha256', $stagedBytes[$relative]);
            if (! hash_equals($declaration['sha256'], $newHash)) {
                $changed[] = $relative;
            }
            $declaration['sha256'] = $newHash;
            $declaration['row_count'] = substr_count($stagedBytes[$relative], "\n");
        }
        unset($declaration);
        $updatedManifest['aggregate_sha256'] = $this->aggregateHash($updatedManifest);
        $manifestBytes = $this->prettyJson($updatedManifest);
        $manifestChanged = ! hash_equals(hash('sha256', (string) file_get_contents($manifestPath)), hash('sha256', $manifestBytes));

        if ($write && ($changed !== [] || $manifestChanged)) {
            $manifestBytes = $this->activate(
                $currentRoot,
                $manifestPath,
                $stagedBytes,
                $changed,
                $updatedManifest,
                $beforeHashes,
            );
            $manifestChanged = true;
        }
        sort($changed, SORT_STRING);

        return [
            'contract_version' => 'career.content_agent.current_merge_receipt.v1',
            'status' => $write ? 'MERGED_CURRENT_CANDIDATES' : 'PASS_CURRENT_MERGE_DRY_RUN',
            'module' => $module,
            'publication_slugs' => $publicationSlugs,
            'candidate_module_digests' => $candidateDigests,
            'affected_shard_count' => count($shardSlugs),
            'rewritten_shards' => $changed,
            'manifest_updated' => $manifestChanged,
            'manifest_sha256' => hash('sha256', $manifestBytes),
            'release_authority_handoff_sha256' => hash_file('sha256', $handoffPath),
            'repository_write_count' => $write ? count($changed) + (int) $manifestChanged : 0,
            'current_write_count' => $write ? count($changed) + (int) $manifestChanged : 0,
            'database_writes' => 0,
            'cache_writes' => 0,
            'cms_writes' => 0,
            'publisher_writes' => 0,
            'deploy_writes' => 0,
            'sitemap_writes' => 0,
            'discoverability_writes' => 0,
            'search_submissions' => 0,
        ];
    }

    /** @param array<string,mixed> $request @param array<string,mixed> $receipt @param array<string,mixed> $handoff */
    private function assertAuthorityBinding(array $request, array $receipt, array $handoff, string $receiptPath): void
    {
        $slugs = $handoff['publication_slugs'] ?? null;
        $handoffKeys = array_keys($handoff);
        sort($handoffKeys, SORT_STRING);
        $expectedHandoffKeys = [
            'content_agent_receipt_sha256', 'contract_version', 'module',
            'publication_slugs', 'release_authority', 'request_hash',
        ];
        $sortedSlugs = is_array($slugs) ? $slugs : [];
        sort($sortedSlugs, SORT_STRING);
        if (($handoff['contract_version'] ?? null) !== 'career.content_agent.release_handoff.v1'
            || $handoffKeys !== $expectedHandoffKeys
            || ($handoff['release_authority'] ?? null) !== 'fap-api-career-release-authority'
            || ($handoff['request_hash'] ?? null) !== ($receipt['request_hash'] ?? null)
            || ($handoff['module'] ?? null) !== ($request['module'] ?? null)
            || ($handoff['content_agent_receipt_sha256'] ?? null) !== hash_file('sha256', $receiptPath)
            || ($receipt['final_state'] ?? null) !== 'ORCHESTRATED'
            || ! is_array($slugs) || $slugs === [] || $slugs !== $sortedSlugs || $slugs !== array_values(array_unique($slugs))
            || array_diff($slugs, (array) ($request['slugs'] ?? [])) !== []
            || array_diff($slugs, (array) ($receipt['publishable_slugs'] ?? [])) !== []) {
            throw new CareerContentMergeFailure('RELEASE_AUTHORITY_HANDOFF_MISMATCH');
        }
        if (in_array(true, (array) ($receipt['permissions'] ?? []), true)
            || array_filter((array) ($receipt['write_counts'] ?? []), static fn (mixed $count): bool => $count !== 0) !== []) {
            throw new CareerContentMergeFailure('CONTENT_AGENT_RECEIPT_AUTHORITY_FORBIDDEN');
        }
        $gates = array_map(static fn (array $gate): array => [$gate['gate'] ?? null, $gate['state'] ?? null], (array) ($receipt['gates'] ?? []));
        if ($gates !== [
            ['research', 'PASS'], ['editorial', 'PASS'], ['evidence_adapter', 'PASS'],
            ['dry_compile', 'PASS'], ['orchestrator', 'PASS'],
        ]) {
            throw new CareerContentMergeFailure('CONTENT_AGENT_FIVE_GATE_PROOF_MISSING');
        }
    }

    /** @param list<array<string,mixed>> $rows @return array<string,string> */
    private function indexedLocks(array $rows, string $key, string $safeCode): array
    {
        $result = [];
        foreach ($rows as $row) {
            $identity = is_array($row) ? ($row[$key] ?? null) : null;
            $hash = is_array($row) ? ($row['sha256'] ?? null) : null;
            if (! is_string($identity) || isset($result[$identity]) || ! is_string($hash) || preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
                throw new CareerContentMergeFailure($safeCode);
            }
            $result[$identity] = $hash;
        }

        return $result;
    }

    /** @param array<string,mixed> $manifest @return array<string,array<string,mixed>> */
    private function manifestDeclarations(array $manifest): array
    {
        if (($manifest['contract_version'] ?? null) !== 'career.sharded_current.manifest.v1'
            || ($manifest['modules'] ?? null) !== CareerLegacyCurrentSharder::MODULES
            || ! is_array($manifest['shards'] ?? null) || count($manifest['shards']) !== 640
            || ! hash_equals((string) ($manifest['aggregate_sha256'] ?? ''), $this->aggregateHash($manifest))) {
            throw new CareerContentMergeFailure('CURRENT_MANIFEST_INVALID');
        }
        $indexed = [];
        foreach ($manifest['shards'] as $position => $row) {
            $module = CareerLegacyCurrentSharder::MODULES[intdiv($position, 64)] ?? null;
            $index = $position % 64;
            $relative = sprintf('%s/shard-%02d.jsonl', $module, $index);
            if (! is_array($row) || ($row['path'] ?? null) !== $relative
                || ($row['module'] ?? null) !== $module || ($row['shard_index'] ?? null) !== $index
                || isset($indexed[$relative])) {
                throw new CareerContentMergeFailure('CURRENT_MANIFEST_INVALID');
            }
            $indexed[$relative] = $row;
        }

        return $indexed;
    }

    /** @param array<string,mixed>|null $declaration */
    private function declaredShardBytes(string $path, ?array $declaration): string
    {
        $raw = is_file($path) && ! is_link($path) ? file_get_contents($path) : false;
        if (! is_string($raw) || $raw === '' || $declaration === null
            || ! hash_equals((string) ($declaration['sha256'] ?? ''), hash('sha256', $raw))) {
            throw new CareerContentMergeFailure('CURRENT_SHARD_DECLARATION_MISMATCH');
        }

        return $raw;
    }

    /** @return list<array<string,mixed>> */
    private function decodeShard(string $raw, string $module, int $index): array
    {
        $rows = [];
        $previous = null;
        foreach (explode("\n", substr($raw, 0, -1)) as $line) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $slug = $row['canonical_slug'] ?? null;
            $locale = $row['locale'] ?? null;
            $sort = is_string($slug) && is_string($locale) ? $slug."\0".$locale : '';
            if (($row['module'] ?? null) !== $module || ! in_array($locale, ['en', 'zh-CN'], true)
                || ! is_string($slug) || CareerLegacyCurrentSharder::shardIndex($slug) !== $index
                || ($previous !== null && strcmp($previous, $sort) >= 0)) {
                throw new CareerContentMergeFailure('CURRENT_SHARD_ROW_INVALID');
            }
            $previous = $sort;
            $rows[] = $row;
        }

        return $rows;
    }

    /** @param array<string,array<string,mixed>> $declarations @param list<string> $slugs @param array<string,array<string,array<string,mixed>>> $candidateRecords */
    private function assertDependencies(string $currentRoot, array $declarations, array $slugs, string $module, array $candidateRecords): void
    {
        $assembler = new CareerShardedCurrentAssembler;
        foreach ($slugs as $slug) {
            $index = CareerLegacyCurrentSharder::shardIndex($slug);
            $records = [];
            foreach (CareerLegacyCurrentSharder::MODULES as $candidateModule) {
                $relative = sprintf('%s/shard-%02d.jsonl', $candidateModule, $index);
                $rows = $this->decodeShard(
                    $this->declaredShardBytes($currentRoot.'/'.$relative, $declarations[$relative] ?? null),
                    $candidateModule,
                    $index,
                );
                foreach ($rows as $row) {
                    if ($row['canonical_slug'] === $slug) {
                        $records[$row['locale']][$candidateModule] = $candidateModule === $module
                            ? $candidateRecords[$slug][$row['locale']]
                            : $row;
                    }
                }
            }
            $assembled = $assembler->assembleRecords($records);
            $this->assertPublicBoundary($assembled);
            $pages = array_keys((array) ($assembled['page_payload_json'] ?? [])) === ['page']
                ? ($assembled['page_payload_json']['page'] ?? null)
                : ($assembled['page_payload_json'] ?? null);
            if (! is_array($pages) || ! is_array($pages['en']['riasec_fit_block'] ?? null)) {
                throw new CareerContentMergeFailure('RIASEC_PRIMARY_SIGNAL_MISSING');
            }
        }
    }

    /** @param array<string,mixed> $row */
    private function assertPublicBoundary(array $row): void
    {
        $walk = function (mixed $value) use (&$walk): void {
            if (! is_array($value)) {
                return;
            }
            foreach ($value as $key => $child) {
                if (is_string($key) && in_array($key, self::FORBIDDEN_PUBLIC_KEYS, true)) {
                    throw new CareerContentMergeFailure('PRIVATE_RUNTIME_DATA_FORBIDDEN');
                }
                $walk($child);
            }
        };
        $walk($row);
    }

    /** @param array<string,string> $stagedBytes @param list<string> $changed @param array<string,mixed> $updatedManifest @param array<string,string> $beforeHashes */
    private function activate(string $currentRoot, string $manifestPath, array $stagedBytes, array $changed, array $updatedManifest, array $beforeHashes): string
    {
        $lockPath = sys_get_temp_dir().'/career-current-merger-'.hash('sha256', $currentRoot).'.lock';
        $lock = fopen($lockPath, 'c+');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new CareerContentMergeFailure('CURRENT_MERGER_LOCK_FAILED');
        }
        $backups = [];
        try {
            foreach ($beforeHashes as $relative => $hash) {
                if (! hash_equals($hash, hash_file('sha256', $currentRoot.'/'.$relative) ?: '')) {
                    throw new CareerContentMergeFailure('OPTIMISTIC_LOCK_RECHECK_FAILED');
                }
            }
            $liveManifest = $this->objectFile($manifestPath, 'CURRENT_MANIFEST_INVALID');
            $this->manifestDeclarations($liveManifest);
            $updatedDeclarations = [];
            foreach ($updatedManifest['shards'] as $declaration) {
                $updatedDeclarations[$declaration['path']] = $declaration;
            }
            foreach ($liveManifest['shards'] as &$declaration) {
                if (in_array($declaration['path'], $changed, true)) {
                    $declaration = $updatedDeclarations[$declaration['path']]
                        ?? throw new CareerContentMergeFailure('CURRENT_MANIFEST_REBASE_FAILED');
                }
            }
            unset($declaration);
            $liveManifest['aggregate_sha256'] = $this->aggregateHash($liveManifest);
            $manifestBytes = $this->prettyJson($liveManifest);
            foreach ($changed as $relative) {
                $path = $currentRoot.'/'.$relative;
                $backups[$path] = (string) file_get_contents($path);
                $this->atomicReplace($path, $stagedBytes[$relative]);
            }
            $backups[$manifestPath] = (string) file_get_contents($manifestPath);
            $this->atomicReplace($manifestPath, $manifestBytes);

            return $manifestBytes;
        } catch (Throwable $throwable) {
            foreach (array_reverse($backups, true) as $path => $bytes) {
                $this->atomicReplace($path, $bytes);
            }
            throw $throwable;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function atomicReplace(string $path, string $bytes): void
    {
        $temporary = tempnam(dirname($path), '.career-content-merge-');
        if (! is_string($temporary)) {
            throw new CareerContentMergeFailure('CURRENT_ATOMIC_WRITE_FAILED');
        }
        try {
            $mode = fileperms($path);
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)
                || ($mode !== false && ! chmod($temporary, $mode & 0777))
                || ! rename($temporary, $path)) {
                throw new CareerContentMergeFailure('CURRENT_ATOMIC_WRITE_FAILED');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @param array<string,mixed> $manifest */
    private function aggregateHash(array $manifest): string
    {
        $projection = array_intersect_key($manifest, array_flip([
            'contract_version', 'modules', 'shards', 'registries', 'coverage', 'module_completeness',
        ]));

        return hash('sha256', CareerLegacyCurrentSharder::canonicalJson($projection));
    }

    /** @return array<string,mixed> */
    private function objectFile(string $path, string $safeCode): array
    {
        if (! is_file($path) || is_link($path)) {
            throw new CareerContentMergeFailure($safeCode);
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new CareerContentMergeFailure($safeCode);
        }

        return $decoded;
    }

    private function realDirectory(string $path, string $safeCode): string
    {
        $resolved = realpath($path);
        if ($resolved === false || ! is_dir($resolved) || is_link($path)) {
            throw new CareerContentMergeFailure($safeCode);
        }

        return rtrim($resolved, '/');
    }

    /** @param array<string,mixed> $value */
    private function prettyJson(array $value): string
    {
        $canonicalize = function (mixed $item) use (&$canonicalize): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (array_is_list($item)) {
                return array_map($canonicalize, $item);
            }
            ksort($item, SORT_STRING);
            foreach ($item as $key => $child) {
                $item[$key] = $canonicalize($child);
            }

            return $item;
        };

        return json_encode($canonicalize($value), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $options = getopt('', ['request:', 'receipt:', 'handoff:', 'write']);
        $repoRoot = dirname(__DIR__, 4);
        $result = (new CareerContentCurrentMerger)->merge(
            $repoRoot,
            (string) ($options['request'] ?? ''),
            (string) ($options['receipt'] ?? ''),
            (string) ($options['handoff'] ?? ''),
            array_key_exists('write', $options),
        );
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        exit(0);
    } catch (Throwable $throwable) {
        echo json_encode([
            'status' => 'FAIL_CURRENT_CANDIDATE_MERGE',
            'safe_error_code' => $throwable instanceof CareerContentMergeFailure
                ? $throwable->safeCode
                : ($throwable instanceof CareerShardedCurrentAssemblyFailure
                    ? $throwable->safeCode
                    : ($throwable instanceof CareerLegacyCurrentSplitFailure
                        ? $throwable->safeCode
                        : 'UNEXPECTED_CURRENT_CANDIDATE_MERGE_FAILURE')),
            'database_writes' => 0,
            'cache_writes' => 0,
            'cms_writes' => 0,
            'publisher_writes' => 0,
            'deploy_writes' => 0,
            'sitemap_writes' => 0,
            'discoverability_writes' => 0,
            'search_submissions' => 0,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        exit(1);
    }
}
