<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityMbti64BackendImportContractCommandTest extends TestCase
{
    public function test_dry_run_plans_exact_eight_row_v21_package_without_writes(): void
    {
        $packagePath = $this->writePackage($this->validPackage());

        $exitCode = Artisan::call('personality:mbti64-backend-import-contract', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('pass', $payload['status']);
        $this->assertTrue($payload['dry_run_only']);
        $this->assertFalse($payload['write_supported_in_this_pr']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertSame(8, $payload['row_count']);
        $this->assertSame(6, $payload['variant_row_count']);
        $this->assertSame(2, $payload['comparison_row_count']);
        $this->assertTrue($payload['row_order_locked']);
        $this->assertSame([], $payload['errors']);
    }

    public function test_comparison_rows_are_profile_revision_draft_overlays_not_standalone_models(): void
    {
        $packagePath = $this->writePackage($this->validPackage());

        $exitCode = Artisan::call('personality:mbti64-backend-import-contract', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $comparison = $payload['rows'][0] ?? [];
        $variant = $payload['rows'][1] ?? [];

        $this->assertSame(0, $exitCode);
        $this->assertSame('comparison', $comparison['page_type'] ?? null);
        $this->assertSame('App\\Models\\PersonalityProfileRevision', $comparison['target']['target_model'] ?? null);
        $this->assertSame('mbti64_comparison_draft_v2_1', $comparison['draft_revision']['snapshot_key'] ?? null);
        $this->assertFalse($comparison['target']['standalone_comparison_model'] ?? true);
        $this->assertSame('variant', $variant['page_type'] ?? null);
        $this->assertSame('App\\Models\\PersonalityProfileVariantRevision', $variant['target']['target_model'] ?? null);
        $this->assertSame('mbti64_variant_content_package_v2_1', $variant['draft_revision']['snapshot_key'] ?? null);
    }

    public function test_command_requires_explicit_dry_run(): void
    {
        $packagePath = $this->writePackage($this->validPackage());

        $exitCode = Artisan::call('personality:mbti64-backend-import-contract', [
            '--package' => $packagePath,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('runtime_error', $payload['errors'][0]['code'] ?? null);
        $this->assertStringContainsString('--dry-run is required', (string) ($payload['errors'][0]['message'] ?? ''));
    }

    public function test_command_refuses_write_mode(): void
    {
        $packagePath = $this->writePackage($this->validPackage());

        $exitCode = Artisan::call('personality:mbti64-backend-import-contract', [
            '--package' => $packagePath,
            '--write' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['write_supported_in_this_pr']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertStringContainsString('--write is intentionally unsupported', (string) ($payload['errors'][0]['message'] ?? ''));
    }

    public function test_row_order_mismatch_blocks_contract_plan(): void
    {
        $package = $this->validPackage();
        $package['rows'] = array_reverse($package['rows']);
        $packagePath = $this->writePackage($package);

        $exitCode = Artisan::call('personality:mbti64-backend-import-contract', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['row_order_locked']);
        $this->assertSame('pilot_queue_order_mismatch', $payload['errors'][0]['code'] ?? null);
    }

    /**
     * @return array<string,mixed>
     */
    private function validPackage(): array
    {
        $urls = [
            ['/en/personality/intj-a-vs-intj-t', 'en', 'comparison'],
            ['/zh/personality/istj-a', 'zh-CN', 'variant'],
            ['/en/personality/intp-a-vs-intp-t', 'en', 'comparison'],
            ['/zh/personality/infp-t', 'zh-CN', 'variant'],
            ['/en/personality/intj-a', 'en', 'variant'],
            ['/en/personality/intj-t', 'en', 'variant'],
            ['/zh/personality/intj-a', 'zh-CN', 'variant'],
            ['/zh/personality/intj-t', 'zh-CN', 'variant'],
        ];

        return [
            'artifact' => 'mbti64-content-package-pilot-v2.1',
            'version' => 'pilot-v2.1',
            'status' => 'pass',
            'rows' => array_map(fn (array $row): array => $this->row($row[0], $row[1], $row[2]), $urls),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function row(string $url, string $locale, string $pageType): array
    {
        return [
            'url' => $url,
            'locale' => $locale,
            'page_type' => $pageType,
            'primary_query' => 'fixture query',
            'secondary_queries' => ['fixture secondary'],
            'excluded_queries' => ['fixture excluded'],
            'target_intent' => 'fixture intent',
            'target_test_route' => str_starts_with($url, '/zh/')
                ? '/zh/tests/mbti-personality-test-16-personality-types'
                : '/en/tests/mbti-personality-test-16-personality-types',
            'canonical_target' => $url,
            'seo' => [
                'seo_title' => 'Fixture title',
                'seo_description' => 'Fixture description.',
                'breadcrumb_title' => 'Fixture',
                'h1' => 'Fixture H1',
                'quick_answer_summary' => 'Fixture summary.',
            ],
            'content' => [
                'quick_answer' => 'Fixture answer.',
                'method_boundary' => ['h2' => 'Method boundary', 'body' => 'Fixture boundary.'],
            ],
            'faq' => [
                ['question' => 'Fixture question?', 'answer' => 'Fixture answer.'],
            ],
            'internal_links' => [
                [
                    'href' => str_starts_with($url, '/zh/') ? '/zh/personality' : '/en/personality',
                    'anchor_text' => 'Personality hub',
                    'role' => 'hub',
                    'safe_public_route' => true,
                ],
            ],
            'method_boundary' => 'Fixture method boundary.',
            'trademark_boundary' => 'Fixture trademark boundary.',
            'information_gain' => ['unique_user_value' => 'fixture'],
            'claim_risk_notes' => ['No deterministic claims.'],
            'qa_flags_for_codex' => ['Fixture QA.'],
            'route_safety' => ['forbidden_route_patterns_absent_from_internal_links' => true],
            'v2_optimization' => ['primary_improvement' => 'fixture'],
            'above_the_fold_module' => ['answer_card_title' => 'Fixture'],
            'status' => 'draft_for_codex_qa',
            'serp_ctr_package_v2' => ['seo_title' => 'Fixture title'],
        ];
    }

    /**
     * @param  array<string,mixed>  $package
     */
    private function writePackage(array $package): string
    {
        $path = sys_get_temp_dir().'/mbti64-contract-'.bin2hex(random_bytes(6)).'.json';
        File::put($path, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }
}
