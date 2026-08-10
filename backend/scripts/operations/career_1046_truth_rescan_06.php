<?php

declare(strict_types=1);

namespace FermatMind\Operations;

use RuntimeException;
use Throwable;

final class Career1046TruthRescan06
{
    public const CONTRACT_VERSION = 'career.1046.truth_rescan_06.v1';

    private const C03_CONTRACT = 'career.c03.cache_only_discoverability_recovery.v1';

    private const PASS_STATUS = 'PASS_C03_REVERIFIED_NO_APPLY_REQUIRED';

    private const API_BASE = 'https://api.fermatmind.com';

    private const WEB_BASE = 'https://fermatmind.com';

    private const MAX_CONCURRENCY = 2;

    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const REQUEST_TIMEOUT_SECONDS = 20;

    private const ROUND_DELAY_SECONDS = 30;

    private const TARGET_UNIQUE_SLUGS = 1046;

    private const TARGET_LOCALE_ROWS = 2092;

    private const PRIVATE_PATH_PATTERN = '#^(?:(?:/(?:en|zh))?/(?:attempts?|results?|reports?|orders?|share|pay|payment|history)(?:/|$)|/(?:en|zh)/tests/[^/]+/take(?:/|$))#iD';

    /** @var list<string> */
    private const ZERO_COUNT_FIELDS = [
        'cache_write_count',
        'database_write_count',
        'publication_write_count',
        'indexability_write_count',
        'deploy_count',
        'migration_count',
        'symlink_write_count',
        'process_restart_count',
        'queue_reload_count',
        'sitemap_submission_count',
        'llms_submission_count',
        'search_submission_count',
    ];

    public static function main(array $argv): int
    {
        $mode = trim((string) ($argv[1] ?? ''));

        try {
            $options = self::parseOptions(array_slice($argv, 2));

            return match ($mode) {
                'scan' => self::runScan($options),
                'finalize' => self::runFinalize($options),
                'validate' => self::runValidate($options),
                default => throw new RuntimeException('MODE_INVALID'),
            };
        } catch (Throwable $exception) {
            fwrite(STDERR, self::safeError($exception->getMessage()).PHP_EOL);

            return 1;
        }
    }

    /** @param array<string, string> $options */
    private static function runScan(array $options): int
    {
        $receiptPath = self::requiredOption($options, 'pre-c03-receipt');
        $artifactDigest = self::artifactDigest(self::requiredOption($options, 'pre-artifact-digest'));
        $outputPath = self::requiredOption($options, 'output');
        $receipt = self::jsonFile($receiptPath);
        self::validateC03Receipt($receipt);

        $scan = [
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => 'scan',
            'status' => 'NO_GO_INCOMPLETE',
            'started_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'completed_at' => null,
            'pre_c03_receipt_sha256' => self::fileSha256($receiptPath),
            'pre_c03_artifact_digest' => $artifactDigest,
            'active_revision' => $receipt['active_revision'],
            'authority_artifact_sha256' => $receipt['authority_artifact_sha256'],
            'published_cohort' => $receipt['published_cohort'],
            'http_policy' => [
                'method' => 'GET',
                'max_concurrency' => self::MAX_CONCURRENCY,
                'connect_timeout_seconds' => self::CONNECT_TIMEOUT_SECONDS,
                'request_timeout_seconds' => self::REQUEST_TIMEOUT_SECONDS,
                'follow_redirects' => false,
                'round_count' => 2,
                'round_delay_seconds' => self::ROUND_DELAY_SECONDS,
            ],
            'rounds' => [],
            'aborted' => false,
            'safe_error' => null,
            'response_bodies_retained' => false,
            'production_write_execution' => false,
        ];

        try {
            $first = self::scanRound(1, $receipt, null);
            $scan['rounds'][] = $first;
            if (($first['complete'] ?? false) !== true) {
                throw new RuntimeException('ROUND_1_INCOMPLETE');
            }

            sleep(self::ROUND_DELAY_SECONDS);
            $second = self::scanRound(2, $receipt, (array) ($first['slug_set'] ?? []));
            $scan['rounds'][] = $second;
            if (($second['complete'] ?? false) !== true) {
                throw new RuntimeException('ROUND_2_INCOMPLETE');
            }

            $scan['status'] = 'SCAN_COMPLETE';
        } catch (Throwable $exception) {
            $scan['aborted'] = true;
            $scan['safe_error'] = self::safeError($exception->getMessage());
        }

        $scan['completed_at'] = gmdate('Y-m-d\TH:i:s\Z');
        self::writeJson($outputPath, $scan);

        return 0;
    }

