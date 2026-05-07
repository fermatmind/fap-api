<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class CareerValidateReleaseGate extends Command
{
    private const FORBIDDEN_PUBLIC_TERMS = [
        'release_gate',
        'release_gates',
        'qa_risk',
        'admin_review_state',
        'tracking_json',
        'raw_ai_exposure_score',
    ];

    protected $signature = 'career:validate-release-gate
        {--slugs= : Comma-separated career slugs}
        {--locales=zh,en : Comma-separated locales}
        {--base-url=https://fermatmind.com : Public site base URL}
        {--public-resolution-ledger= : Optional full release ledger JSON artifact for public type matrix validation}
        {--json : Emit JSON report}
        {--output= : Optional report output path}';

    protected $description = 'Read-only career public release gate validator for live career pages.';

    public function handle(): int
    {
        try {
            $publicResolutionLedgerPath = trim((string) ($this->option('public-resolution-ledger') ?? ''));
            if ($publicResolutionLedgerPath !== '') {
                return $this->finish($this->validatePublicTypeMatrix($publicResolutionLedgerPath));
            }

            $slugs = $this->requiredCsvOption('slugs');
            $locales = $this->requiredCsvOption('locales');
            $baseUrl = rtrim(trim((string) $this->option('base-url')), '/');
            if ($baseUrl === '') {
                throw new RuntimeException('--base-url is required.');
            }

            $items = [];
            foreach ($slugs as $slug) {
                foreach ($locales as $locale) {
                    $items[] = $this->validateUrl($baseUrl, $slug, $locale);
                }
            }

            $report = [
                'command' => 'career:validate-release-gate',
                'validator_version' => 'career_release_gate_validator_v0.1',
                'read_only' => true,
                'writes_database' => false,
                'release_states_changed' => false,
                'sitemap_changed' => false,
                'llms_changed' => false,
                'base_url' => $baseUrl,
                'slugs' => $slugs,
                'locales' => $locales,
                'validated_count' => count($items),
                'decision' => $this->allPassed($items) ? 'pass' : 'no_go',
                'summary' => $this->summary($items),
                'items' => $items,
            ];

            return $this->finish($report);
        } catch (Throwable $throwable) {
            return $this->finish([
                'command' => 'career:validate-release-gate',
                'validator_version' => 'career_release_gate_validator_v0.1',
                'decision' => 'fail',
                'read_only' => true,
                'writes_database' => false,
                'errors' => [$throwable->getMessage()],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePublicTypeMatrix(string $ledgerPath): array
    {
        if (! is_file($ledgerPath)) {
            throw new RuntimeException('career public resolution ledger artifact not found: '.$ledgerPath);
        }

        $payload = json_decode((string) file_get_contents($ledgerPath), true);
        if (! is_array($payload)) {
            throw new RuntimeException('career public resolution ledger artifact is not valid JSON: '.$ledgerPath);
        }

        $rows = data_get($payload, 'public_resolution.rows');
        if (! is_array($rows)) {
            $rows = data_get($payload, 'rows');
        }
        if (! is_array($rows)) {
            throw new RuntimeException('career public resolution ledger artifact has no public resolution rows: '.$ledgerPath);
        }

        $items = [];
        foreach (array_values(array_filter($rows, static fn (mixed $row): bool => is_array($row))) as $row) {
            $items[] = $this->validatePublicTypeRow($row);
        }

        $blocked = array_values(array_filter(
            $items,
            static fn (array $item): bool => ($item['Release_Gate_Result'] ?? null) !== 'pass',
        ));

        return [
            'command' => 'career:validate-release-gate',
            'validator_version' => 'career_release_gate_public_type_matrix_v0.1',
            'read_only' => true,
            'writes_database' => false,
            'release_states_changed' => false,
            'sitemap_changed' => false,
            'llms_changed' => false,
            'public_resolution_ledger' => $ledgerPath,
            'validated_count' => count($items),
            'decision' => $blocked === [] && $items !== [] ? 'pass' : 'no_go',
            'summary' => [
                'pass' => count($items) - count($blocked),
                'blocked' => count($blocked),
            ],
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function validatePublicTypeRow(array $row): array
    {
        $type = trim((string) ($row['public_resolution_type'] ?? ''));
        $reasons = [];
        $sourceSlug = trim((string) ($row['source_slug'] ?? ''));
        $currentStatus = trim((string) ($row['current_status'] ?? ''));
        $publicEligible = (bool) ($row['public_eligible'] ?? false);

        if ($type === '') {
            $reasons[] = $publicEligible ? 'public_row_missing_public_resolution_type' : 'missing_public_resolution_type';
        } elseif (! in_array($type, CareerPublicResolutionTypeMatrix::allowedTypes(), true)) {
            $reasons[] = 'unknown_public_resolution_type';
        }

        if ($sourceSlug === 'software-developers' && $publicEligible) {
            $reasons[] = 'software_developers_public_leakage';
        }

        if (in_array($currentStatus, ['duplicate_identity_hold', 'CN_proxy_hold', 'broad_group_hold', 'manual_hold'], true)
            && $type === CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB) {
            $reasons[] = 'held_row_public_canonical_job_leakage';
        }

        $reasons = [...$reasons, ...match ($type) {
            CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB => $this->validatePublicCanonicalJobRow($row),
            CareerPublicResolutionTypeMatrix::PUBLIC_ALIAS_REDIRECT => $this->validatePublicAliasRedirectRow($row),
            CareerPublicResolutionTypeMatrix::PUBLIC_FAMILY_HUB => $this->validatePublicFamilyHubRow($row),
            CareerPublicResolutionTypeMatrix::PUBLIC_CN_PROXY_PAGE => $this->validatePublicCnProxyPageRow($row),
            CareerPublicResolutionTypeMatrix::PUBLIC_NONINDEX_REFERENCE => $this->validatePublicNonindexReferenceRow($row),
            CareerPublicResolutionTypeMatrix::KEEP_NON_PUBLIC_WITH_POLICY,
            CareerPublicResolutionTypeMatrix::BLOCKED_UNTIL_GOVERNANCE_APPROVAL => $this->validateNonPublicRow($row),
            default => [],
        }];

        return [
            'source_slug' => $sourceSlug,
            'current_status' => $currentStatus,
            'public_resolution_type' => $type,
            'public_eligible' => $publicEligible,
            'Release_Gate_Result' => $reasons === [] ? 'pass' : 'blocked',
            'Failure_Reason' => array_values(array_unique($reasons)),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function validatePublicCanonicalJobRow(array $row): array
    {
        $reasons = [];
        if (! (bool) ($row['public_eligible'] ?? false)) {
            $reasons[] = 'public_canonical_job_not_public_eligible';
        }
        if ((string) ($row['indexability'] ?? '') !== 'indexable') {
            $reasons[] = 'public_canonical_job_not_indexable';
        }
        foreach (['sitemap_eligible', 'llms_eligible', 'llms_full_eligible'] as $field) {
            if (! (bool) ($row[$field] ?? false)) {
                $reasons[] = 'public_canonical_job_'.$field.'_false';
            }
        }

        return $reasons;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function validatePublicAliasRedirectRow(array $row): array
    {
        $reasons = [];
        if (! (bool) ($row['public_eligible'] ?? false)) {
            $reasons[] = 'public_alias_redirect_not_public_eligible';
        }
        if (trim((string) ($row['target_canonical_slug'] ?? '')) === '') {
            $reasons[] = 'public_alias_redirect_missing_release_approved_target';
        }
        if ((string) ($row['indexability'] ?? '') !== 'no_independent_index') {
            $reasons[] = 'public_alias_redirect_independent_indexability';
        }
        $reasons = [...$reasons, ...$this->forbidSitemapLlms($row, 'public_alias_redirect')];

        return $reasons;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function validatePublicFamilyHubRow(array $row): array
    {
        $reasons = [];
        if (! (bool) ($row['public_eligible'] ?? false)) {
            $reasons[] = 'public_family_hub_not_public_eligible';
        }
        if (trim((string) ($row['family_hub_slug'] ?? '')) === '') {
            $reasons[] = 'public_family_hub_missing_slug';
        }
        if (! is_array($row['child_canonical_slugs'] ?? null) || $row['child_canonical_slugs'] === []) {
            $reasons[] = 'public_family_hub_missing_child_canonical_links';
        }
        if (trim((string) ($row['schema_policy'] ?? '')) === '') {
            $reasons[] = 'public_family_hub_missing_schema_policy';
        }
        if (! (bool) ($row['trust_manifest_required'] ?? false)) {
            $reasons[] = 'public_family_hub_missing_trust_manifest_requirement';
        }

        return $reasons;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function validatePublicCnProxyPageRow(array $row): array
    {
        $reasons = [];
        if (! (bool) ($row['public_eligible'] ?? false)) {
            $reasons[] = 'public_cn_proxy_page_not_public_eligible';
        }
        if (! (bool) ($row['boundary_disclaimer_required'] ?? false)) {
            $reasons[] = 'public_cn_proxy_page_missing_disclaimer';
        }
        if (! (bool) ($row['trust_manifest_required'] ?? false)) {
            $reasons[] = 'public_cn_proxy_page_missing_trust_manifest_requirement';
        }
        if ((string) ($row['indexability'] ?? '') !== 'noindex') {
            $reasons[] = 'public_cn_proxy_page_not_noindex_default';
        }
        $reasons = [...$reasons, ...$this->forbidSitemapLlms($row, 'public_cn_proxy_page')];

        return $reasons;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function validatePublicNonindexReferenceRow(array $row): array
    {
        $reasons = [];
        if (! (bool) ($row['public_eligible'] ?? false)) {
            $reasons[] = 'public_nonindex_reference_not_public_eligible';
        }
        if (! in_array((string) ($row['indexability'] ?? ''), ['noindex', 'no_independent_index'], true)) {
            $reasons[] = 'public_nonindex_reference_without_noindex';
        }
        $reasons = [...$reasons, ...$this->forbidSitemapLlms($row, 'public_nonindex_reference')];

        return $reasons;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function validateNonPublicRow(array $row): array
    {
        $reasons = [];
        if ((bool) ($row['public_eligible'] ?? false)) {
            $reasons[] = 'non_public_type_marked_public_eligible';
        }
        if ((string) ($row['indexability'] ?? '') !== 'not_public') {
            $reasons[] = 'non_public_type_indexability_not_public';
        }
        $reasons = [...$reasons, ...$this->forbidSitemapLlms($row, 'non_public_type')];

        return $reasons;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function forbidSitemapLlms(array $row, string $type): array
    {
        $reasons = [];
        foreach (['sitemap_eligible', 'llms_eligible', 'llms_full_eligible'] as $field) {
            if ((bool) ($row[$field] ?? false)) {
                $reasons[] = $type.'_'.$field;
            }
        }

        return $reasons;
    }

    /**
     * @return list<string>
     */
    private function requiredCsvOption(string $name): array
    {
        $raw = trim((string) $this->option($name));
        if ($raw === '') {
            throw new RuntimeException('--'.$name.' is required.');
        }

        $values = array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => strtolower(trim($value)),
            explode(',', $raw),
        ), static fn (string $value): bool => $value !== '')));
        if ($values === []) {
            throw new RuntimeException('--'.$name.' must contain at least one value.');
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateUrl(string $baseUrl, string $slug, string $locale): array
    {
        $url = $baseUrl.'/'.$locale.'/career/jobs/'.$slug;
        $started = hrtime(true);

        try {
            $response = Http::timeout(12)->withOptions(['allow_redirects' => true])->get($url);
        } catch (Throwable $throwable) {
            return [
                'slug' => $slug,
                'locale' => $locale,
                'url' => $url,
                'HTTP_Status' => null,
                'Release_Gate_Result' => 'fail',
                'Failure_Reason' => ['request_failed:'.$throwable->getMessage()],
            ];
        }

        $html = (string) $response->body();
        $ttfbMs = (int) round((hrtime(true) - $started) / 1_000_000);
        $canonical = $this->firstMatch('/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/i', $html)
            ?? $this->firstMatch('/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']canonical["\']/i', $html);
        $robots = $this->firstMatch('/<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']+)["\']/i', $html);
        $schemaText = strtolower($html);
        $forbidden = array_values(array_filter(
            self::FORBIDDEN_PUBLIC_TERMS,
            static fn (string $term): bool => str_contains($schemaText, $term),
        ));
        $productAbsent = ! str_contains($schemaText, '"@type":"product"')
            && ! str_contains($schemaText, '"@type": "product"');
        $faqVisible = str_contains($schemaText, 'faq') || str_contains($html, '常见问题');
        $faqSchema = str_contains($schemaText, '"@type":"faqpage"') || str_contains($schemaText, '"@type": "faqpage"');
        $occupationSchemaSafe = ! str_contains($schemaText, '"@type":"product"')
            && ! str_contains($schemaText, '"@type": "product"');
        $ctaOk = str_contains($html, 'holland-career-interest-test-riasec')
            && str_contains($html, 'start_riasec_test')
            && str_contains($html, 'career_job_detail')
            && (str_contains($html, 'subject_key='.$slug) || str_contains($html, 'subject_key%3D'.$slug));
        $canonicalOk = is_string($canonical) && str_contains($canonical, '/'.$locale.'/career/jobs/'.$slug);
        $noindex = is_string($robots) && str_contains(strtolower($robots), 'noindex');
        $schemaOk = $productAbsent && ($faqSchema || ! $faqVisible) && $occupationSchemaSafe;
        $nextStepLinksOk = substr_count($html, 'holland-career-interest-test-riasec') >= 1;
        $passed = $response->ok()
            && $canonicalOk
            && ! $noindex
            && $schemaOk
            && $ctaOk
            && $forbidden === []
            && $productAbsent;

        return [
            'slug' => $slug,
            'locale' => $locale,
            'url' => $url,
            'HTTP_Status' => $response->status(),
            'Final_URL' => $url,
            'Redirect_Count' => null,
            'Canonical_OK' => $canonicalOk,
            'Canonical' => $canonical,
            'Noindex' => $noindex,
            'Robots_OK' => ! $noindex,
            'Robots' => $robots,
            'Public_Cache_OK' => true,
            'TTFB_ms' => $ttfbMs,
            'HTML_Size_KB' => round(strlen($html) / 1024, 1),
            'Schema_OK' => $schemaOk,
            'FAQ_Schema_OK' => $faqSchema || ! $faqVisible,
            'Occupation_Schema_OK' => $occupationSchemaSafe,
            'Internal_Links_OK' => $nextStepLinksOk,
            'Next_Step_Links_OK' => $nextStepLinksOk,
            'CTA_OK' => $ctaOk,
            'Product_Absent' => $productAbsent,
            'Forbidden_Absent' => $forbidden === [],
            'Forbidden_Found' => $forbidden,
            'Release_Gate_Result' => $passed ? 'pass' : 'blocked',
            'Failure_Reason' => $this->failureReasons($response->ok(), $canonicalOk, $noindex, $schemaOk, $ctaOk, $forbidden, $productAbsent),
        ];
    }

    private function firstMatch(string $pattern, string $html): ?string
    {
        if (preg_match($pattern, $html, $matches) !== 1) {
            return null;
        }

        return (string) ($matches[1] ?? '');
    }

    /**
     * @param  list<string>  $forbidden
     * @return list<string>
     */
    private function failureReasons(bool $httpOk, bool $canonicalOk, bool $noindex, bool $schemaOk, bool $ctaOk, array $forbidden, bool $productAbsent): array
    {
        $reasons = [];
        if (! $httpOk) {
            $reasons[] = 'http_not_200';
        }
        if (! $canonicalOk) {
            $reasons[] = 'canonical_not_self';
        }
        if ($noindex) {
            $reasons[] = 'noindex_present';
        }
        if (! $schemaOk) {
            $reasons[] = 'schema_invalid';
        }
        if (! $ctaOk) {
            $reasons[] = 'cta_missing_or_unattributed';
        }
        if ($forbidden !== []) {
            $reasons[] = 'forbidden_fields_present';
        }
        if (! $productAbsent) {
            $reasons[] = 'product_schema_present';
        }

        return $reasons;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function allPassed(array $items): bool
    {
        return $items !== [] && count(array_filter($items, static fn (array $item): bool => ($item['Release_Gate_Result'] ?? null) !== 'pass')) === 0;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, int>
     */
    private function summary(array $items): array
    {
        return [
            'pass' => count(array_filter($items, static fn (array $item): bool => ($item['Release_Gate_Result'] ?? null) === 'pass')),
            'blocked' => count(array_filter($items, static fn (array $item): bool => ($item['Release_Gate_Result'] ?? null) !== 'pass')),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function finish(array $report): int
    {
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($json)) {
            throw new RuntimeException('Unable to encode validation report.');
        }

        $output = trim((string) ($this->option('output') ?? ''));
        if ($output !== '') {
            $written = file_put_contents($output, $json.PHP_EOL);
            if ($written === false) {
                throw new RuntimeException('Unable to write report output: '.$output);
            }
        }

        if ((bool) $this->option('json')) {
            $this->output->write($json.PHP_EOL, false, OutputInterface::OUTPUT_RAW);
        } else {
            $this->line('validator_version='.$report['validator_version']);
            $this->line('decision='.$report['decision']);
            $this->line('validated_count='.$report['validated_count']);
        }

        return ($report['decision'] ?? 'fail') === 'pass' ? self::SUCCESS : self::FAILURE;
    }
}
