<?php

declare(strict_types=1);

namespace App\Services\EnglishParity;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Symfony\Component\Process\Process;

final class EnglishParityProgramScanner
{
    public const SCHEMA_VERSION = 'english-content-parity-program-ledger.v1';

    private const BODY_LIMIT = 2_000_000;

    /** @var list<string> */
    private const ALLOWED_HOSTS = ['fermatmind.com', 'api.fermatmind.com'];

    /** @var list<string> */
    private const PRIVATE_PATH_MARKERS = ['/attempts/', '/orders/', '/payments/', '/reports/', '/result/lookup', '/results/lookup'];

    /** @var array<string, array<string, mixed>> */
    private const LANE_BASELINE = [
        'W1' => ['name' => 'MBTI', 'expected' => ['comparisons' => 7, 'result_content' => 46]],
        'W2' => ['name' => 'Big Five', 'expected' => ['public' => 52, 'historical' => 50, 'result_units' => 16]],
        'W3' => ['name' => 'Editorial CMS', 'expected' => ['articles' => 17, 'career_guides' => 20]],
        'W4' => ['name' => 'RIASEC', 'expected' => ['logical_groups' => 14, 'atomic_rows' => 1550]],
        'W5' => ['name' => 'Enneagram', 'expected' => ['public_control' => 58, 'private_result_payloads' => 630]],
        'W6' => ['name' => 'IQ', 'expected' => [], 'operator_disposition' => 'deferred'],
        'W7' => ['name' => 'EQ', 'expected' => ['result_report_share' => 'package_defined']],
        'W8' => ['name' => 'Career Job', 'expected' => ['bilingual_entities' => 1046]],
        'W9' => ['name' => 'Independent QA', 'expected' => [], 'lane_kind' => 'qa_capability'],
    ];

    /**
     * @param  array{site_base:string,api_base:string,fap_web_root:string,fap_api_root:string,since:string,concurrency:int,timeout:int,max_urls:int}  $options
     * @return array<string, mixed>
     */
    public function scan(array $options): array
    {
        $this->assertOptions($options);
        $repositories = [
            'fap-web' => $this->scanRepository('fap-web', $options['fap_web_root'], $options['since']),
            'fap-api' => $this->scanRepository('fap-api', $options['fap_api_root'], $options['since']),
        ];
        $tasks = $this->deduplicateTasks(array_merge($repositories['fap-web']['tasks'], $repositories['fap-api']['tasks']));
        $control = $this->scanControlEvidence($options['fap_web_root'], $repositories['fap-web']['commit']);
        $live = $this->scanLive($options);
        $lanes = $this->buildLanes($tasks, $control);

        $dispositions = [];
        foreach ($tasks as $task) {
            $key = (string) $task['disposition'];
            $dispositions[$key] = ($dispositions[$key] ?? 0) + 1;
        }
        ksort($dispositions, SORT_STRING);

        return [
            '$schema' => '../../schemas/english-content-parity-program-ledger.v1.schema.json',
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'scan_window' => ['since' => $options['since'], 'until' => gmdate('c')],
            'source_repositories' => [
                'fap-web' => ['path' => $options['fap_web_root'], 'commit' => $repositories['fap-web']['commit']],
                'fap-api' => ['path' => $options['fap_api_root'], 'commit' => $repositories['fap-api']['commit']],
            ],
            'method' => [
                'task_identity' => 'repository + pull_request_number + task_id; commit SHA is fallback',
                'program_start' => '2026-07-30',
                'pre_program_assets' => 'preexisting_baseline',
                'http_methods' => ['GET', 'HEAD'],
                'allowed_hosts' => self::ALLOWED_HOSTS,
                'body_limit_bytes' => self::BODY_LIMIT,
                'private_production_data_read' => false,
            ],
            'summary' => [
                'candidate_commit_count' => $repositories['fap-web']['candidate_commit_count'] + $repositories['fap-api']['candidate_commit_count'],
                'deduplicated_task_count' => count($tasks),
                'explicit_pull_request_count' => count(array_unique(array_values(array_filter(array_map(static fn (array $task): ?string => $task['pull_request_number'], $tasks))))),
                'task_dispositions' => $dispositions,
                'sitemap_url_count' => $live['sitemap']['url_count'],
                'live_finding_count' => count($live['findings']),
                'control_drift_count' => count($control['drift']),
            ],
            'lanes' => $lanes,
            'tasks' => $tasks,
            'control_drift' => $control['drift'],
            'control_evidence' => $control['evidence'],
            'live_scan' => $live,
            'superseded_workflows' => [
                'launch_ready_status_control_pr',
                'status_acceptance_control_pr',
                'standalone_w9_evidence_pr',
                'blocked_reset_refreeze_pr_chain',
                'human_approval_artifact',
            ],
            'evidence_precedence' => [
                'exact_package_and_successful_promotion_live_qa_receipt',
                'current_backend_authority_and_public_api',
                'current_public_page_readback',
                'lane_manifest_and_v2_inputs',
                'generated_v2_master',
                'v1_master_historical_approvals_and_pr_history',
            ],
            'followups' => $this->followups($lanes, $control['drift'], $live['findings']),
            'negative_guarantees' => [
                'cms_write' => false,
                'publication' => false,
                'database_write' => false,
                'seo_discoverability_mutation' => false,
                'search_submission' => false,
                'deployment' => false,
                'authenticated_or_private_read' => false,
            ],
        ];
    }