    /** @param array<string, string> $options */
    private static function runFinalize(array $options): int
    {
        $prePath = self::requiredOption($options, 'pre-c03-receipt');
        $postPath = self::requiredOption($options, 'post-c03-receipt');
        $scanPath = self::requiredOption($options, 'scan');
        $outputDir = self::requiredOption($options, 'output-dir');
        $baseMainSha = self::commitSha(self::requiredOption($options, 'base-main-sha'));
        $c05MergeSha = self::commitSha(self::requiredOption($options, 'c05-merge-sha'));
        $preDigest = self::artifactDigest(self::requiredOption($options, 'pre-artifact-digest'));
        $postDigest = self::artifactDigest(self::requiredOption($options, 'post-artifact-digest'));

        $pre = self::jsonFile($prePath);
        $post = self::jsonFile($postPath);
        $scan = self::jsonFile($scanPath);
        self::validateC03Receipt($pre);
        self::validateC03Receipt($post);

        if (($scan['pre_c03_receipt_sha256'] ?? null) !== self::fileSha256($prePath)) {
            throw new RuntimeException('SCAN_PRE_RECEIPT_SHA_MISMATCH');
        }

        $stability = self::receiptStability($pre, $post);
        $c05 = self::c05Activation($c05MergeSha, (string) $pre['active_revision']);
        $scanSafety = self::scanSafety($scan);
        $counts = self::counts($pre, $scan);
        $verdict = self::determineVerdict([
            'source_stable' => $stability['stable'],
            'scan_safe' => $scanSafety['safe'],
            'c05_active' => $c05['active'],
            'unique_slug_count' => $counts['authority_unique_slugs'],
            'en_count' => $counts['public_en'],
            'zh_count' => $counts['public_zh_CN'],
            'localized_total' => $counts['public_locale_rows'],
        ]);

        self::ensureDirectory($outputDir);
        $sourcePath = rtrim($outputDir, '/').'/source-receipts.v1.json';
        $csvPath = rtrim($outputDir, '/').'/target-readback.v1.csv';
        $manifestPath = rtrim($outputDir, '/').'/manifest.v1.json';

        $sources = [
            'contract_version' => self::CONTRACT_VERSION,
            'base_main_sha' => $baseMainSha,
            'c05_merge_sha' => $c05MergeSha,
            'pre_c03' => self::safeReceiptProjection($pre, self::fileSha256($prePath), $preDigest),
            'post_c03' => self::safeReceiptProjection($post, self::fileSha256($postPath), $postDigest),
            'receipt_stability' => $stability,
            'approval_phrase_retained' => false,
            'raw_response_body_retained' => false,
        ];
        self::writeJson($sourcePath, $sources);
        self::writeCsv($csvPath, self::csvRows($scan));

        $manifest = [
            'contract_version' => self::CONTRACT_VERSION,
            'task_id' => 'CAREER-1046-TRUTH-RESCAN-06',
            'verdict' => $verdict,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'scan_window' => [
                'started_at' => $scan['started_at'] ?? null,
                'completed_at' => $scan['completed_at'] ?? null,
                'round_count' => count((array) ($scan['rounds'] ?? [])),
                'stable' => $stability['stable'],
            ],
            'lineage' => [
                'base_main_sha' => $baseMainSha,
                'active_revision' => $pre['active_revision'],
                'authority_artifact_sha256' => $pre['authority_artifact_sha256'],
                'runner_sha256' => self::fileSha256(__FILE__),
                'source_receipts_sha256' => self::fileSha256($sourcePath),
                'target_readback_sha256' => self::fileSha256($csvPath),
            ],
            'counts' => $counts,
            'acceptance_thresholds' => [
                'unique_slugs' => self::TARGET_UNIQUE_SLUGS,
                'en' => self::TARGET_UNIQUE_SLUGS,
                'zh_CN' => self::TARGET_UNIQUE_SLUGS,
                'localized_total' => self::TARGET_LOCALE_ROWS,
            ],
            'c05_cold_start_gate' => $c05,
            'scan_safety' => $scanSafety,
            'receipt_stability' => $stability,
            'career_link_publication_gate' => 'CLOSED',
            'pr6_allowed' => $verdict === 'PASS',
            'production_writes' => [
                'cache' => 0,
                'database' => 0,
                'cms' => 0,
                'publication' => 0,
                'indexability' => 0,
                'deploy' => 0,
                'migration' => 0,
                'restart' => 0,
                'search_submission' => 0,
            ],
            'evidence_boundary' => [
                'authority_source' => 'fresh C03 production verify receipts',
                'public_cohort_source' => 'receipt-bound current Jobs and Directory read models',
                'historical_1046_used_as_authority' => false,
                'held_or_unpublished_paths_requested' => false,
                'response_bodies_retained' => false,
            ],
        ];
        self::writeJson($manifestPath, $manifest);

        return self::validateEvidenceDirectory($outputDir) ? 0 : 1;
    }

