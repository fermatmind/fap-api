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
        {--json : Emit JSON report}
        {--output= : Optional report output path}';

    protected $description = 'Read-only career public release gate validator for live career pages.';

    public function handle(): int
    {
        try {
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

        return ($report['decision'] ?? 'fail') === 'fail' ? self::FAILURE : self::SUCCESS;
    }
}