    /** @param array<string, mixed> $options */
    private function assertOptions(array $options): void
    {
        foreach (['site_base', 'api_base'] as $key) {
            $host = strtolower((string) parse_url((string) ($options[$key] ?? ''), PHP_URL_HOST));
            if (! in_array($host, self::ALLOWED_HOSTS, true) || parse_url((string) $options[$key], PHP_URL_SCHEME) !== 'https') {
                throw new RuntimeException('host_not_allowlisted:'.$key);
            }
        }
        foreach (['fap_web_root', 'fap_api_root'] as $key) {
            if (! is_dir((string) ($options[$key] ?? '').'/.git') && ! is_file((string) ($options[$key] ?? '').'/.git')) {
                throw new RuntimeException('repository_not_found:'.$key);
            }
        }
        if ($options['concurrency'] < 1 || $options['concurrency'] > 8 || $options['timeout'] < 1 || $options['timeout'] > 60 || $options['max_urls'] < 1 || $options['max_urls'] > 5000) {
            throw new RuntimeException('unsafe_scan_bounds');
        }
    }

    /** @return array{commit:string,candidate_commit_count:int,tasks:list<array<string,mixed>>} */
    private function scanRepository(string $repository, string $root, string $since): array
    {
        $commit = $this->git($root, ['rev-parse', 'HEAD']);
        $log = $this->git($root, ['log', '--since='.$since, '--pretty=format:%H%x09%aI%x09%s', 'origin/main']);
        $tasks = [];
        foreach (preg_split('/\R/', trim($log)) ?: [] as $line) {
            $parts = explode("\t", $line, 3);
            if (count($parts) !== 3 || ! $this->isProgramSubject($parts[2])) {
                continue;
            }
            $tasks[] = $this->taskFromCommit($repository, $parts[0], $parts[1], $parts[2]);
        }

        return ['commit' => $commit, 'candidate_commit_count' => count($tasks), 'tasks' => $tasks];
    }

    /** @param list<string> $arguments */
    private function git(string $root, array $arguments): string
    {
        $process = new Process(array_merge(['git', '-C', $root], $arguments));
        $process->setTimeout(30);
        $process->mustRun();

        return trim($process->getOutput());
    }

    /** @param list<string> $arguments */
    private function gitBytes(string $root, array $arguments): string
    {
        $process = new Process(array_merge(['git', '-C', $root], $arguments));
        $process->setTimeout(30);
        $process->mustRun();

        return $process->getOutput();
    }

    private function isProgramSubject(string $subject): bool
    {
        return preg_match('/(?:EN[- ]PARITY|W[1-9](?:\b|-)|W9|English (?:content|parity)|content[- ]promotion|promotion (?:adapter|receipt|closeout)|RIASEC.*(?:English|parity)|Career.*(?:English|parity)|Big Five.*(?:English|parity)|Enneagram.*(?:English|parity))/iu', $subject) === 1;
    }

    /** @return array<string, mixed> */
    private function taskFromCommit(string $repository, string $commit, string $mergedAt, string $subject): array
    {
        preg_match('/#(\d+)/', $subject, $prMatch);
        preg_match('/\b((?:EN-PARITY|CONTENT-PROMOTION|SOLO-OWNER)[A-Z0-9-]+)\b/i', $subject, $idMatch);
        $taskId = isset($idMatch[1]) ? strtoupper($idMatch[1]) : null;

        return [
            'repository' => $repository,
            'commit' => $commit,
            'pull_request_number' => $prMatch[1] ?? null,
            'task_id' => $taskId,
            'title' => $subject,
            'lane' => $this->classifyLane($subject),
            'category' => $this->classifyCategory($subject),
            'phase' => 'merged',
            'disposition' => $this->classifyDisposition($subject, $mergedAt),
            'merged_at' => $mergedAt,
            'evidence_refs' => [$repository.'@'.$commit],
        ];
    }