    /** @param array<string, string> $options */
    private static function runValidate(array $options): int
    {
        return self::validateEvidenceDirectory(self::requiredOption($options, 'evidence-dir')) ? 0 : 1;
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @param  null|list<string>  $expectedSlugs
     * @return array<string, mixed>
     */
    private static function scanRound(int $number, array $receipt, ?array $expectedSlugs): array
    {
        $collectionRequests = [
            'jobs|en' => self::API_BASE.'/api/v0.5/career/jobs?locale=en',
            'jobs|zh-CN' => self::API_BASE.'/api/v0.5/career/jobs?locale=zh-CN',
            'directory|en' => self::API_BASE.'/api/v0.5/career/directory?locale=en',
            'directory|zh-CN' => self::API_BASE.'/api/v0.5/career/directory?locale=zh-CN',
            'industries|en' => self::API_BASE.'/api/v0.5/career/industries?locale=en',
            'industries|zh-CN' => self::API_BASE.'/api/v0.5/career/industries?locale=zh-CN',
            'sitemap' => self::WEB_BASE.'/sitemap.xml',
            'llms' => self::WEB_BASE.'/llms.txt',
            'llms_full' => self::WEB_BASE.'/llms-full.txt',
        ];
        $collections = self::requestBatch($collectionRequests);
        $errors = self::httpErrors($collections);
        if ($errors !== []) {
            return self::incompleteRound($number, $errors);
        }

        $jobs = [
            'en' => self::jobsSlugs(self::jsonBody($collections['jobs|en']['body'])),
            'zh-CN' => self::jobsSlugs(self::jsonBody($collections['jobs|zh-CN']['body'])),
        ];
        $directories = [
            'en' => self::directorySnapshot(self::jsonBody($collections['directory|en']['body'])),
            'zh-CN' => self::directorySnapshot(self::jsonBody($collections['directory|zh-CN']['body'])),
        ];
        $slugs = $jobs['en'];
        $expectedSlugs ??= $slugs;
        $slugSetMatches = self::setHash($slugs) === (string) ($receipt['published_cohort']['slug_set_sha256'] ?? '')
            && $slugs === $jobs['zh-CN']
            && $slugs === $directories['en']['slugs']
            && $slugs === $directories['zh-CN']['slugs']
            && $slugs === $expectedSlugs;

        $surfaceRows = [
            'sitemap' => self::careerRowsFromText((string) $collections['sitemap']['body']),
            'llms' => self::careerRowsFromText((string) $collections['llms']['body']),
            'llms_full' => self::careerRowsFromText((string) $collections['llms_full']['body']),
        ];
        $expectedRows = self::localeRows($slugs);
        $surfaceSetMatches = true;
        foreach ($surfaceRows as $rows) {
            if ($rows !== $expectedRows) {
                $surfaceSetMatches = false;
            }
        }

        $privateLeakCount = 0;
        foreach (['sitemap', 'llms', 'llms_full'] as $surface) {
            foreach (self::urlsFromText((string) $collections[$surface]['body']) as $url) {
                $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
                if (preg_match(self::PRIVATE_PATH_PATTERN, $path) === 1) {
                    $privateLeakCount++;
                }
            }
        }

        $detailRequests = [];
        foreach ($slugs as $slug) {
            foreach (['en' => 'en', 'zh-CN' => 'zh'] as $locale => $prefix) {
                $detailRequests['api|'.$locale.'|'.$slug] = self::API_BASE.'/api/v0.5/career/jobs/'.$slug.'?locale='.rawurlencode($locale);
                $detailRequests['page|'.$locale.'|'.$slug] = self::WEB_BASE.'/'.$prefix.'/career/jobs/'.$slug;
            }
        }
        $details = self::requestBatch($detailRequests);
        $detailErrors = self::httpErrors($details);
        $errors = array_merge($errors, $detailErrors);

        $targets = [];
        if ($detailErrors === []) {
            foreach ($slugs as $slug) {
                foreach (['en' => 'en', 'zh-CN' => 'zh'] as $locale => $prefix) {
                    $api = $details['api|'.$locale.'|'.$slug];
                    $page = $details['page|'.$locale.'|'.$slug];
                    $apiPayload = self::jsonBody($api['body']);
                    $pageMeta = self::htmlMeta((string) $page['body']);
                    $expectedPath = '/'.$prefix.'/career/jobs/'.$slug;
                    $expectedCanonical = self::WEB_BASE.$expectedPath;
                    $alternateEn = self::WEB_BASE.'/en/career/jobs/'.$slug;
                    $alternateZh = self::WEB_BASE.'/zh/career/jobs/'.$slug;
                    $familyUuid = self::nullableString($apiPayload['identity']['family_uuid'] ?? null);
                    $directoryFamily = self::nullableString($directories[$locale]['families'][$slug] ?? null);
                    $targets[] = [
                        'slug' => $slug,
                        'locale' => $locale,
                        'path' => $expectedPath,
                        'api_http_status' => (int) $api['status'],
                        'page_http_status' => (int) $page['status'],
                        'redirect_count' => (int) $page['redirect_count'],
                        'api_identity_ok' => ($apiPayload['identity']['canonical_slug'] ?? null) === $slug,
                        'api_canonical_ok' => ($apiPayload['seo_contract']['canonical_path'] ?? null) === $expectedPath
                            && ($apiPayload['seo_contract']['canonical_target'] ?? null) === $expectedPath,
                        'api_indexability_ok' => ($apiPayload['seo_contract']['index_state'] ?? null) === 'indexable'
                            && ($apiPayload['seo_contract']['index_eligible'] ?? false) === true
                            && self::robotsIndexable((string) ($apiPayload['seo_contract']['robots_policy'] ?? '')),
                        'page_canonical_ok' => $pageMeta['canonical'] === $expectedCanonical,
                        'page_hreflang_ok' => ($pageMeta['alternates']['en'] ?? null) === $alternateEn
                            && ($pageMeta['alternates']['zh-CN'] ?? null) === $alternateZh,
                        'page_robots_ok' => self::robotsIndexable((string) $pageMeta['robots']),
                        'jobs_member' => true,
                        'directory_member' => true,
                        'sitemap_member' => in_array($slug.'|'.$locale, $surfaceRows['sitemap'], true),
                        'llms_member' => in_array($slug.'|'.$locale, $surfaceRows['llms'], true),
                        'llms_full_member' => in_array($slug.'|'.$locale, $surfaceRows['llms_full'], true),
                        'family_uuid' => $familyUuid,
                        'directory_family_slug' => $directoryFamily,
                    ];
                }
            }
        }

        usort($targets, static fn (array $left, array $right): int => ($left['slug'].'|'.$left['locale']) <=> ($right['slug'].'|'.$right['locale']));
        $targetFailures = count(array_filter($targets, static fn (array $target): bool => ! self::targetPasses($target)));
        $industryEn = self::industrySnapshot(self::jsonBody($collections['industries|en']['body']));
        $industryZh = self::industrySnapshot(self::jsonBody($collections['industries|zh-CN']['body']));
        $industryConsistent = $industryEn['slugs'] === $industryZh['slugs']
            && $industryEn['counts'] === $industryZh['counts'];

        return [
            'round' => $number,
            'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'complete' => $errors === [],
            'errors' => $errors,
            'slug_set' => $slugs,
            'slug_count' => count($slugs),
            'locale_row_count' => count($expectedRows),
            'slug_set_sha256' => self::setHash($slugs),
            'row_set_sha256' => self::setHash($expectedRows),
            'jobs_directory_receipt_set_match' => $slugSetMatches,
            'surface_set_match' => $surfaceSetMatches,
            'private_path_leak_count' => $privateLeakCount,
            'timeout_count' => self::countError($errors, 'timeout'),
            'server_error_count' => self::countError($errors, 'server_error'),
            'target_failure_count' => $targetFailures,
            'family_membership' => [
                'en_non_null_count' => count(array_filter($directories['en']['families'])),
                'zh_CN_non_null_count' => count(array_filter($directories['zh-CN']['families'])),
                'locale_consistent' => $directories['en']['families'] === $directories['zh-CN']['families'],
            ],
            'industry_membership' => [
                'en_industry_count' => count($industryEn['slugs']),
                'zh_CN_industry_count' => count($industryZh['slugs']),
                'locale_consistent' => $industryConsistent,
            ],
            'targets' => $targets,
        ];
    }

    /** @return array<string, mixed> */
    private static function incompleteRound(int $number, array $errors): array
    {
        return [
            'round' => $number,
            'checked_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'complete' => false,
            'errors' => $errors,
            'timeout_count' => self::countError($errors, 'timeout'),
            'server_error_count' => self::countError($errors, 'server_error'),
            'private_path_leak_count' => 0,
            'target_failure_count' => 0,
            'targets' => [],
        ];
    }

    /**
     * @param  array<string, string>  $requests
     * @return array<string, array{status:int,body:string,curl_errno:int,redirect_count:int}>
     */
    private static function requestBatch(array $requests): array
    {
        if (! function_exists('curl_multi_init')) {
            throw new RuntimeException('CURL_EXTENSION_UNAVAILABLE');
        }

        $results = [];
        foreach (array_chunk($requests, self::MAX_CONCURRENCY, true) as $chunk) {
            $multi = curl_multi_init();
            $handles = [];
            foreach ($chunk as $id => $url) {
                $handle = curl_init($url);
                if ($handle === false) {
                    throw new RuntimeException('CURL_INIT_FAILED');
                }
                curl_setopt_array($handle, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                    CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_USERAGENT => 'FermatMind-Career-C06-Truth-Rescan/1.0',
                    CURLOPT_HTTPHEADER => ['Accept: application/json,text/html,application/xml,text/plain;q=0.9'],
                ]);
                curl_multi_add_handle($multi, $handle);
                $handles[(string) $id] = $handle;
            }

            do {
                $status = curl_multi_exec($multi, $running);
                if ($running > 0) {
                    curl_multi_select($multi, 1.0);
                }
            } while ($running > 0 && $status === CURLM_OK);

            foreach ($handles as $id => $handle) {
                $body = curl_multi_getcontent($handle);
                $results[$id] = [
                    'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                    'body' => is_string($body) ? $body : '',
                    'curl_errno' => curl_errno($handle),
                    'redirect_count' => (int) curl_getinfo($handle, CURLINFO_REDIRECT_COUNT),
                ];
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            curl_multi_close($multi);

            $chunkErrors = self::httpErrors(array_intersect_key($results, $chunk));
            if (self::countError($chunkErrors, 'timeout') > 0 || self::countError($chunkErrors, 'server_error') > 0) {
                break;
            }
        }

        return $results;
    }

    /** @param array<string, array{status:int,body:string,curl_errno:int,redirect_count:int}> $responses */
    private static function httpErrors(array $responses): array
    {
        $errors = [];
        foreach ($responses as $id => $response) {
            $reason = null;
            if ($response['curl_errno'] === CURLE_OPERATION_TIMEDOUT) {
                $reason = 'timeout';
            } elseif ($response['curl_errno'] !== 0) {
                $reason = 'transport_error';
            } elseif ($response['status'] >= 500) {
                $reason = 'server_error';
            } elseif ($response['status'] !== 200) {
                $reason = 'non_200';
            }
            if ($reason !== null) {
                $errors[] = ['request_id' => (string) $id, 'reason' => $reason, 'status' => $response['status']];
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private static function jobsSlugs(array $payload): array
    {
        $slugs = [];
        foreach ((array) ($payload['items'] ?? []) as $item) {
            if (! is_array($item)) {
                throw new RuntimeException('JOBS_ITEM_INVALID');
            }
            $slugs[] = self::slug($item['identity']['canonical_slug'] ?? null);
        }

        return self::uniqueSorted($slugs, 'JOBS_DUPLICATE_IDENTITY');
    }

    /** @return array{slugs:list<string>,families:array<string, ?string>} */
    private static function directorySnapshot(array $payload): array
    {
        $slugs = [];
        $families = [];
        foreach ((array) ($payload['items'] ?? []) as $item) {
            if (! is_array($item)) {
                throw new RuntimeException('DIRECTORY_ITEM_INVALID');
            }
            $slug = self::slug($item['slug'] ?? null);
            if (array_key_exists($slug, $families)) {
                throw new RuntimeException('DIRECTORY_DUPLICATE_IDENTITY');
            }
            if (($item['indexable'] ?? false) !== true || ($item['detail_ready'] ?? false) !== true) {
                throw new RuntimeException('DIRECTORY_UNQUALIFIED_IDENTITY');
            }
            $slugs[] = $slug;
            $families[$slug] = self::nullableString($item['family']['slug'] ?? null);
        }
        sort($slugs, SORT_STRING);
        ksort($families, SORT_STRING);

        return ['slugs' => $slugs, 'families' => $families];
    }

    /** @return array{slugs:list<string>,counts:array<string, int>} */
    private static function industrySnapshot(array $payload): array
    {
        $counts = [];
        foreach ((array) ($payload['industries'] ?? []) as $industry) {
            if (! is_array($industry)) {
                throw new RuntimeException('INDUSTRY_ITEM_INVALID');
            }
            $slug = self::slug($industry['slug'] ?? null);
            if (array_key_exists($slug, $counts)) {
                throw new RuntimeException('INDUSTRY_DUPLICATE_IDENTITY');
            }
            $counts[$slug] = (int) ($industry['public_detail_count'] ?? -1);
        }
        ksort($counts, SORT_STRING);

        return ['slugs' => array_keys($counts), 'counts' => $counts];
    }

    /** @return array{canonical:?string,robots:?string,alternates:array<string,string>} */
    private static function htmlMeta(string $html): array
    {
        $canonical = null;
        $robots = null;
        $alternates = [];
        preg_match_all('/<(?:link|meta)\b[^>]*>/i', $html, $matches);
        foreach ($matches[0] ?? [] as $tag) {
            $rel = strtolower((string) self::attribute($tag, 'rel'));
            $name = strtolower((string) self::attribute($tag, 'name'));
            if ($rel === 'canonical') {
                $canonical = self::attribute($tag, 'href');
            } elseif ($rel === 'alternate') {
                $hreflang = self::attribute($tag, 'hreflang');
                $href = self::attribute($tag, 'href');
                if ($hreflang !== null && $href !== null) {
                    $alternates[$hreflang] = $href;
                }
            } elseif ($name === 'robots') {
                $robots = self::attribute($tag, 'content');
            }
        }
        ksort($alternates, SORT_STRING);

        return ['canonical' => $canonical, 'robots' => $robots, 'alternates' => $alternates];
    }

    private static function attribute(string $tag, string $name): ?string
    {
        if (preg_match('/\b'.preg_quote($name, '/').'\s*=\s*(["\'])(.*?)\1/i', $tag, $match) !== 1) {
            return null;
        }

        return html_entity_decode(trim($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @return list<string> */
    private static function careerRowsFromText(string $text): array
    {
        $rows = [];
        foreach (self::urlsFromText($text) as $url) {
            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
            if (preg_match('#^/(en|zh)/career/jobs/([a-z0-9]+(?:-[a-z0-9]+)*)/?$#D', $path, $match) !== 1) {
                continue;
            }
            $rows[] = $match[2].'|'.($match[1] === 'zh' ? 'zh-CN' : 'en');
        }

        return self::uniqueSorted($rows, 'SURFACE_DUPLICATE_IDENTITY');
    }

    /** @return list<string> */
    private static function urlsFromText(string $text): array
    {
        preg_match_all('#https://[^\s<>)"\']+#i', $text, $matches);
        $urls = array_map(static fn (string $url): string => rtrim($url, '.,;'), $matches[0] ?? []);

        return array_values($urls);
    }

    /** @param list<string> $slugs @return list<string> */
    private static function localeRows(array $slugs): array
    {
        $rows = [];
        foreach ($slugs as $slug) {
            $rows[] = $slug.'|en';
            $rows[] = $slug.'|zh-CN';
        }
        sort($rows, SORT_STRING);

        return $rows;
    }

    private static function targetPasses(array $target): bool
    {
        foreach (['api_identity_ok', 'api_canonical_ok', 'api_indexability_ok', 'page_canonical_ok', 'page_hreflang_ok', 'page_robots_ok', 'jobs_member', 'directory_member', 'sitemap_member', 'llms_member', 'llms_full_member'] as $field) {
            if (($target[$field] ?? false) !== true) {
                return false;
            }
        }

        return ($target['api_http_status'] ?? 0) === 200
            && ($target['page_http_status'] ?? 0) === 200
            && ($target['redirect_count'] ?? -1) === 0;
    }

    public static function validateC03Receipt(array $receipt): void
    {
        if (($receipt['contract_version'] ?? null) !== self::C03_CONTRACT
            || ($receipt['mode'] ?? null) !== 'verify'
            || ($receipt['status'] ?? null) !== self::PASS_STATUS
            || ($receipt['career_link_publication_gate'] ?? null) !== 'CLOSED'
            || ($receipt['public_converged'] ?? false) !== true
            || ($receipt['job_index_converged'] ?? false) !== true
            || ($receipt['directory_converged'] ?? false) !== true
            || ($receipt['sitemap_source_converged'] ?? false) !== true
            || ($receipt['published_cohort'] ?? null) !== ($receipt['detail_coverage'] ?? null)) {
            throw new RuntimeException('C03_RECEIPT_INVALID');
        }
        foreach (self::ZERO_COUNT_FIELDS as $field) {
            if (($receipt[$field] ?? null) !== 0) {
                throw new RuntimeException('C03_ZERO_WRITE_GUARANTEE_INVALID');
            }
        }
        self::commitSha((string) ($receipt['control_plane_sha'] ?? ''));
        self::commitSha((string) ($receipt['active_revision'] ?? ''));
        self::sha256((string) ($receipt['authority_artifact_sha256'] ?? ''));
        self::sha256((string) ($receipt['authority_inventory']['row_set_sha256'] ?? ''));
        self::sha256((string) ($receipt['published_cohort']['row_set_sha256'] ?? ''));
    }

    /** @return array{stable:bool,drift_fields:list<string>} */
    public static function receiptStability(array $pre, array $post): array
    {
        $fields = [
            'active_revision',
            'authority_artifact_sha256',
            'authority_inventory',
            'published_cohort',
            'detail_coverage',
            'target_set_sha256',
        ];
        $drift = [];
        foreach ($fields as $field) {
            if (($pre[$field] ?? null) !== ($post[$field] ?? null)) {
                $drift[] = $field;
            }
        }

        return ['stable' => $drift === [], 'drift_fields' => $drift];
    }

    /** @return array{active:bool,ancestry:bool,runner_present:bool,deploy_wiring_present:bool} */
    private static function c05Activation(string $c05MergeSha, string $activeRevision): array
    {
        $ancestry = self::gitExit(['merge-base', '--is-ancestor', $c05MergeSha, $activeRevision]) === 0;
        $runnerPresent = self::gitExit(['cat-file', '-e', $activeRevision.':backend/scripts/deploy/verify_career_cold_cache_discoverability.php']) === 0;
        $deploySource = self::gitOutput(['show', $activeRevision.':deploy.php']);
        $deployWiring = str_contains($deploySource, 'verify_career_cold_cache_discoverability.php')
            && str_contains($deploySource, "task('guard:career-runtime-projection-authority'")
            && str_contains($deploySource, "task('guard:career-discoverability-pre-sitemap'")
            && str_contains($deploySource, "task('guard:career-discoverability-post-sitemap'");

        return [
            'active' => $ancestry && $runnerPresent && $deployWiring,
            'ancestry' => $ancestry,
            'runner_present' => $runnerPresent,
            'deploy_wiring_present' => $deployWiring,
        ];
    }

    /** @return array<string, mixed> */
    private static function scanSafety(array $scan): array
    {
        $rounds = (array) ($scan['rounds'] ?? []);
        $safe = ($scan['status'] ?? null) === 'SCAN_COMPLETE'
            && ($scan['aborted'] ?? true) === false
            && count($rounds) === 2;
        $timeout = 0;
        $serverErrors = 0;
        $privateLeaks = 0;
        $targetFailures = 0;
        foreach ($rounds as $round) {
            if (! is_array($round)) {
                $safe = false;

                continue;
            }
            $timeout += (int) ($round['timeout_count'] ?? 0);
            $serverErrors += (int) ($round['server_error_count'] ?? 0);
            $privateLeaks += (int) ($round['private_path_leak_count'] ?? 0);
            $targetFailures += (int) ($round['target_failure_count'] ?? 0);
            $safe = $safe
                && ($round['complete'] ?? false) === true
                && ($round['jobs_directory_receipt_set_match'] ?? false) === true
                && ($round['surface_set_match'] ?? false) === true
                && ($round['family_membership']['locale_consistent'] ?? false) === true
                && ($round['industry_membership']['locale_consistent'] ?? false) === true;
        }
        $safe = $safe && $timeout === 0 && $serverErrors === 0 && $privateLeaks === 0 && $targetFailures === 0;

        return [
            'safe' => $safe,
            'timeout_count' => $timeout,
            'server_error_count' => $serverErrors,
            'private_path_leak_count' => $privateLeaks,
            'target_failure_count' => $targetFailures,
            'round_count' => count($rounds),
        ];
    }

    /** @return array<string, int> */
    private static function counts(array $receipt, array $scan): array
    {
        $round = (array) (($scan['rounds'][0] ?? null) ?: []);

        return [
            'authority_unique_slugs' => (int) ($receipt['authority_inventory']['unique_slug_count'] ?? 0),
            'authority_locale_rows' => (int) ($receipt['authority_inventory']['locale_row_count'] ?? 0),
            'published_unique_slugs' => (int) ($receipt['published_cohort']['slug_count'] ?? 0),
            'public_en' => (int) ($receipt['published_cohort']['locales']['en']['count'] ?? 0),
            'public_zh_CN' => (int) ($receipt['published_cohort']['locales']['zh-CN']['count'] ?? 0),
            'public_locale_rows' => (int) ($receipt['published_cohort']['row_count'] ?? 0),
            'scanned_unique_slugs' => (int) ($round['slug_count'] ?? 0),
            'scanned_locale_rows' => (int) ($round['locale_row_count'] ?? 0),
            'family_members_en' => (int) ($round['family_membership']['en_non_null_count'] ?? 0),
            'family_members_zh_CN' => (int) ($round['family_membership']['zh_CN_non_null_count'] ?? 0),
            'industries_en' => (int) ($round['industry_membership']['en_industry_count'] ?? 0),
            'industries_zh_CN' => (int) ($round['industry_membership']['zh_CN_industry_count'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $context */
    public static function determineVerdict(array $context): string
    {
        if (($context['source_stable'] ?? false) !== true || ($context['scan_safe'] ?? false) !== true) {
            return 'NO_GO';
        }

        $thresholdsPass = (int) ($context['unique_slug_count'] ?? 0) === self::TARGET_UNIQUE_SLUGS
            && (int) ($context['en_count'] ?? 0) === self::TARGET_UNIQUE_SLUGS
            && (int) ($context['zh_count'] ?? 0) === self::TARGET_UNIQUE_SLUGS
            && (int) ($context['localized_total'] ?? 0) === self::TARGET_LOCALE_ROWS;

        return $thresholdsPass && ($context['c05_active'] ?? false) === true ? 'PASS' : 'PARTIALLY_BLOCKED';
    }

    /** @return list<array<string, scalar|null>> */
    private static function csvRows(array $scan): array
    {
        $rounds = (array) ($scan['rounds'] ?? []);
        $byIdentity = [];
        foreach ($rounds as $roundIndex => $round) {
            foreach ((array) ($round['targets'] ?? []) as $target) {
                if (! is_array($target)) {
                    continue;
                }
                $key = (string) ($target['slug'] ?? '').'|'.(string) ($target['locale'] ?? '');
                $byIdentity[$key] ??= [
                    'slug' => $target['slug'] ?? null,
                    'locale' => $target['locale'] ?? null,
                    'path' => $target['path'] ?? null,
                    'family_uuid' => $target['family_uuid'] ?? null,
                    'directory_family_slug' => $target['directory_family_slug'] ?? null,
                ];
                $prefix = 'round_'.($roundIndex + 1).'_';
                $byIdentity[$key][$prefix.'api_http_status'] = $target['api_http_status'] ?? null;
                $byIdentity[$key][$prefix.'page_http_status'] = $target['page_http_status'] ?? null;
                $byIdentity[$key][$prefix.'redirect_count'] = $target['redirect_count'] ?? null;
                $byIdentity[$key][$prefix.'checks_pass'] = self::targetPasses($target);
            }
        }
        ksort($byIdentity, SORT_STRING);

        return array_values($byIdentity);
    }

    /** @param list<array<string, scalar|null>> $rows */
    private static function writeCsv(string $path, array $rows): void
    {
        $headers = [
            'slug', 'locale', 'path', 'family_uuid', 'directory_family_slug',
            'round_1_api_http_status', 'round_1_page_http_status', 'round_1_redirect_count', 'round_1_checks_pass',
            'round_2_api_http_status', 'round_2_page_http_status', 'round_2_redirect_count', 'round_2_checks_pass',
        ];
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('CSV_OPEN_FAILED');
        }
        fputcsv($handle, $headers, ',', '"', '');
        foreach ($rows as $row) {
            $values = [];
            foreach ($headers as $header) {
                $value = $row[$header] ?? '';
                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                }
                $values[] = self::csvSafe((string) $value);
            }
            fputcsv($handle, $values, ',', '"', '');
        }
        fclose($handle);
    }

    private static function csvSafe(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }

    /** @return array<string, mixed> */
    private static function safeReceiptProjection(array $receipt, string $receiptSha, string $artifactDigest): array
    {
        $zero = [];
        foreach (self::ZERO_COUNT_FIELDS as $field) {
            $zero[$field] = $receipt[$field];
        }

        return [
            'run_id' => $receipt['workflow_run_id'],
            'run_attempt' => $receipt['workflow_run_attempt'],
            'receipt_sha256' => $receiptSha,
            'artifact_digest' => $artifactDigest,
            'status' => $receipt['status'],
            'control_plane_sha' => $receipt['control_plane_sha'],
            'active_revision' => $receipt['active_revision'],
            'runner_sha256' => $receipt['runner_sha256'],
            'authority_artifact_sha256' => $receipt['authority_artifact_sha256'],
            'authority_inventory' => $receipt['authority_inventory'],
            'published_cohort' => $receipt['published_cohort'],
            'detail_coverage' => $receipt['detail_coverage'],
            'target_set_sha256' => $receipt['target_set_sha256'],
            'public_timeout_count' => $receipt['public_timeout_count'],
            'public_5xx_count' => $receipt['public_5xx_count'],
            'private_path_leak_count' => $receipt['private_path_leak_count'],
            'zero_counts' => $zero,
            'career_link_publication_gate' => $receipt['career_link_publication_gate'],
        ];
    }

    private static function validateEvidenceDirectory(string $directory): bool
    {
        $manifestPath = rtrim($directory, '/').'/manifest.v1.json';
        $sourcePath = rtrim($directory, '/').'/source-receipts.v1.json';
        $csvPath = rtrim($directory, '/').'/target-readback.v1.csv';
        $manifest = self::jsonFile($manifestPath);
        $sources = self::jsonFile($sourcePath);

        if (($manifest['contract_version'] ?? null) !== self::CONTRACT_VERSION
            || ! in_array($manifest['verdict'] ?? null, ['PASS', 'PARTIALLY_BLOCKED', 'NO_GO'], true)
            || ($manifest['career_link_publication_gate'] ?? null) !== 'CLOSED'
            || ($manifest['lineage']['source_receipts_sha256'] ?? null) !== self::fileSha256($sourcePath)
            || ($manifest['lineage']['target_readback_sha256'] ?? null) !== self::fileSha256($csvPath)
            || ($sources['approval_phrase_retained'] ?? true) !== false
            || ($sources['raw_response_body_retained'] ?? true) !== false) {
            throw new RuntimeException('EVIDENCE_VALIDATION_FAILED');
        }
        $handle = fopen($csvPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('CSV_READ_FAILED');
        }
        $header = fgetcsv($handle, 0, ',', '"', '');
        if (! is_array($header) || $header[0] !== 'slug' || count($header) !== 13) {
            fclose($handle);
            throw new RuntimeException('CSV_HEADER_INVALID');
        }
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (count($row) !== count($header)) {
                fclose($handle);
                throw new RuntimeException('CSV_ROW_INVALID');
            }
            foreach ($row as $value) {
                if (preg_match('/^[=+\-@]/', (string) $value) === 1) {
                    fclose($handle);
                    throw new RuntimeException('CSV_FORMULA_INJECTION');
                }
            }
        }
        fclose($handle);

        return true;
    }

    /** @param array<string, mixed> $responses */
    private static function countError(array $responses, string $reason): int
    {
        return count(array_filter($responses, static fn (array $error): bool => ($error['reason'] ?? null) === $reason));
    }

    /** @param list<string> $values @return list<string> */
    private static function uniqueSorted(array $values, string $duplicateCode): array
    {
        $values = array_values(array_filter($values, static fn (string $value): bool => $value !== ''));
        if (count($values) !== count(array_unique($values))) {
            throw new RuntimeException($duplicateCode);
        }
        sort($values, SORT_STRING);

        return $values;
    }

    public static function setHash(array $items): string
    {
        $items = array_values(array_unique(array_map('strval', $items)));
        sort($items, SORT_STRING);

        return hash('sha256', implode("\n", $items)."\n");
    }

    private static function robotsIndexable(string $value): bool
    {
        $tokens = array_values(array_filter(array_map('trim', explode(',', strtolower($value)))));

        return in_array('index', $tokens, true)
            && in_array('follow', $tokens, true)
            && ! in_array('noindex', $tokens, true)
            && ! in_array('nofollow', $tokens, true);
    }

    private static function slug(mixed $value): string
    {
        $slug = is_string($value) ? strtolower(trim($value)) : '';
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
            throw new RuntimeException('SLUG_INVALID');
        }

        return $slug;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    /** @return array<string, mixed> */
    private static function jsonBody(string $body): array
    {
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('PUBLIC_JSON_INVALID');
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    private static function jsonFile(string $path): array
    {
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('JSON_FILE_INVALID');
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('JSON_PARSE_FAILED');
        }

        return $decoded;
    }

    private static function writeJson(string $path, array $payload): void
    {
        self::ensureDirectory(dirname($path));
        $encoded = json_encode(self::canonicalize($payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $encoded.PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('JSON_WRITE_FAILED');
        }
    }

    private static function ensureDirectory(string $path): void
    {
        if (is_link($path) || (! is_dir($path) && ! mkdir($path, 0700, true))) {
            throw new RuntimeException('OUTPUT_DIRECTORY_INVALID');
        }
    }

    private static function fileSha256(string $path): string
    {
        $sha = is_file($path) ? hash_file('sha256', $path) : false;
        if (! is_string($sha)) {
            throw new RuntimeException('FILE_SHA_FAILED');
        }

        return $sha;
    }

    private static function sha256(string $value): string
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new RuntimeException('SHA256_INVALID');
        }

        return $value;
    }

    private static function commitSha(string $value): string
    {
        if (preg_match('/^[0-9a-f]{40}$/D', $value) !== 1) {
            throw new RuntimeException('COMMIT_SHA_INVALID');
        }

        return $value;
    }

    private static function artifactDigest(string $value): string
    {
        if (preg_match('/^sha256:[0-9a-f]{64}$/D', $value) !== 1) {
            throw new RuntimeException('ARTIFACT_DIGEST_INVALID');
        }

        return $value;
    }

    /** @return array<string, string> */
    private static function parseOptions(array $arguments): array
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z0-9-]+)=(.+)$/D', (string) $argument, $match) !== 1) {
                throw new RuntimeException('OPTION_INVALID');
            }
            $options[$match[1]] = $match[2];
        }

        return $options;
    }

    /** @param array<string, string> $options */
    private static function requiredOption(array $options, string $key): string
    {
        $value = trim((string) ($options[$key] ?? ''));
        if ($value === '') {
            throw new RuntimeException('OPTION_REQUIRED_'.$key);
        }

        return $value;
    }

    /** @param list<string> $arguments */
    private static function gitExit(array $arguments): int
    {
        $command = array_merge(['git'], $arguments);
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptor, $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('GIT_PROCESS_FAILED');
        }
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process);
    }

    /** @param list<string> $arguments */
    private static function gitOutput(array $arguments): string
    {
        $command = array_merge(['git'], $arguments);
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptor, $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('GIT_PROCESS_FAILED');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            return '';
        }

        return $stdout;
    }

    private static function safeError(string $message): string
    {
        $safe = strtoupper(preg_replace('/[^A-Za-z0-9_]+/', '_', $message) ?? 'UNEXPECTED_FAILURE');

        return trim($safe, '_') ?: 'UNEXPECTED_FAILURE';
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(Career1046TruthRescan06::main($argv));
}
