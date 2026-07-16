<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Models\PersonalityPublicContentAssetRevisionReview;
use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV206RevisionPromoter;
use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV224RuntimeCloseout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class EnneagramPublicAuthorityV224RuntimeCloseoutTest extends TestCase
{
    use RefreshDatabase;

    private const BACKEND_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const FRONTEND_SHA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const REVALIDATION_SECRET = 'runtime-closeout-test-secret-with-entropy';

    public function test_preflight_generates_exact_review_batches_and_authorization_without_writes(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        $register = $this->reviewRegister($report);

        $result = $this->closeout()->preflight(
            $report,
            $this->fingerprintRaw($report),
            $register,
            $this->fingerprintRaw($register),
            self::BACKEND_SHA,
            self::FRONTEND_SHA,
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('PASS_EXACT_SHA_RUNTIME_PREFLIGHT_AUTHORIZATION_REQUIRED', $result['status']);
        $this->assertSame(116, $result['target_count']);
        $this->assertSame(116, $result['approved_review_count']);
        $this->assertSame(0, $result['media_write_count']);
        $this->assertFalse($result['writes_committed']);
        $this->assertSame(116, count($result['artifacts']['checklist']['items']));
        $this->assertSame(116, count($result['artifacts']['review_register_template']['reviews']));
        $batches = $result['artifacts']['readback_batches']['batches'];
        $this->assertSame([
            'en|hub:enneagram',
            'zh-CN|hub:enneagram',
            'en|center:gut',
            'zh-CN|center:head',
            'en|core_type:type-1',
            'zh-CN|wing:1w9',
            'en|instinctual_subtype:type-4/social',
            'en|instinctual_subtype:type-9/one-to-one',
        ], array_column($batches['canary-00'], 'asset_key'));
        $this->assertSame(8, count($batches['canary-00']));
        for ($index = 1; $index <= 9; $index++) {
            $this->assertSame(12, count($batches[sprintf('readback-%02d', $index)]));
        }
        $this->assertStringContainsString('READBACK=8+9x12', $result['authorization_packet']['authorization_phrase']);
        $this->assertStringContainsString('AUTOMATIC_ROLLBACK=0', $result['authorization_packet']['authorization_phrase']);
        $this->assertStringNotContainsString('Private Human Reviewer', json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertSame(0, PersonalityPublicContentAssetRevision::query()->count());
        $this->assertSame(0, PersonalityPublicContentAssetRevisionReview::query()->count());
        $this->assertSame(116, PersonalityPublicContentAsset::query()->whereNull('working_revision_id')->count());
    }

    public function test_authorized_execute_runs_import_bind_atomic_promotion_cache_and_nine_plus_canary_readbacks(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        $register = $this->reviewRegister($report);
        $reportSha = $this->fingerprintRaw($report);
        $registerSha = $this->fingerprintRaw($register);
        $preflight = $this->closeout()->preflight(
            $report,
            $reportSha,
            $register,
            $registerSha,
            self::BACKEND_SHA,
            self::FRONTEND_SHA,
        );
        $this->fakeRuntimeHttp($report);
        $rollbackPath = '/tmp/enneagram-v224-rollback-'.bin2hex(random_bytes(8)).'.token';

        try {
            $result = $this->closeout()->execute(
                $report,
                $reportSha,
                $register,
                $registerSha,
                self::BACKEND_SHA,
                self::FRONTEND_SHA,
                $preflight['authorization_packet'],
                (string) $preflight['authorization_packet_sha256'],
                (string) $preflight['authorization_packet']['authorization_phrase'],
                $rollbackPath,
                'https://api.test',
                'https://frontend.test',
                'https://frontend.test/api/content-release/revalidate',
                self::REVALIDATION_SECRET,
            );

            $this->assertTrue($result['ok']);
            $this->assertSame('PASS_AUTHORIZED_RUNTIME_CLOSEOUT', $result['status']);
            $this->assertSame(116, $result['working_import']['revision_created_count']);
            $this->assertSame(116, $result['review_bind']['review_evidence_created_count']);
            $this->assertSame(116, $result['promotion']['promoted_count']);
            $this->assertSame('PASS_POINTER_SAFE_ROLLBACK_PREFLIGHT', $result['rollback_preflight']['status']);
            $this->assertSame(116, $result['frontend_revalidation']['accepted_count']);
            $this->assertSame(0, $result['frontend_revalidation']['rejected_count']);
            $this->assertSame(10, count($result['readback_batches']));
            $this->assertSame(8, $result['readback_batches']['canary-00']['target_count']);
            $this->assertSame(12, $result['readback_batches']['readback-09']['target_count']);
            $this->assertSame(0, $result['media_write_count']);
            $this->assertFalse($result['automatic_rollback']);
            $this->assertFileExists($rollbackPath);
            $this->assertSame(0600, fileperms($rollbackPath) & 0777);
            $this->assertStringNotContainsString((string) file_get_contents($rollbackPath), json_encode($result, JSON_THROW_ON_ERROR));
            $this->assertSame(116, PersonalityPublicContentAssetRevisionReview::query()->count());
            $this->assertSame(116, PersonalityPublicContentAssetRevision::query()
                ->where('workflow_state', EnneagramPublicAuthorityV206RevisionPromoter::STATE_PUBLISHED)
                ->where('authority_package_sha256', (string) $report['package_sha256'])
                ->count());
            $this->assertSame(116, PersonalityPublicContentAsset::query()
                ->where('review_state', EnneagramPublicAuthorityV206RevisionPromoter::STATE_HUMAN_REVIEW_APPROVED)
                ->whereNull('working_revision_id')
                ->count());
            $this->assertStringNotContainsString('Private Human Reviewer', json_encode($result, JSON_THROW_ON_ERROR));
        } finally {
            @unlink($rollbackPath);
        }
    }

    public function test_console_preflight_generates_only_redacted_operator_artifacts(): void
    {
        $this->seedPublishedEstate();
        $registerPath = storage_path('framework/testing/enneagram-v224-private-register.json');
        $outputDirectory = storage_path('framework/testing/enneagram-v224-artifacts-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists(dirname($registerPath));
        File::put($registerPath, json_encode($this->reviewRegister($this->releaseReport()), JSON_THROW_ON_ERROR));

        try {
            $this->artisan('personality:enneagram-authority-v2-runtime-closeout', [
                '--preflight' => true,
                '--review-register' => $registerPath,
                '--backend-deployed-sha' => self::BACKEND_SHA,
                '--frontend-deployed-sha' => self::FRONTEND_SHA,
                '--output-dir' => $outputDirectory,
                '--allow-testing' => true,
            ])
                ->expectsOutputToContain('status=PASS_EXACT_SHA_RUNTIME_PREFLIGHT_AUTHORIZATION_REQUIRED')
                ->assertSuccessful();

            $this->assertFileExists($outputDirectory.'/human-review-checklist.json');
            $this->assertFileExists($outputDirectory.'/private-review-register-template.json');
            $this->assertFileExists($outputDirectory.'/readback-batches.json');
            $this->assertFileExists($outputDirectory.'/exact-sha-production-authorization-packet.json');
            $this->assertFileExists($outputDirectory.'/pr23-redacted-retrospective-template.json');
            $this->assertStringNotContainsString(
                'Private Human Reviewer',
                implode('', array_map(static fn (\SplFileInfo $file): string => File::get($file->getPathname()), File::files($outputDirectory))),
            );
            $this->assertSame(0, PersonalityPublicContentAssetRevision::query()->count());
            $this->assertSame(0, PersonalityPublicContentAssetRevisionReview::query()->count());
        } finally {
            File::delete($registerPath);
            File::deleteDirectory($outputDirectory);
        }
    }

    public function test_late_execute_failure_reports_committed_writes_and_safe_rollback_evidence(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        $register = $this->reviewRegister($report);
        $reportSha = $this->fingerprintRaw($report);
        $registerSha = $this->fingerprintRaw($register);
        $closeout = $this->closeout();
        $preflight = $closeout->preflight(
            $report,
            $reportSha,
            $register,
            $registerSha,
            self::BACKEND_SHA,
            self::FRONTEND_SHA,
        );
        $this->fakeRuntimeHttp($report, true);
        $rollbackPath = '/tmp/enneagram-v224-rollback-'.bin2hex(random_bytes(8)).'.token';

        try {
            try {
                $closeout->execute(
                    $report,
                    $reportSha,
                    $register,
                    $registerSha,
                    self::BACKEND_SHA,
                    self::FRONTEND_SHA,
                    $preflight['authorization_packet'],
                    (string) $preflight['authorization_packet_sha256'],
                    (string) $preflight['authorization_packet']['authorization_phrase'],
                    $rollbackPath,
                    'https://api.test',
                    'https://frontend.test',
                    'https://frontend.test/api/content-release/revalidate',
                    self::REVALIDATION_SECRET,
                );
                $this->fail('Expected frontend revalidation to fail after promotion.');
            } catch (\Throwable $throwable) {
                $result = $closeout->failureResult($throwable);
            }

            $this->assertSame('FAIL_CLOSED_PARTIAL_WRITES_COMMITTED', $result['status']);
            $this->assertSame('frontend_revalidation', $result['failure_stage']);
            $this->assertTrue($result['writes_committed']);
            $this->assertTrue($result['working_import_committed']);
            $this->assertTrue($result['review_bind_committed']);
            $this->assertTrue($result['promotion_committed']);
            $this->assertTrue($result['rollback_token_persisted']);
            $this->assertFalse($result['automatic_rollback']);
            $this->assertFileExists($rollbackPath);
            $rollbackToken = trim((string) file_get_contents($rollbackPath));
            $this->assertSame(hash('sha256', $rollbackToken), $result['rollback_token_sha256']);
            $this->assertStringNotContainsString($rollbackToken, json_encode($result, JSON_THROW_ON_ERROR));
            $this->assertArrayNotHasKey('rollback_token_output', $result);
        } finally {
            @unlink($rollbackPath);
        }
    }

    /** @param array<string,mixed> $report */
    private function fakeRuntimeHttp(array $report, bool $rejectRevalidation = false): void
    {
        $paths = array_column($report['asset_records'], 'path');
        $urlText = implode("\n", array_map(static fn (string $path): string => 'https://frontend.test'.$path, $paths));
        Http::fake(function (Request $request) use ($rejectRevalidation, $urlText): \GuzzleHttp\Promise\PromiseInterface|\Illuminate\Http\Client\Response {
            $url = $request->url();
            if ($url === 'https://frontend.test/api/content-release/revalidate') {
                if ($rejectRevalidation) {
                    return Http::response(['ok' => false], 503);
                }
                $timestamp = (string) $request->header('X-FM-Content-Release-Timestamp')[0];
                $nonce = (string) $request->header('X-FM-Content-Release-Nonce')[0];
                $signature = (string) $request->header('X-FM-Content-Release-Signature')[0];
                $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$request->body(), self::REVALIDATION_SECRET);
                if (! hash_equals($expected, $signature)) {
                    return Http::response(['ok' => false], 401);
                }
                $payload = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);

                return Http::response([
                    'ok' => true,
                    'revalidated_paths' => $payload['cache_signal']['paths'],
                    'rejected_paths' => [],
                    'invalidated_tags' => [],
                ]);
            }
            if (str_starts_with($url, 'https://api.test/api/v0.5/personality-content-assets?')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $asset = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
                    ->where('framework', 'enneagram')
                    ->where('entity_type', (string) $query['entity_type'])
                    ->where('entity_key', (string) $query['code'])
                    ->where('locale', (string) $query['locale'])
                    ->firstOrFail();

                return Http::response([
                    'ok' => true,
                    'personality_public_content_asset_v1' => [
                        'framework' => 'enneagram',
                        'entity_type' => (string) $asset->entity_type,
                        'code' => (string) $asset->entity_key,
                        'locale' => (string) $asset->locale,
                        'title' => (string) $asset->title,
                        'summary' => (string) $asset->summary,
                        'seo' => ['description' => (string) $asset->summary],
                        'canonical_path' => (string) data_get($asset->canonical_json, 'path'),
                        'faq' => [],
                        'media' => ['hero' => null, 'inline' => [], 'og' => null],
                        'source_package' => (string) $asset->source_package,
                        'source_hash' => (string) $asset->source_hash,
                    ],
                    'personality_public_content_asset_v2' => [
                        'visible_evidence' => ['sources' => []],
                        'editorial_authority' => [
                            'review_state' => (string) $asset->review_state,
                            'reviewer' => null,
                        ],
                    ],
                ], 200, ['X-Fermat-Public-Read-Cache' => 'fresh']);
            }
            if ($url === 'https://frontend.test/sitemap.xml') {
                return Http::response('<urlset>'.implode('', array_map(
                    static fn (string $line): string => '<url><loc>'.$line.'</loc></url>',
                    explode("\n", $urlText),
                )).'</urlset>', 200, ['Content-Type' => 'application/xml']);
            }
            if (in_array($url, ['https://frontend.test/llms.txt', 'https://frontend.test/llms-full.txt'], true)) {
                return Http::response($urlText, 200, ['Content-Type' => 'text/plain']);
            }
            if (str_starts_with($url, 'https://frontend.test/')) {
                $path = (string) parse_url($url, PHP_URL_PATH);
                $asset = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
                    ->where('framework', 'enneagram')
                    ->get()
                    ->first(fn (PersonalityPublicContentAsset $candidate): bool => data_get($candidate->canonical_json, 'path') === $path);
                if (! $asset instanceof PersonalityPublicContentAsset) {
                    return Http::response('not found', 404);
                }
                $title = htmlspecialchars((string) $asset->title, ENT_QUOTES | ENT_HTML5);
                $summary = htmlspecialchars((string) $asset->summary, ENT_QUOTES | ENT_HTML5);

                return Http::response('<!doctype html><html><head><title>'.$title.'</title>'
                    .'<meta name="description" content="'.$summary.'">'
                    .'<link rel="canonical" href="https://frontend.test'.$path.'">'
                    .'<link rel="alternate" hreflang="en" href="https://frontend.test/en/personality/enneagram">'
                    .'<link rel="alternate" hreflang="zh-CN" href="https://frontend.test/zh/personality/enneagram">'
                    .'</head><body><h1>'.$title.'</h1><main>'.$summary.'</main></body></html>');
            }

            return Http::response(['ok' => false], 404);
        });
    }

    private function seedPublishedEstate(): void
    {
        foreach ($this->scorecard()['rows'] as $index => $row) {
            $path = (string) $row['path'];
            $slug = (string) preg_replace('#^/(?:en|zh)/personality/#', '', $path);
            PersonalityPublicContentAsset::query()->create([
                'org_id' => 0,
                'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
                'entity_type' => (string) $row['entity_type'],
                'entity_key' => (string) $row['code'],
                'slug' => $slug,
                'locale' => (string) $row['locale'],
                'title' => 'Published Enneagram authority '.($index + 1),
                'summary' => 'Existing backend-authoritative public content.',
                'content_sections_json' => [['key' => 'existing', 'body_md' => 'Existing public content.']],
                'seo_json' => ['title' => 'Published Enneagram authority '.($index + 1)],
                'robots' => PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW,
                'canonical_json' => ['path' => $path, 'url' => (string) $row['canonical']],
                'hreflang_json' => $row['hreflang'],
                'faq_json' => [],
                'media_json' => ['hero' => null, 'inline' => [], 'og' => null],
                'schema_json' => [],
                'method_boundary_json' => ['is_diagnostic' => false],
                'evidence_notes_json' => [],
                'authority_json' => ['source' => 'test-fixture', 'reviewer' => null],
                'internal_links_json' => [],
                'is_public' => true,
                'index_eligible' => true,
                'sitemap_eligible' => true,
                'llms_eligible' => true,
                'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
                'review_state' => 'published_no_llms',
                'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
                'source_package' => 'enneagram-90-cms-v1',
                'source_hash' => hash('sha256', (string) $row['identity_key'].'|'.$row['locale']),
                'published_at' => '2026-07-01 00:00:00',
            ]);
        }
        $this->assertSame(116, PersonalityPublicContentAsset::query()->count());
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private function reviewRegister(array $report): array
    {
        return [
            'schema_version' => 'enneagram_public_authority_v2_private_review_register.v1',
            'package_sha256' => (string) $report['package_sha256'],
            'review_source' => PersonalityPublicContentAssetRevisionReview::REVIEW_SOURCE_OPERATOR_SUPPLIED_HUMAN,
            'reviews' => array_map(static fn (array $record, int $index): array => [
                'asset_key' => (string) $record['asset_key'],
                'asset_sha256' => (string) $record['asset_sha256'],
                'reviewer_name' => 'Private Human Reviewer '.($index + 1),
                'reviewed_at' => '2026-07-16T00:00:00Z',
                'decision' => PersonalityPublicContentAssetRevisionReview::DECISION_APPROVED,
                'review_source' => PersonalityPublicContentAssetRevisionReview::REVIEW_SOURCE_OPERATOR_SUPPLIED_HUMAN,
            ], $report['asset_records'], array_keys($report['asset_records'])),
        ];
    }

    /** @return array<string,mixed> */
    private function releaseReport(): array
    {
        return $this->loadJson('docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-release-gate-22/release-gate-report.json');
    }

    /** @return array<string,mixed> */
    private function scorecard(): array
    {
        return $this->loadJson('docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-benchmark-01/production-scorecard.json');
    }

    /** @return array<string,mixed> */
    private function loadJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents(base_path($path)), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function closeout(): EnneagramPublicAuthorityV224RuntimeCloseout
    {
        return app(EnneagramPublicAuthorityV224RuntimeCloseout::class);
    }

    private function fingerprintRaw(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