    private function classifyLane(string $subject): string
    {
        if (preg_match('/\bW([1-9])(?:\b|-)/i', $subject, $match) === 1) {
            return 'W'.$match[1];
        }
        $map = ['MBTI' => 'W1', 'BIG FIVE' => 'W2', 'ARTICLE' => 'W3', 'CAREER GUIDE' => 'W3', 'RIASEC' => 'W4', 'ENNEAGRAM' => 'W5', 'IQ' => 'W6', 'EQ' => 'W7', 'CAREER JOB' => 'W8'];
        $upper = strtoupper($subject);
        foreach ($map as $needle => $lane) {
            if (str_contains($upper, $needle)) {
                return $lane;
            }
        }

        return 'PROGRAM';
    }

    private function classifyCategory(string $subject): string
    {
        $upper = strtoupper($subject);

        return match (true) {
            str_contains($upper, 'INVENTOR'), str_contains($upper, 'SCAN') => 'inventory_scan',
            str_contains($upper, 'W9'), str_contains($upper, 'QA') => 'qa_w9',
            str_contains($upper, 'CONTROL') => 'control_v1',
            str_contains($upper, 'RESET'), str_contains($upper, 'REWORK'), str_contains($upper, 'REPAIR') => 'repair_rework',
            str_contains($upper, 'RECEIPT'), str_contains($upper, 'CLOSEOUT') => 'receipt_materialization',
            str_contains($upper, 'PROMOTION'), str_contains($upper, 'ADAPTER') => 'promotion_infra',
            str_contains($upper, 'PUBLISH'), str_contains($upper, 'IMPORT') => 'promotion_execution',
            str_contains($upper, 'FRONTEND'), str_contains($upper, 'CONSUMER'), str_contains($upper, 'RENDER') => 'frontend_consumer',
            str_contains($upper, 'SITEMAP'), str_contains($upper, 'LLMS'), str_contains($upper, 'SEO') => 'seo_discoverability',
            str_contains($upper, 'RULE'), str_contains($upper, 'DOC'), str_contains($upper, 'SOLO-OWNER') => 'docs_rules',
            str_contains($upper, 'ASSET'), str_contains($upper, 'PACKAGE') => 'producer_asset',
            default => 'unclassified',
        };
    }

    private function classifyDisposition(string $subject, string $mergedAt): string
    {
        if (strtotime($mergedAt) < strtotime('2026-07-30T00:00:00+08:00')) {
            return 'preexisting_baseline';
        }
        $upper = strtoupper($subject);

        return match (true) {
            str_contains($upper, 'RESET'), str_contains($upper, 'REWORK'), str_contains($upper, 'REPAIR') => 'repair_iteration',
            str_contains($upper, 'V1'), str_contains($upper, 'APPROVAL') => 'superseded_v1',
            default => 'effective_current',
        };
    }

    /**
     * @param  list<array<string,mixed>>  $tasks
     * @return list<array<string,mixed>>
     */
    private function deduplicateTasks(array $tasks): array
    {
        $unique = [];
        foreach ($tasks as $task) {
            $identity = $task['repository'].'|'.($task['pull_request_number'] ?? $task['task_id'] ?? $task['commit']);
            if (! isset($unique[$identity])) {
                $unique[$identity] = $task;

                continue;
            }
            $unique[$identity]['evidence_refs'] = array_values(array_unique(array_merge($unique[$identity]['evidence_refs'], $task['evidence_refs'])));
        }
        $tasks = array_values($unique);
        usort($tasks, static fn (array $a, array $b): int => [$a['merged_at'], $a['repository'], $a['commit']] <=> [$b['merged_at'], $b['repository'], $b['commit']]);

        return $tasks;
    }

    /** @return array{evidence:array<string,mixed>,drift:list<array<string,mixed>>} */
    private function scanControlEvidence(string $webRoot, string $webCommit): array
    {
        $masterPath = 'docs/seo/generated/en-content-parity-control-master.v2.json';
        $inputsPath = 'docs/seo/generated/en-content-parity-control-inputs.v2.json';
        [$master, $masterBytes] = $this->readJsonAtCommit($webRoot, $webCommit, $masterPath);
        [$inputs, $inputsBytes] = $this->readJsonAtCommit($webRoot, $webCommit, $inputsPath);
        $masterLanes = [];
        foreach ((array) ($master['lanes'] ?? []) as $lane) {
            if (is_array($lane) && isset($lane['lane_id'])) {
                $masterLanes[(string) $lane['lane_id']] = ['status' => $lane['status'] ?? null, 'subscopes' => $lane['subscopes'] ?? []];
            }
        }

        $laneManifests = [];
        foreach ($this->recursiveFilesAtCommit($webRoot, $webCommit, 'generated/en-content-parity', 'lane_manifest.json') as $path) {
            [$payload] = $this->readJsonAtCommit($webRoot, $webCommit, $path);
            $lane = (string) ($payload['lane_id'] ?? $payload['lane'] ?? '');
            if ($lane !== '') {
                $laneManifests[] = ['lane' => $lane, 'status' => $payload['status'] ?? null, 'package_sha256' => $payload['package_sha256'] ?? null, 'path' => $path];
            }
        }

        $drift = [];
        foreach ($laneManifests as $manifest) {
            $masterLane = preg_match('/^(W\d)/', $manifest['lane'], $match) === 1 ? $match[1] : $manifest['lane'];
            $masterStatus = $masterLanes[$masterLane]['status'] ?? null;
            if ($this->stateRank((string) $manifest['status']) > $this->stateRank((string) $masterStatus)) {
                $drift[] = ['type' => 'lane_manifest_ahead_of_master', 'lane' => $masterLane, 'manifest_status' => $manifest['status'], 'master_status' => $masterStatus, 'evidence_ref' => $manifest['path']];
            }
        }

        $registeredManifests = (array) ($inputs['lane_manifests'] ?? []);
        $receiptChains = (array) ($inputs['receipt_chains'] ?? []);
        $inputRows = array_merge($registeredManifests, $receiptChains);
        $seen = [];
        foreach ($receiptChains as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $copy = $row;
            unset($copy['registered_at'], $copy['accepted_at']);
            $hash = hash('sha256', $this->canonicalJson($copy));
            if (isset($seen[$hash])) {
                $drift[] = ['type' => 'duplicate_v2_input', 'lane' => $row['lane_id'] ?? $row['lane'] ?? 'unknown', 'first_index' => $seen[$hash], 'duplicate_index' => $index];
            } else {
                $seen[$hash] = $index;
            }
        }

        $registeredPaths = array_fill_keys(array_values(array_filter(array_map(static fn (array $row): ?string => isset($row['path']) ? (string) $row['path'] : null, $registeredManifests))), true);
        foreach ($laneManifests as $manifest) {
            if (! isset($registeredPaths[$manifest['path']])) {
                $drift[] = ['type' => 'lane_manifest_not_registered_in_v2_inputs', 'lane' => $manifest['lane'], 'evidence_ref' => $manifest['path']];
            }
        }

        foreach ($receiptChains as $chain) {
            if (! is_array($chain) || ! isset($chain['lane_id'], $chain['target_status'])) {
                continue;
            }
            $laneId = (string) $chain['lane_id'];
            $masterStatus = $masterLanes[$laneId]['status'] ?? null;
            $subscope = $chain['subscope'] ?? null;
            if (is_string($subscope) && $subscope !== '') {
                foreach ((array) ($masterLanes[$laneId]['subscopes'] ?? []) as $masterSubscope) {
                    if (($masterSubscope['id'] ?? null) === $subscope) {
                        $masterStatus = $masterSubscope['status'] ?? $masterStatus;
                        break;
                    }
                }
            }
            if ($this->stateRank((string) $chain['target_status']) > $this->stateRank((string) $masterStatus)) {
                $drift[] = ['type' => 'receipt_chain_ahead_of_materialized_master', 'lane' => $laneId, 'subscope' => $subscope, 'receipt_status' => $chain['target_status'], 'master_status' => $masterStatus, 'package_sha256' => $chain['package_sha256'] ?? null];
            }
        }

        return [
            'evidence' => [
                'master' => ['path' => $masterPath, 'sha256' => hash('sha256', $masterBytes), 'lanes' => $masterLanes],
                'inputs' => ['path' => $inputsPath, 'sha256' => hash('sha256', $inputsBytes), 'entry_count' => count($inputRows), 'lane_manifest_count' => count($registeredManifests), 'receipt_chain_count' => count($receiptChains)],
                'lane_manifests' => $laneManifests,
            ],
            'drift' => $drift,
        ];
    }

    /** @return array<string,mixed> */
    private function scanLive(array $options): array
    {
        $siteBase = rtrim($options['site_base'], '/');
        $apiBase = rtrim($options['api_base'], '/');
        $sitemap = $this->sitemapUrls($siteBase.'/sitemap.xml', $options['timeout'], $options['max_urls']);
        $sitemapUrls = $sitemap['urls'];
        $documents = array_values(array_unique(array_merge($sitemapUrls, [$siteBase.'/llms.txt', $siteBase.'/llms-full.txt'])));
        $pageResults = $this->fetchBatch($documents, $options['concurrency'], $options['timeout'], $options['max_urls']);

        $apiEndpoints = [];
        foreach (['en', 'zh-CN'] as $locale) {
            foreach (['articles', 'career-guides', 'career-jobs', 'personality-content-assets', 'personality', 'topics'] as $surface) {
                $apiEndpoints[] = $apiBase.'/api/v0.5/'.$surface.'?locale='.rawurlencode($locale).'&per_page=20';
            }
            $apiEndpoints[] = $apiBase.'/api/v0.3/scales?locale='.rawurlencode($locale);
            $apiEndpoints[] = $apiBase.'/api/v0.5/seo/sitemap-source?locale='.rawurlencode($locale);
        }
        $apiResults = $this->fetchBatch($apiEndpoints, $options['concurrency'], $options['timeout'], $options['max_urls']);

        $findings = $sitemap['findings'];
        $canonicalOwners = [];
        foreach ($pageResults as $result) {
            foreach ($result['findings'] as $finding) {
                $findings[] = ['surface' => 'page', 'url_hash' => $result['url_hash'], 'code' => $finding];
            }
            if (($result['canonical'] ?? null) !== null) {
                $canonicalOwners[$result['canonical']][] = $result['url_hash'];
            }
        }
        foreach ($canonicalOwners as $canonical => $owners) {
            if (count($owners) > 1) {
                $findings[] = ['surface' => 'page', 'code' => 'duplicate_canonical', 'canonical_hash' => hash('sha256', $canonical), 'owner_count' => count($owners)];
            }
        }
        foreach ($apiResults as $result) {
            foreach ($result['findings'] as $finding) {
                $findings[] = ['surface' => 'api', 'url_hash' => $result['url_hash'], 'code' => $finding];
            }
        }

        return [
            'sitemap' => ['source' => $siteBase.'/sitemap.xml', 'url_count' => count($sitemapUrls), 'url_set_sha256' => hash('sha256', implode("\n", $sitemapUrls))],
            'documents' => $pageResults,
            'public_api' => $apiResults,
            'findings' => $findings,
            'private_surfaces' => ['mode' => 'package_w9_receipt_and_contract_evidence_only', 'production_private_records_read' => false],
        ];
    }

    /** @return array{urls:list<string>,findings:list<array<string,mixed>>} */
    private function sitemapUrls(string $initialUrl, int $timeout, int $maxUrls): array
    {
        $pending = [$initialUrl];
        $seenMaps = [];
        $urls = [];
        $findings = [];
        while ($pending !== [] && count($urls) < $maxUrls) {
            $url = array_shift($pending);
            if (isset($seenMaps[$url])) {
                continue;
            }
            $seenMaps[$url] = true;
            $response = $this->request($url, $timeout);
            if (! $response->successful() || strlen($response->body()) > self::BODY_LIMIT) {
                $response->close();

                if ($url === $initialUrl) {
                    throw new RuntimeException('root_sitemap_unavailable');
                }

                continue;
            }
            $xml = @simplexml_load_string($response->body(), options: LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            if ($xml === false) {
                $response->close();
                if ($url === $initialUrl) {
                    throw new RuntimeException('root_sitemap_malformed');
                }

                continue;
            }
            preg_match_all('/<loc>\s*([^<]+)\s*<\/loc>/i', $response->body(), $matches);
            if ($url === $initialUrl && ($matches[1] ?? []) === []) {
                $response->close();
                throw new RuntimeException('root_sitemap_empty');
            }
            foreach ($matches[1] ?? [] as $location) {
                $location = html_entity_decode(trim($location), ENT_QUOTES | ENT_XML1, 'UTF-8');
                if (! $this->allowedUrl($location)) {
                    continue;
                }
                if ($this->isPrivateUrl($location)) {
                    $findings[] = ['surface' => 'sitemap', 'url_hash' => hash('sha256', $location), 'code' => 'private_path_in_public_inventory_not_fetched'];

                    continue;
                }
                if (str_ends_with((string) parse_url($location, PHP_URL_PATH), '.xml')) {
                    $pending[] = $location;
                } else {
                    $urls[$location] = true;
                }
                if (count($urls) >= $maxUrls) {
                    break 2;
                }
            }
            $response->close();
        }
        $urls = array_keys($urls);
        sort($urls, SORT_STRING);

        return ['urls' => $urls, 'findings' => $findings];
    }

    /** @return list<array<string,mixed>> */
    private function fetchBatch(array $urls, int $concurrency, int $timeout, int $maxUrls): array
    {
        $urls = array_slice(array_values(array_unique(array_filter($urls, fn (string $url): bool => $this->allowedUrl($url)))), 0, $maxUrls);
        $results = [];
        foreach (array_chunk($urls, $concurrency) as $chunk) {
            $responses = Http::pool(function (Pool $pool) use ($chunk, $timeout): array {
                $requests = [];
                foreach ($chunk as $url) {
                    $requests[] = $pool->as(hash('sha256', $url))->withOptions($this->boundedHttpOptions())->timeout($timeout)->accept('*/*')->get($url);
                }

                return $requests;
            });
            foreach ($chunk as $url) {
                $key = hash('sha256', $url);
                $response = $responses[$key] ?? null;
                if ($response instanceof Response && ($response->status() === 429 || $response->serverError())) {
                    $response->close();
                    try {
                        $response = $this->request($url, $timeout, false);
                    } catch (\Throwable $exception) {
                        $response = null;
                    }
                }
                if ($response instanceof Response) {
                    $results[] = $this->summarizeResponse($url, $response);
                    $response->close();
                } else {
                    $results[] = $this->failedResponse($url, $this->isBodyLimitFailure($response) ? 'response_body_over_limit' : 'transport_failure');
                }
            }
            unset($responses);
            gc_collect_cycles();
            usleep(100_000);
        }

        return $results;
    }

    private function request(string $url, int $timeout, bool $allowRetry = true): Response
    {
        $response = Http::withOptions($this->boundedHttpOptions())->timeout($timeout)->accept('*/*')->get($url);
        if ($allowRetry && ($response->status() === 429 || $response->serverError())) {
            $retryAfter = min(2, max(0, (int) $response->header('Retry-After')));
            $response->close();
            if ($retryAfter > 0) {
                sleep($retryAfter);
            }
            $response = Http::withOptions($this->boundedHttpOptions())->timeout($timeout)->accept('*/*')->get($url);
        }

        return $response;
    }

    /** @return array<string,mixed> */
    private function summarizeResponse(string $url, Response $response): array
    {
        $body = $response->body();
        $contentType = strtolower($response->header('Content-Type'));
        $findings = [];
        if (strlen($body) > self::BODY_LIMIT) {
            $body = '';
            $findings[] = 'response_body_over_limit';
        }
        if ($response->status() >= 300 && $response->status() < 400) {
            $location = $response->header('Location');
            if ($location !== '' && ! $this->allowedUrl($location)) {
                $findings[] = 'external_redirect_blocked';
            } else {
                $findings[] = 'redirect_not_followed';
            }
        } elseif (! $response->successful()) {
            $findings[] = 'http_status_'.$response->status();
        }

        $canonical = null;
        $htmlLang = null;
        $hreflangCount = 0;
        $identityCount = null;
        if ($body !== '' && str_contains($contentType, 'html')) {
            [$canonical, $htmlLang, $hreflangCount, $visibleText, $robotsNoindex] = $this->parseHtml($body);
            if ($canonical === null) {
                $findings[] = 'canonical_missing';
            }
            if ($htmlLang === null || $htmlLang === '') {
                $findings[] = 'html_lang_missing';
            }
            if ($hreflangCount === 0) {
                $findings[] = 'hreflang_missing';
            }
            if (mb_strlen(trim($visibleText)) < 80) {
                $findings[] = 'visible_content_too_short';
            }
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (str_starts_with($path, '/en') && $this->cjkRatio($visibleText) > 0.02) {
                $findings[] = 'english_visible_text_cjk_ratio_high';
            }
            if ($robotsNoindex && $this->isPublicContentPath($path)) {
                $findings[] = 'public_sitemap_page_noindex';
            }
        } elseif ($body !== '' && (str_contains($contentType, 'json') || str_starts_with(ltrim($body), '{') || str_starts_with(ltrim($body), '['))) {
            $json = json_decode($body, true);
            if (! is_array($json)) {
                $findings[] = 'malformed_json';
            } else {
                $items = $json['items'] ?? $json['data'] ?? null;
                $identityCount = is_array($items) ? count($items) : null;
                if (($json['ok'] ?? true) !== true) {
                    $findings[] = 'api_ok_false';
                }
            }
        }
        foreach (self::PRIVATE_PATH_MARKERS as $marker) {
            if (str_contains(strtolower($url), $marker)) {
                $findings[] = 'private_path_in_public_inventory';
            }
        }

        return [
            'url_hash' => hash('sha256', $url),
            'path' => (string) parse_url($url, PHP_URL_PATH),
            'query_keys' => $this->queryKeys($url),
            'status' => $response->status(),
            'content_type' => strtok($contentType, ';') ?: $contentType,
            'body_size' => strlen($response->body()),
            'body_sha256' => $body === '' ? null : hash('sha256', $body),
            'canonical' => $canonical,
            'html_lang' => $htmlLang,
            'hreflang_count' => $hreflangCount,
            'identity_count' => $identityCount,
            'findings' => array_values(array_unique($findings)),
        ];
    }

    /** @return array{0:?string,1:?string,2:int,3:string,4:bool} */
    private function parseHtml(string $html): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        $canonicalNode = $xpath->query('//link[contains(concat(" ", translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), " "), " canonical ")]')->item(0);
        $htmlNode = $xpath->query('/html')->item(0);
        $hreflangCount = $xpath->query('//link[@hreflang]')->length;
        $robotsNoindex = false;
        foreach ($xpath->query('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="robots"]') as $node) {
            if (str_contains(strtolower((string) $node->attributes?->getNamedItem('content')?->nodeValue), 'noindex')) {
                $robotsNoindex = true;
                break;
            }
        }
        foreach ($xpath->query('//script|//style|//svg|//noscript') as $node) {
            $node->parentNode?->removeChild($node);
        }
        $visible = preg_replace('/\s+/u', ' ', (string) $document->textContent) ?? '';
        $visible = str_replace(['中文', '简体中文', '繁體中文'], '', $visible);

        return [
            $canonicalNode?->attributes?->getNamedItem('href')?->nodeValue,
            $htmlNode?->attributes?->getNamedItem('lang')?->nodeValue,
            $hreflangCount,
            trim($visible),
            $robotsNoindex,
        ];
    }

    private function cjkRatio(string $value): float
    {
        $length = max(1, mb_strlen($value));
        preg_match_all('/[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $value, $matches);

        return count($matches[0] ?? []) / $length;
    }

    private function isPublicContentPath(string $path): bool
    {
        return ! preg_match('#/(?:attempt|order|payment|report|result)(?:/|$)#i', $path);
    }

    /** @return list<string> */
    private function queryKeys(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $keys = array_keys($query);
        sort($keys, SORT_STRING);

        return $keys;
    }

    /** @return array<string,mixed> */
    private function failedResponse(string $url, string $finding): array
    {
        return ['url_hash' => hash('sha256', $url), 'path' => (string) parse_url($url, PHP_URL_PATH), 'query_keys' => $this->queryKeys($url), 'status' => 0, 'content_type' => null, 'body_size' => 0, 'body_sha256' => null, 'canonical' => null, 'html_lang' => null, 'hreflang_count' => 0, 'identity_count' => null, 'findings' => [$finding]];
    }

    private function allowedUrl(string $url): bool
    {
        return parse_url($url, PHP_URL_SCHEME) === 'https' && in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), self::ALLOWED_HOSTS, true);
    }

    private function isPrivateUrl(string $url): bool
    {
        $normalized = strtolower($url);
        foreach (self::PRIVATE_PATH_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    private function boundedHttpOptions(): array
    {
        return [
            'allow_redirects' => false,
            'sink' => fopen('php://temp/maxmemory:1048576', 'w+'),
            'on_headers' => static function (ResponseInterface $response): void {
                $length = (int) $response->getHeaderLine('Content-Length');
                if ($length > self::BODY_LIMIT) {
                    throw new RuntimeException('response_body_over_limit');
                }
            },
            'progress' => static function (int $downloadTotal, int $downloadedBytes): void {
                if ($downloadTotal > self::BODY_LIMIT || $downloadedBytes > self::BODY_LIMIT) {
                    throw new RuntimeException('response_body_over_limit');
                }
            },
        ];
    }

    private function isBodyLimitFailure(mixed $response): bool
    {
        return $response instanceof \Throwable && str_contains($response->getMessage(), 'response_body_over_limit');
    }

    /** @param list<array<string,mixed>> $tasks @param array<string,mixed> $control @return list<array<string,mixed>> */
    private function buildLanes(array $tasks, array $control): array
    {
        $masterLanes = $control['evidence']['master']['lanes'] ?? [];
        $manifests = $control['evidence']['lane_manifests'] ?? [];
        $lanes = [];
        foreach (self::LANE_BASELINE as $laneId => $baseline) {
            $laneTasks = array_values(array_filter($tasks, static fn (array $task): bool => $task['lane'] === $laneId));
            $manifestStatuses = array_values(array_filter($manifests, static fn (array $manifest): bool => str_starts_with((string) $manifest['lane'], $laneId)));
            $masterStatus = $masterLanes[$laneId]['status'] ?? 'not_started';
            $bestStatus = $this->aggregateLaneStatus($laneId, (string) $masterStatus, $manifestStatuses, (array) ($masterLanes[$laneId]['subscopes'] ?? []));
            if ($laneId === 'W6') {
                $bestStatus = 'deferred';
            }
            $lanes[] = [
                'lane_id' => $laneId,
                'name' => $baseline['name'],
                'lane_kind' => $baseline['lane_kind'] ?? 'producer',
                'initial_scope' => $baseline['expected'],
                'operator_disposition' => $baseline['operator_disposition'] ?? 'active',
                'task_count' => count($laneTasks),
                'task_ids' => array_values(array_filter(array_map(static fn (array $task): ?string => $task['task_id'], $laneTasks))),
                'repository_state' => $bestStatus,
                'control_state' => $masterStatus,
                'asset_ready' => $this->stateRank((string) $bestStatus) >= $this->stateRank('package_frozen'),
                'qa_ready' => $this->stateRank((string) $bestStatus) >= $this->stateRank('qa_pass'),
                'promotion_ready' => $this->stateRank((string) $bestStatus) >= $this->stateRank('dry_run_ready'),
                'live_verified' => $this->stateRank((string) $bestStatus) >= $this->stateRank('live_qa_pass') || $bestStatus === 'deployed_verified',
                'control_synced' => $bestStatus === $masterStatus || $laneId === 'W6',
                'package_evidence' => $manifestStatuses,
                'remaining_work' => $this->remainingWork($laneId, (string) $bestStatus, (string) $masterStatus),
                'confidence' => $manifestStatuses !== [] ? 'high' : ($laneTasks !== [] ? 'medium' : 'low'),
            ];
        }

        return $lanes;
    }

    /** @return list<string> */
    private function remainingWork(string $laneId, string $bestStatus, string $masterStatus): array
    {
        if ($laneId === 'W6') {
            return ['operator_deferred_no_action'];
        }
        if ($laneId === 'W9') {
            return ['continue_as_same_pr_required_qa_capability'];
        }
        $remaining = [];
        if ($this->stateRank($bestStatus) < $this->stateRank('live_qa_pass') && $bestStatus !== 'deployed_verified') {
            $remaining[] = 'complete_v2_package_qa_promotion_live_qa';
        }
        if ($bestStatus !== $masterStatus) {
            $remaining[] = 'repair_control_materialization_input_registration';
        }

        return $remaining;
    }

    private function stateRank(string $status): int
    {
        $states = ['not_started', 'inventory_frozen', 'package_in_progress', 'package_frozen', 'qa_pass', 'dry_run_ready', 'draft_imported', 'published', 'live_qa_pass', 'deployed_verified'];
        $rank = array_search($status, $states, true);

        return $rank === false ? -1 : $rank;
    }

    /** @param list<array<string,mixed>> $manifests @param list<array<string,mixed>> $masterSubscopes */
    private function aggregateLaneStatus(string $laneId, string $masterStatus, array $manifests, array $masterSubscopes): string
    {
        if ($laneId !== 'W3') {
            $status = $masterStatus;
            foreach ($manifests as $manifest) {
                if ($this->stateRank((string) $manifest['status']) > $this->stateRank($status)) {
                    $status = (string) $manifest['status'];
                }
            }

            return $status;
        }

        $required = ['articles' => null, 'career_guides' => null];
        foreach ($masterSubscopes as $subscope) {
            $id = strtolower((string) ($subscope['id'] ?? ''));
            $key = str_contains($id, 'article') ? 'articles' : (str_contains($id, 'career') ? 'career_guides' : null);
            if ($key !== null) {
                $required[$key] = (string) ($subscope['status'] ?? $masterStatus);
            }
        }
        foreach ($manifests as $manifest) {
            $id = strtolower((string) ($manifest['lane'] ?? ''));
            $key = str_contains($id, 'article') ? 'articles' : (str_contains($id, 'career') ? 'career_guides' : null);
            if ($key !== null && ($required[$key] === null || $this->stateRank((string) $manifest['status']) > $this->stateRank((string) $required[$key]))) {
                $required[$key] = (string) $manifest['status'];
            }
        }

        $statuses = array_map(static fn (?string $status): string => $status ?? $masterStatus, $required);
        usort($statuses, fn (string $a, string $b): int => $this->stateRank($a) <=> $this->stateRank($b));

        return $statuses[0];
    }

    /** @return list<array<string,mixed>> */
    private function followups(array $lanes, array $drift, array $findings): array
    {
        $followups = [];
        if ($drift !== []) {
            $followups[] = ['priority' => 1, 'type' => 'control_materialization', 'action' => 'register exact lane manifests and deduplicate V2 inputs; regenerate read-only master', 'count' => count($drift)];
        }
        foreach ($lanes as $lane) {
            if ($lane['lane_id'] !== 'W6' && $lane['lane_id'] !== 'W9' && $lane['remaining_work'] !== []) {
                $followups[] = ['priority' => 2, 'type' => 'lane_closeout', 'lane' => $lane['lane_id'], 'actions' => $lane['remaining_work']];
            }
        }
        if ($findings !== []) {
            $followups[] = ['priority' => 3, 'type' => 'live_parity_findings', 'action' => 'triage sanitized read-only findings by authority owner', 'count' => count($findings)];
        }

        return $followups;
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function readJsonAtCommit(string $root, string $commit, string $path): array
    {
        $bytes = $this->gitBytes($root, ['show', $commit.':'.$path]);
        try {
            $decoded = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('invalid_control_json:'.$path, previous: $exception);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('invalid_control_json_shape:'.$path);
        }

        return [$decoded, $bytes];
    }

    /** @return list<string> */
    private function recursiveFilesAtCommit(string $root, string $commit, string $prefix, string $filename): array
    {
        $files = array_values(array_filter(
            preg_split('/\R/', $this->git($root, ['ls-tree', '-r', '--name-only', $commit, '--', $prefix])) ?: [],
            static fn (string $path): bool => basename($path) === $filename,
        ));
        sort($files, SORT_STRING);

        return $files;
    }

    private function canonicalJson(array $value): string
    {
        $sort = function (&$item) use (&$sort): void {
            if (! is_array($item)) {
                return;
            }
            if (! array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as &$child) {
                $sort($child);
            }
        };
        $sort($value);

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
