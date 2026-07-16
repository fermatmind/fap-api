<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Models\PersonalityPublicContentAssetRevisionReview;
use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV206RevisionPromoter;
use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV224RuntimeCloseout;
use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV224RuntimeReadback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
            'https://api.test',
            'https://frontend.test',
            'https://frontend.test/api/content-release/revalidate',
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('PASS_EXACT_SHA_RUNTIME_PREFLIGHT_AUTHORIZATION_REQUIRED', $result['status']);
        $this->assertSame(116, $result['target_count']);
        $this->assertSame(116, $result['approved_review_count']);
        $this->assertSame(0, $result['media_write_count']);
        $this->assertFalse($result['writes_committed']);
        $this->assertSame(116, count($result['artifacts']['checklist']['items']));
        $this->assertSame(116, count($result['artifacts']['review_register_template']['reviews']));
        $this->assertSame([
            'api_base_origin' => 'https://api.test',
            'frontend_base_origin' => 'https://frontend.test',
            'frontend_revalidation_endpoint' => 'https://frontend.test/api/content-release/revalidate',
        ], $result['authorization_packet']['runtime_endpoints']);
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

    public function test_preflight_rejects_review_evidence_sha_mismatch_without_writes(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        $register = $this->reviewRegister($report);
        $register['reviews'][0]['evidence_sha256'] = str_repeat('f', 64);

        try {
            $this->closeout()->preflight(
                $report,
                $this->fingerprintRaw($report),
                $register,
                $this->fingerprintRaw($register),
                self::BACKEND_SHA,
                self::FRONTEND_SHA,
                'https://api.test',
                'https://frontend.test',
                'https://frontend.test/api/content-release/revalidate',
            );
            $this->fail('Expected review evidence SHA mismatch to fail before authorization.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Private review register evidence SHA-256 is invalid', $exception->getMessage());
        }

        $this->assertSame(0, PersonalityPublicContentAssetRevision::query()->count());
        $this->assertSame(0, PersonalityPublicContentAssetRevisionReview::query()->count());
        $this->assertSame(116, PersonalityPublicContentAsset::query()->whereNull('working_revision_id')->count());
    }

    public function test_execute_rejects_runtime_endpoint_drift_before_any_write(): void
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
            'https://api.test',
            'https://frontend.test',
            'https://frontend.test/api/content-release/revalidate',
        );

        $driftedEndpoints = [
            ['https://mirror-api.test', 'https://frontend.test', 'https://frontend.test/api/content-release/revalidate'],
            ['https://api.test', 'https://mirror-frontend.test', 'https://frontend.test/api/content-release/revalidate'],
            ['https://api.test', 'https://frontend.test', 'https://mirror-frontend.test/api/content-release/revalidate'],
        ];
        foreach ($driftedEndpoints as [$apiBaseUrl, $frontendBaseUrl, $revalidationEndpoint]) {
            $rollbackPath = '/tmp/enneagram-v224-endpoint-drift-'.bin2hex(random_bytes(8)).'.token';
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
                    $apiBaseUrl,
                    $frontendBaseUrl,
                    $revalidationEndpoint,
                    self::REVALIDATION_SECRET,
                );
                $this->fail('Expected runtime endpoint drift to fail closed before writes.');
            } catch (\Throwable $throwable) {
                $result = $closeout->failureResult($throwable);
            }

            $this->assertSame('FAIL_CLOSED_NO_WRITES', $result['status']);
            $this->assertSame('authorization_validation', $result['failure_stage']);
            $this->assertFalse($result['writes_committed']);
            $this->assertFileDoesNotExist($rollbackPath);
        }

        $this->assertSame(0, PersonalityPublicContentAssetRevision::query()->count());
        $this->assertSame(0, PersonalityPublicContentAssetRevisionReview::query()->count());
        $this->assertSame(116, PersonalityPublicContentAsset::query()->whereNull('working_revision_id')->count());
    }

    public function test_authorized_execute_runs_import_bind_atomic_promotion_cache_and_nine_plus_canary_readbacks(): void
    {
        $this->seedPublishedEstate();
        $nonReleaseAsset = $this->seedNonReleaseAsset();
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
            'https://api.test',
            'https://frontend.test',
            'https://frontend.test/api/content-release/revalidate',
        );
        $this->fakeRuntimeHttp($report);
        $rollbackPath = '/tmp/enneagram-v224-rollback-'.bin2hex(random_bytes(8)).'.token';
        $registerPath = storage_path('framework/testing/enneagram-v224-post-readback-register-'.bin2hex(random_bytes(4)).'.json');
        File::ensureDirectoryExists(dirname($registerPath));
        File::put($registerPath, json_encode($register, JSON_THROW_ON_ERROR));

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
            $nonReleaseAsset->refresh();
            $this->assertSame('Non-release Enneagram draft', $nonReleaseAsset->title);
            $this->assertSame(PersonalityPublicContentAsset::LAUNCH_DRAFT, $nonReleaseAsset->launch_state);
            $this->assertNull($nonReleaseAsset->working_revision_id);
            $this->assertNull($nonReleaseAsset->published_revision_id);
            $this->assertStringNotContainsString('Private Human Reviewer', json_encode($result, JSON_THROW_ON_ERROR));

            $this->artisan('personality:enneagram-authority-v2-runtime-readback', [
                '--phase' => 'post',
                '--batch' => 'canary-00',
                '--api-base-url' => 'https://api.test',
                '--frontend-base-url' => 'https://frontend.test',
                '--backend-deployed-sha' => self::BACKEND_SHA,
                '--frontend-deployed-sha' => self::FRONTEND_SHA,
                '--review-register' => $registerPath,
                '--require-fresh-api-cache' => true,
                '--allow-testing' => true,
            ])
                ->expectsOutputToContain('status=PASS_POST_RUNTIME_READBACK')
                ->expectsOutputToContain('target_count=8')
                ->assertSuccessful();
        } finally {
            @unlink($rollbackPath);
            File::delete($registerPath);
        }
    }

    public function test_standalone_post_readback_rejects_incomplete_review_register_before_http(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        $register = $this->reviewRegister($report);
        array_pop($register['reviews']);
        $registerPath = storage_path('framework/testing/enneagram-v224-incomplete-register-'.bin2hex(random_bytes(4)).'.json');
        File::ensureDirectoryExists(dirname($registerPath));
        File::put($registerPath, json_encode($register, JSON_THROW_ON_ERROR));
        Http::fake();

        try {
            $this->artisan('personality:enneagram-authority-v2-runtime-readback', [
                '--phase' => 'post',
                '--batch' => 'all',
                '--api-base-url' => 'https://api.test',
                '--frontend-base-url' => 'https://frontend.test',
                '--backend-deployed-sha' => self::BACKEND_SHA,
                '--frontend-deployed-sha' => self::FRONTEND_SHA,
                '--review-register' => $registerPath,
                '--allow-testing' => true,
            ])
                ->expectsOutputToContain('status=FAIL_CLOSED')
                ->expectsOutputToContain('Private review register schema, source, package, or row count is invalid.')
                ->assertFailed();

            Http::assertNothingSent();
            $this->assertSame(0, PersonalityPublicContentAssetRevision::query()->count());
            $this->assertSame(0, PersonalityPublicContentAssetRevisionReview::query()->count());
        } finally {
            File::delete($registerPath);
        }
    }

    public function test_standalone_readback_rejects_non_origin_api_and_frontend_base_urls_before_http(): void
    {
        $this->seedPublishedEstate();
        Http::fake();
        $invalidOrigins = [
            ['api-base-url', 'https://api.test/api'],
            ['api-base-url', 'https://api.test?preview=1'],
            ['api-base-url', 'https://api.test#preview'],
            ['frontend-base-url', 'https://frontend.test/personality'],
            ['frontend-base-url', 'https://frontend.test?preview=1'],
            ['frontend-base-url', 'https://frontend.test#preview'],
        ];

        foreach ($invalidOrigins as [$option, $invalidOrigin]) {
            $arguments = [
                '--phase' => 'pre',
                '--batch' => 'canary-00',
                '--api-base-url' => 'https://api.test',
                '--frontend-base-url' => 'https://frontend.test',
                '--backend-deployed-sha' => self::BACKEND_SHA,
                '--frontend-deployed-sha' => self::FRONTEND_SHA,
                '--allow-testing' => true,
            ];
            $arguments['--'.$option] = $invalidOrigin;

            $this->artisan('personality:enneagram-authority-v2-runtime-readback', $arguments)
                ->expectsOutputToContain('--'.$option.' must be an exact HTTPS origin without credentials, path, query, or fragment.')
                ->expectsOutputToContain('status=FAIL_CLOSED')
                ->assertFailed();
        }

        Http::assertNothingSent();
        $this->assertSame(0, PersonalityPublicContentAssetRevision::query()->count());
        $this->assertSame(0, PersonalityPublicContentAssetRevisionReview::query()->count());
    }

    public function test_standalone_production_readback_frontend_revision_probe_disables_redirects(): void
    {
        $this->seedPublishedEstate();
        $revisionPath = base_path('../REVISION');
        $previousEnvironment = app()->environment();
        $revisionExisted = File::isFile($revisionPath);
        $revisionContents = $revisionExisted ? File::get($revisionPath) : null;
        File::put($revisionPath, self::BACKEND_SHA);
        app()->detectEnvironment(static fn (): string => 'production');
        $redirectsDisabled = false;
        Http::fake(function (Request $request, array $options) use (&$redirectsDisabled): \GuzzleHttp\Promise\PromiseInterface|\Illuminate\Http\Client\Response {
            if ($request->url() === 'https://frontend.test/revision') {
                $redirectsDisabled = ($options['allow_redirects'] ?? null) === false;

                return Http::response('', 302, ['Location' => 'https://staging-mirror.test/revision']);
            }

            return Http::response(['ok' => false], 404);
        });

        try {
            $this->artisan('personality:enneagram-authority-v2-runtime-readback', [
                '--phase' => 'pre',
                '--batch' => 'all',
                '--api-base-url' => 'https://api.test',
                '--frontend-base-url' => 'https://frontend.test',
                '--backend-deployed-sha' => self::BACKEND_SHA,
                '--frontend-deployed-sha' => self::FRONTEND_SHA,
                '--frontend-revision-url' => 'https://frontend.test/revision',
            ])
                ->expectsOutputToContain('Deployed frontend revision endpoint does not match the exact readback SHA.')
                ->expectsOutputToContain('status=FAIL_CLOSED')
                ->assertFailed();

            $this->assertTrue($redirectsDisabled);
            $this->assertSame(0, PersonalityPublicContentAssetRevision::query()->count());
            $this->assertSame(0, PersonalityPublicContentAssetRevisionReview::query()->count());
        } finally {
            app()->detectEnvironment(static fn (): string => $previousEnvironment);
            if ($revisionExisted && is_string($revisionContents)) {
                File::put($revisionPath, $revisionContents);
            } else {
                File::delete($revisionPath);
            }
        }
    }

    public function test_url_set_readback_rejects_wrong_origin_query_and_fragment_drift(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        $firstPath = (string) $report['asset_records'][0]['path'];
        $invalidUrls = [
            'https://staging.example.com'.$firstPath,
            'https://frontend.test'.$firstPath.'?preview=1',
            'https://frontend.test'.$firstPath.'#preview',
            'https://frontend.test'.$firstPath.'?',
            'https://frontend.test'.$firstPath.'#',
            'https://frontend.test'.$firstPath.'/',
        ];

        foreach ($invalidUrls as $invalidUrl) {
            $this->fakeRuntimeHttp($report, false, $invalidUrl);
            try {
                app(EnneagramPublicAuthorityV224RuntimeReadback::class)->snapshot(
                    $report,
                    'https://frontend.test',
                );
                $this->fail('Expected discoverability URL drift to fail closed: '.$invalidUrl);
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('URL', $exception->getMessage());
            }
        }
    }

    public function test_html_readback_rejects_canonical_origin_query_fragment_and_relative_drift(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        $path = '/en/personality/enneagram';
        $invalidCanonicals = [
            'https://staging.example.com'.$path,
            'https://frontend.test'.$path.'?preview=1',
            'https://frontend.test'.$path.'#preview',
            'https://frontend.test'.$path.'?',
            'https://frontend.test'.$path.'#',
            $path,
        ];

        foreach ($invalidCanonicals as $invalidCanonical) {
            $this->fakeRuntimeHttp($report, false, null, $invalidCanonical);
            try {
                app(EnneagramPublicAuthorityV224RuntimeReadback::class)->run(
                    'pre',
                    'canary-00',
                    $report,
                    'https://api.test',
                    'https://frontend.test',
                    self::BACKEND_SHA,
                    self::FRONTEND_SHA,
                );
                $this->fail('Expected HTML canonical drift to fail closed: '.$invalidCanonical);
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('html_canonical_mismatch', $exception->getMessage());
            }
        }
    }

    public function test_html_readback_rejects_hreflang_origin_query_fragment_and_relative_drift(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        $path = '/en/personality/enneagram';
        $invalidHreflangUrls = [
            'https://staging.example.com'.$path,
            'https://frontend.test'.$path.'?preview=1',
            'https://frontend.test'.$path.'#preview',
            'https://frontend.test'.$path.'?',
            'https://frontend.test'.$path.'#',
            $path,
        ];

        foreach ($invalidHreflangUrls as $invalidHreflangUrl) {
            $this->fakeRuntimeHttp($report, false, null, null, $invalidHreflangUrl);
            try {
                app(EnneagramPublicAuthorityV224RuntimeReadback::class)->run(
                    'pre',
                    'canary-00',
                    $report,
                    'https://api.test',
                    'https://frontend.test',
                    self::BACKEND_SHA,
                    self::FRONTEND_SHA,
                );
                $this->fail('Expected HTML hreflang drift to fail closed: '.$invalidHreflangUrl);
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('html_hreflang_mismatch', $exception->getMessage());
            }
        }
    }

    public function test_post_readback_rejects_case_folded_dom_split_private_reviewer_name_leak(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        $this->fakeRuntimeHttp(
            $report,
            privateReviewerLeak: 'private human reviewer 1',
            splitPrivateReviewerHtml: true,
        );

        try {
            app(EnneagramPublicAuthorityV224RuntimeReadback::class)->run(
                'post',
                'canary-00',
                $report,
                'https://api.test',
                'https://frontend.test',
                self::BACKEND_SHA,
                self::FRONTEND_SHA,
                false,
                ['Private Human Reviewer 1'],
            );
            $this->fail('Expected case-folded private reviewer leak to fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('api_private_reviewer_exposed', $exception->getMessage());
            $this->assertStringContainsString('html_private_reviewer_exposed', $exception->getMessage());
        }

        $this->assertSame(0, PersonalityPublicContentAssetRevision::query()->count());
        $this->assertSame(0, PersonalityPublicContentAssetRevisionReview::query()->count());
    }

    public function test_html_readback_rejects_stale_rendered_section_content(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        $this->fakeRuntimeHttp($report, omitSectionHtml: true);

        try {
            app(EnneagramPublicAuthorityV224RuntimeReadback::class)->run(
                'pre',
                'canary-00',
                $report,
                'https://api.test',
                'https://frontend.test',
                self::BACKEND_SHA,
                self::FRONTEND_SHA,
            );
            $this->fail('Expected stale rendered section content to fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('html_visible_section_mismatch', $exception->getMessage());
        }
    }

    public function test_pre_readback_rejects_stale_api_and_html_payload_against_current_database(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        $this->fakeRuntimeHttp($report, stalePublicPayload: true);

        try {
            app(EnneagramPublicAuthorityV224RuntimeReadback::class)->run(
                'pre',
                'canary-00',
                $report,
                'https://api.test',
                'https://frontend.test',
                self::BACKEND_SHA,
                self::FRONTEND_SHA,
            );
            $this->fail('Expected stale public API/HTML payload to fail against the current database.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('api_current_public_asset_mismatch', $exception->getMessage());
        }
    }

    public function test_readback_rejects_query_fragment_and_bare_private_routes(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();

        foreach (['/results?token=private', '/orders#private', '/pay?order=private', '/share'] as $privateRoute) {
            $this->fakeRuntimeHttp($report, privateRouteLeak: $privateRoute);
            try {
                app(EnneagramPublicAuthorityV224RuntimeReadback::class)->run(
                    'pre',
                    'canary-00',
                    $report,
                    'https://api.test',
                    'https://frontend.test',
                    self::BACKEND_SHA,
                    self::FRONTEND_SHA,
                );
                $this->fail('Expected private route leak to fail closed: '.$privateRoute);
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('api_private_data_marker_exposed', $exception->getMessage());
                $this->assertStringContainsString('html_private_link_present', $exception->getMessage());
            }
        }
    }

    public function test_readback_requires_visible_faq_answers(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        foreach (PersonalityPublicContentAsset::query()->get() as $asset) {
            $asset->forceFill(['faq_json' => [[
                'question' => 'What does this page explain?',
                'answer' => 'It explains a bounded observation pattern.',
            ]]])->save();
        }
        $this->fakeRuntimeHttp($report, omitFaqAnswerHtml: true);

        try {
            app(EnneagramPublicAuthorityV224RuntimeReadback::class)->run(
                'pre',
                'canary-00',
                $report,
                'https://api.test',
                'https://frontend.test',
                self::BACKEND_SHA,
                self::FRONTEND_SHA,
            );
            $this->fail('Expected a missing visible FAQ answer to fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('html_visible_faq_mismatch', $exception->getMessage());
        }
    }

    public function test_post_readback_requires_complete_visible_evidence(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        foreach (PersonalityPublicContentAsset::query()->get() as $asset) {
            $asset->forceFill(['authority_json' => [
                'sources' => [['id' => 'source-1', 'title' => 'Visible evidence source']],
                'claim_mapping' => ['claim-1' => ['source-1']],
                'visible_evidence_eligible' => true,
            ]])->save();
        }
        $this->fakeRuntimeHttp($report, omitVisibleEvidence: true);

        try {
            app(EnneagramPublicAuthorityV224RuntimeReadback::class)->run(
                'post',
                'canary-00',
                $report,
                'https://api.test',
                'https://frontend.test',
                self::BACKEND_SHA,
                self::FRONTEND_SHA,
            );
            $this->fail('Expected missing post-readback visible evidence to fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('api_visible_evidence_missing', $exception->getMessage());
        }
    }

    public function test_post_readback_rejects_partial_visible_evidence_against_current_authority(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        foreach (PersonalityPublicContentAsset::query()->get() as $asset) {
            $asset->forceFill(['authority_json' => [
                'sources' => [
                    ['id' => 'source-1', 'title' => 'First visible evidence source'],
                    ['id' => 'source-2', 'title' => 'Second visible evidence source'],
                ],
                'claim_mapping' => [
                    ['claim_id' => 'claim-1', 'source_ids' => ['source-1']],
                    ['claim_id' => 'claim-2', 'source_ids' => ['source-2']],
                ],
                'visible_evidence_eligible' => true,
            ]])->save();
        }
        $this->fakeRuntimeHttp($report, partialVisibleEvidence: true);

        try {
            app(EnneagramPublicAuthorityV224RuntimeReadback::class)->run(
                'post',
                'canary-00',
                $report,
                'https://api.test',
                'https://frontend.test',
                self::BACKEND_SHA,
                self::FRONTEND_SHA,
            );
            $this->fail('Expected partial post-readback visible evidence to fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('api_visible_evidence_authority_mismatch', $exception->getMessage());
            $this->assertStringNotContainsString('api_visible_evidence_missing', $exception->getMessage());
            $this->assertStringNotContainsString('html_visible_evidence_mismatch', $exception->getMessage());
        }
    }

    public function test_runtime_readback_rejects_api_redirect(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        $this->fakeRuntimeHttp($report, redirectSurface: 'api');

        try {
            app(EnneagramPublicAuthorityV224RuntimeReadback::class)->run(
                'pre',
                'canary-00',
                $report,
                'https://api.test',
                'https://frontend.test',
                self::BACKEND_SHA,
                self::FRONTEND_SHA,
            );
            $this->fail('Expected API redirect to fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('api_http_302', $exception->getMessage());
        }
    }

    public function test_discoverability_snapshot_rejects_sitemap_redirect(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        $this->fakeRuntimeHttp($report, redirectSurface: 'sitemap');
        try {
            app(EnneagramPublicAuthorityV224RuntimeReadback::class)->snapshot($report, 'https://frontend.test');
            $this->fail('Expected discoverability redirect to fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('sitemap URL-set readback failed with HTTP 302', $exception->getMessage());
        }
    }

    public function test_discoverability_snapshot_rejects_trailing_slash_drift_on_every_surface(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        $discoverabilityState = (object) ['trailing_slash_surface' => null];

        foreach (['sitemap', 'llms', 'llms_full'] as $surface) {
            $discoverabilityState->trailing_slash_surface = $surface;
            $this->fakeRuntimeHttp($report, discoverabilityState: $discoverabilityState);
            try {
                app(EnneagramPublicAuthorityV224RuntimeReadback::class)->snapshot(
                    $report,
                    'https://frontend.test',
                );
                $this->fail('Expected trailing-slash discoverability drift to fail: '.$surface);
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('trailing slash', $exception->getMessage());
            }
        }
    }

    public function test_discoverability_snapshot_hashes_non_enneagram_relative_llms_urls(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        $discoverabilityState = (object) ['additional_llms_url' => '/en/articles/runtime-evidence'];
        $this->fakeRuntimeHttp($report, discoverabilityState: $discoverabilityState);

        $snapshot = app(EnneagramPublicAuthorityV224RuntimeReadback::class)->snapshot(
            $report,
            'https://frontend.test',
        );

        $this->assertSame(116, data_get($snapshot, 'url_sets.sitemap.url_count'));
        $this->assertSame(117, data_get($snapshot, 'url_sets.llms.url_count'));
        $this->assertSame(117, data_get($snapshot, 'url_sets.llms_full.url_count'));
        $this->assertSame(116, data_get($snapshot, 'url_sets.llms.enneagram_url_count'));
        $this->assertNotSame(
            data_get($snapshot, 'url_sets.sitemap.url_set_sha256'),
            data_get($snapshot, 'url_sets.llms.url_set_sha256'),
        );
    }

    public function test_discoverability_snapshot_rejects_non_enneagram_absolute_llms_origin_query_and_fragment_drift(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        $discoverabilityState = (object) ['additional_llms_url' => null];
        $this->fakeRuntimeHttp($report, discoverabilityState: $discoverabilityState);

        foreach ([
            'https://staging.example.com/en/articles/runtime-evidence',
            'https://frontend.test/en/articles/runtime-evidence?preview=1',
            'https://frontend.test/en/articles/runtime-evidence#preview',
            'https://frontend.test/en/articles/runtime-evidence/',
            '/en/articles/runtime-evidence/',
        ] as $invalidUrl) {
            $discoverabilityState->additional_llms_url = $invalidUrl;
            try {
                app(EnneagramPublicAuthorityV224RuntimeReadback::class)->snapshot(
                    $report,
                    'https://frontend.test',
                );
                $this->fail('Expected non-Enneagram LLMS URL drift to fail closed: '.$invalidUrl);
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Discoverability URL', $exception->getMessage());
            }
        }
    }

    public function test_execute_rejects_full_url_set_drift_before_any_runtime_write(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        $register = $this->reviewRegister($report);
        $discoverabilityState = (object) ['additional_url' => null];
        $this->fakeRuntimeHttp($report, discoverabilityState: $discoverabilityState);
        $preReadback = app(EnneagramPublicAuthorityV224RuntimeReadback::class)->run(
            'pre',
            'all',
            $report,
            'https://api.test',
            'https://frontend.test',
            self::BACKEND_SHA,
            self::FRONTEND_SHA,
        );
        $closeout = $this->closeout();
        $preflight = $closeout->preflight(
            $report,
            $this->fingerprintRaw($report),
            $register,
            $this->fingerprintRaw($register),
            self::BACKEND_SHA,
            self::FRONTEND_SHA,
            'https://api.test',
            'https://frontend.test',
            'https://frontend.test/api/content-release/revalidate',
            $preReadback,
            $this->fingerprintRaw($preReadback),
        );
        $discoverabilityState->additional_url = 'https://frontend.test/en/articles/runtime-drift';
        $rollbackPath = '/tmp/enneagram-v224-url-set-drift-'.bin2hex(random_bytes(8)).'.token';

        try {
            $closeout->execute(
                $report,
                $this->fingerprintRaw($report),
                $register,
                $this->fingerprintRaw($register),
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
                preReadback: $preReadback,
                preReadbackSha256: $this->fingerprintRaw($preReadback),
            );
            $this->fail('Expected full discoverability URL-set drift to fail before writes.');
        } catch (\Throwable $throwable) {
            $result = $closeout->failureResult($throwable);
        }

        $this->assertSame('FAIL_CLOSED_NO_WRITES', $result['status']);
        $this->assertSame('pre_execution_url_set_verification', $result['failure_stage']);
        $this->assertFalse($result['writes_committed']);
        $this->assertFileDoesNotExist($rollbackPath);
        $this->assertSame(0, PersonalityPublicContentAssetRevision::query()->count());
        $this->assertSame(0, PersonalityPublicContentAssetRevisionReview::query()->count());
        $this->assertSame(116, PersonalityPublicContentAsset::query()->whereNull('working_revision_id')->count());
    }

    public function test_console_preflight_generates_only_redacted_operator_artifacts(): void
    {
        $this->seedPublishedEstate();
        $registerPath = storage_path('framework/testing/enneagram-v224-private-register.json');
        $outputDirectory = storage_path('framework/testing/enneagram-v224-artifacts-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists(dirname($registerPath));
        File::put($registerPath, json_encode($this->reviewRegister($this->releaseReport()), JSON_THROW_ON_ERROR));
        config()->set('ops.content_release_observability.hmac_revalidation_url', 'https://frontend.test/api/content-release/revalidate');

        try {
            $this->artisan('personality:enneagram-authority-v2-runtime-closeout', [
                '--preflight' => true,
                '--review-register' => $registerPath,
                '--backend-deployed-sha' => self::BACKEND_SHA,
                '--frontend-deployed-sha' => self::FRONTEND_SHA,
                '--api-base-url' => 'https://api.test',
                '--frontend-base-url' => 'https://frontend.test',
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

    public function test_console_rejects_non_origin_api_and_frontend_base_urls_before_any_write(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        $registerPath = storage_path('framework/testing/enneagram-v224-private-register-'.bin2hex(random_bytes(4)).'.json');
        File::ensureDirectoryExists(dirname($registerPath));
        File::put($registerPath, json_encode($this->reviewRegister($report), JSON_THROW_ON_ERROR));
        config()->set('ops.content_release_observability.hmac_revalidation_url', 'https://frontend.test/api/content-release/revalidate');

        $invalidOrigins = [
            ['api-base-url', 'https://api.test/api'],
            ['api-base-url', 'https://api.test?preview=1'],
            ['api-base-url', 'https://api.test#preview'],
            ['frontend-base-url', 'https://frontend.test/personality'],
            ['frontend-base-url', 'https://frontend.test?preview=1'],
            ['frontend-base-url', 'https://frontend.test#preview'],
        ];

        try {
            foreach ($invalidOrigins as [$option, $invalidOrigin]) {
                $arguments = [
                    '--preflight' => true,
                    '--review-register' => $registerPath,
                    '--backend-deployed-sha' => self::BACKEND_SHA,
                    '--frontend-deployed-sha' => self::FRONTEND_SHA,
                    '--api-base-url' => 'https://api.test',
                    '--frontend-base-url' => 'https://frontend.test',
                    '--allow-testing' => true,
                ];
                $arguments['--'.$option] = $invalidOrigin;

                $this->artisan('personality:enneagram-authority-v2-runtime-closeout', $arguments)
                    ->expectsOutputToContain('--'.$option.' must be an exact HTTPS origin without credentials, path, query, or fragment.')
                    ->expectsOutputToContain('status=FAIL_CLOSED_NO_WRITES')
                    ->expectsOutputToContain('writes_committed=0')
                    ->assertFailed();
            }

            $this->assertSame(0, PersonalityPublicContentAssetRevision::query()->count());
            $this->assertSame(0, PersonalityPublicContentAssetRevisionReview::query()->count());
            $this->assertSame(116, PersonalityPublicContentAsset::query()->whereNull('working_revision_id')->count());
        } finally {
            File::delete($registerPath);
        }
    }

    public function test_console_rejects_frontend_revision_probe_on_a_different_origin_before_any_write(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        $registerPath = storage_path('framework/testing/enneagram-v224-private-register-'.bin2hex(random_bytes(4)).'.json');
        File::ensureDirectoryExists(dirname($registerPath));
        File::put($registerPath, json_encode($this->reviewRegister($report), JSON_THROW_ON_ERROR));
        config()->set('ops.content_release_observability.hmac_revalidation_url', 'https://frontend.test/api/content-release/revalidate');

        try {
            $this->artisan('personality:enneagram-authority-v2-runtime-closeout', [
                '--preflight' => true,
                '--review-register' => $registerPath,
                '--backend-deployed-sha' => self::BACKEND_SHA,
                '--frontend-deployed-sha' => self::FRONTEND_SHA,
                '--frontend-revision-url' => 'https://staging.test/revision',
                '--api-base-url' => 'https://api.test',
                '--frontend-base-url' => 'https://frontend.test',
                '--allow-testing' => true,
            ])
                ->expectsOutputToContain('--frontend-revision-url must use the exact --frontend-base-url origin.')
                ->expectsOutputToContain('status=FAIL_CLOSED_NO_WRITES')
                ->expectsOutputToContain('writes_committed=0')
                ->assertFailed();

            $this->assertSame(0, PersonalityPublicContentAssetRevision::query()->count());
            $this->assertSame(0, PersonalityPublicContentAssetRevisionReview::query()->count());
            $this->assertSame(116, PersonalityPublicContentAsset::query()->whereNull('working_revision_id')->count());
            Http::assertNothingSent();
        } finally {
            File::delete($registerPath);
        }
    }

    public function test_console_production_frontend_revision_probe_disables_redirects(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        $registerPath = storage_path('framework/testing/enneagram-v224-private-register-'.bin2hex(random_bytes(4)).'.json');
        $preReadbackPath = storage_path('framework/testing/enneagram-v224-pre-readback-'.bin2hex(random_bytes(4)).'.json');
        $revisionPath = base_path('../REVISION');
        $previousEnvironment = app()->environment();
        $revisionExisted = File::isFile($revisionPath);
        $revisionContents = $revisionExisted ? File::get($revisionPath) : null;
        File::ensureDirectoryExists(dirname($registerPath));
        File::put($registerPath, json_encode($this->reviewRegister($report), JSON_THROW_ON_ERROR));
        File::put($preReadbackPath, '{}');
        File::put($revisionPath, self::BACKEND_SHA);
        config()->set('ops.content_release_observability.hmac_revalidation_url', 'https://frontend.test/api/content-release/revalidate');
        app()->detectEnvironment(static fn (): string => 'production');
        $redirectsDisabled = false;
        Http::fake(function (Request $request, array $options) use (&$redirectsDisabled): \GuzzleHttp\Promise\PromiseInterface|\Illuminate\Http\Client\Response {
            if ($request->url() === 'https://frontend.test/revision') {
                $redirectsDisabled = ($options['allow_redirects'] ?? null) === false;

                return Http::response('', 302, ['Location' => 'https://staging-mirror.test/revision']);
            }

            return Http::response(['ok' => false], 404);
        });

        try {
            $this->artisan('personality:enneagram-authority-v2-runtime-closeout', [
                '--preflight' => true,
                '--review-register' => $registerPath,
                '--pre-readback' => $preReadbackPath,
                '--backend-deployed-sha' => self::BACKEND_SHA,
                '--frontend-deployed-sha' => self::FRONTEND_SHA,
                '--frontend-revision-url' => 'https://frontend.test/revision',
                '--api-base-url' => 'https://api.test',
                '--frontend-base-url' => 'https://frontend.test',
            ])
                ->expectsOutputToContain('Deployed frontend revision endpoint does not match the exact authorization SHA.')
                ->expectsOutputToContain('status=FAIL_CLOSED_NO_WRITES')
                ->expectsOutputToContain('writes_committed=0')
                ->assertFailed();

            $this->assertTrue($redirectsDisabled);
            $this->assertSame(0, PersonalityPublicContentAssetRevision::query()->count());
            $this->assertSame(0, PersonalityPublicContentAssetRevisionReview::query()->count());
            $this->assertSame(116, PersonalityPublicContentAsset::query()->whereNull('working_revision_id')->count());
        } finally {
            app()->detectEnvironment(static fn (): string => $previousEnvironment);
            File::delete([$registerPath, $preReadbackPath]);
            if ($revisionExisted && is_string($revisionContents)) {
                File::put($revisionPath, $revisionContents);
            } else {
                File::delete($revisionPath);
            }
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
            'https://api.test',
            'https://frontend.test',
            'https://frontend.test/api/content-release/revalidate',
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

    public function test_invalid_rollback_output_is_rejected_before_any_runtime_write(): void
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
            'https://api.test',
            'https://frontend.test',
            'https://frontend.test/api/content-release/revalidate',
        );
        $existingPath = '/tmp/enneagram-v224-existing-'.bin2hex(random_bytes(8)).'.token';
        File::put($existingPath, 'operator-owned-sentinel');

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
                    $existingPath,
                    'https://api.test',
                    'https://frontend.test',
                    'https://frontend.test/api/content-release/revalidate',
                    self::REVALIDATION_SECRET,
                );
                $this->fail('Expected an existing rollback output to fail closed.');
            } catch (\Throwable $throwable) {
                $result = $closeout->failureResult($throwable);
            }

            $this->assertSame('FAIL_CLOSED_NO_WRITES', $result['status']);
            $this->assertSame('rollback_token_reservation', $result['failure_stage']);
            $this->assertFalse($result['writes_committed']);
            $this->assertFalse($result['promotion_committed']);
            $this->assertFalse($result['rollback_token_persisted']);
            $this->assertSame('operator-owned-sentinel', File::get($existingPath));
            $this->assertSame(0, PersonalityPublicContentAssetRevision::query()->count());
            $this->assertSame(0, PersonalityPublicContentAssetRevisionReview::query()->count());
            $this->assertSame(116, PersonalityPublicContentAsset::query()->whereNull('working_revision_id')->count());
        } finally {
            File::delete($existingPath);
        }
    }

    public function test_preflight_binds_complete_current_all_target_readback_evidence(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        $register = $this->reviewRegister($report);
        $this->fakeRuntimeHttp($report);
        $readback = app(EnneagramPublicAuthorityV224RuntimeReadback::class)->run(
            'pre',
            'all',
            $report,
            'https://api.test',
            'https://frontend.test',
            self::BACKEND_SHA,
            self::FRONTEND_SHA,
        );
        $valid = $this->closeout()->preflight(
            $report,
            $this->fingerprintRaw($report),
            $register,
            $this->fingerprintRaw($register),
            self::BACKEND_SHA,
            self::FRONTEND_SHA,
            'https://api.test',
            'https://frontend.test',
            'https://frontend.test/api/content-release/revalidate',
            $readback,
            $this->fingerprintRaw($readback),
        );
        $this->assertSame($this->fingerprintRaw($readback), data_get($valid, 'authorization_packet.pre_readback.sha256'));
        $this->assertSame([
            'api_base_origin' => 'https://api.test',
            'frontend_base_origin' => 'https://frontend.test',
        ], data_get($valid, 'authorization_packet.pre_readback.runtime_origins'));
        $this->assertSame([
            'backend_deployed_sha' => self::BACKEND_SHA,
            'frontend_deployed_sha' => self::FRONTEND_SHA,
        ], data_get($valid, 'authorization_packet.pre_readback.deployed_revisions'));

        $invalidArtifacts = [
            'partial batch' => [...$readback, 'batch' => 'canary-00'],
            'stale fingerprint' => [...$readback, 'public_projection_fingerprint' => str_repeat('f', 64)],
            'missing API read' => [...$readback, 'api_read_count' => 115],
            'missing observation' => [...$readback, 'observed_at' => ''],
            'stale observation' => [...$readback, 'observed_at' => now()->subHour()->utc()->toIso8601String()],
            'URL subset drift' => array_replace_recursive($readback, [
                'url_sets' => ['sitemap' => ['enneagram_url_count' => 115]],
            ]),
            'API origin drift' => array_replace_recursive($readback, [
                'runtime_origins' => ['api_base_origin' => 'https://staging-api.test'],
            ]),
            'frontend origin drift' => array_replace_recursive($readback, [
                'runtime_origins' => ['frontend_base_origin' => 'https://staging-frontend.test'],
            ]),
            'missing origins' => array_diff_key($readback, ['runtime_origins' => true]),
            'backend deployed SHA drift' => array_replace_recursive($readback, [
                'deployed_revisions' => ['backend_deployed_sha' => str_repeat('c', 40)],
            ]),
            'frontend deployed SHA drift' => array_replace_recursive($readback, [
                'deployed_revisions' => ['frontend_deployed_sha' => str_repeat('d', 40)],
            ]),
            'missing deployed revisions' => array_diff_key($readback, ['deployed_revisions' => true]),
        ];
        foreach ($invalidArtifacts as $label => $invalid) {
            try {
                $this->closeout()->preflight(
                    $report,
                    $this->fingerprintRaw($report),
                    $register,
                    $this->fingerprintRaw($register),
                    self::BACKEND_SHA,
                    self::FRONTEND_SHA,
                    'https://api.test',
                    'https://frontend.test',
                    'https://frontend.test/api/content-release/revalidate',
                    $invalid,
                    $this->fingerprintRaw($invalid),
                );
                $this->fail('Expected invalid pre-readback to fail: '.$label);
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('pre-readback', $exception->getMessage());
            }
        }
    }

    public function test_console_execute_rejects_invalid_closeout_output_before_any_runtime_write(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        $registerPath = storage_path('framework/testing/enneagram-v224-private-register-'.bin2hex(random_bytes(4)).'.json');
        $existingOutput = storage_path('framework/testing/enneagram-v224-existing-output-'.bin2hex(random_bytes(4)).'.json');
        File::ensureDirectoryExists(dirname($registerPath));
        File::put($registerPath, json_encode($this->reviewRegister($report), JSON_THROW_ON_ERROR));
        File::put($existingOutput, 'operator-owned-closeout-sentinel');
        config()->set('ops.content_release_observability.hmac_revalidation_url', 'https://frontend.test/api/content-release/revalidate');

        try {
            $this->artisan('personality:enneagram-authority-v2-runtime-closeout', [
                '--execute' => true,
                '--review-register' => $registerPath,
                '--backend-deployed-sha' => self::BACKEND_SHA,
                '--frontend-deployed-sha' => self::FRONTEND_SHA,
                '--api-base-url' => 'https://api.test',
                '--frontend-base-url' => 'https://frontend.test',
                '--output' => $existingOutput,
                '--allow-testing' => true,
            ])
                ->expectsOutputToContain('status=FAIL_CLOSED_NO_WRITES')
                ->expectsOutputToContain('writes_committed=0')
                ->assertFailed();

            $this->assertSame('operator-owned-closeout-sentinel', File::get($existingOutput));
            $this->assertSame(0, PersonalityPublicContentAssetRevision::query()->count());
            $this->assertSame(0, PersonalityPublicContentAssetRevisionReview::query()->count());
            $this->assertSame(116, PersonalityPublicContentAsset::query()->whereNull('working_revision_id')->count());
        } finally {
            File::delete([$registerPath, $existingOutput]);
        }
    }

    public function test_console_execute_requires_production_pre_readback_before_revision_http_or_write(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        $registerPath = storage_path('framework/testing/enneagram-v224-private-register-'.bin2hex(random_bytes(4)).'.json');
        File::ensureDirectoryExists(dirname($registerPath));
        File::put($registerPath, json_encode($this->reviewRegister($report), JSON_THROW_ON_ERROR));
        Http::fake();

        try {
            $this->artisan('personality:enneagram-authority-v2-runtime-closeout', [
                '--execute' => true,
                '--review-register' => $registerPath,
                '--backend-deployed-sha' => self::BACKEND_SHA,
                '--frontend-deployed-sha' => self::FRONTEND_SHA,
                '--api-base-url' => 'https://api.test',
                '--frontend-base-url' => 'https://frontend.test',
            ])
                ->expectsOutputToContain('--pre-readback is required for production preflight and execute.')
                ->expectsOutputToContain('status=FAIL_CLOSED_NO_WRITES')
                ->expectsOutputToContain('writes_committed=0')
                ->assertFailed();

            Http::assertNothingSent();
            $this->assertSame(0, PersonalityPublicContentAssetRevision::query()->count());
            $this->assertSame(0, PersonalityPublicContentAssetRevisionReview::query()->count());
            $this->assertSame(116, PersonalityPublicContentAsset::query()->whereNull('working_revision_id')->count());
        } finally {
            File::delete($registerPath);
        }
    }

    public function test_console_execute_persists_the_redacted_closeout_result_to_the_reserved_output(): void
    {
        $this->seedPublishedEstate();
        $report = $this->releaseReport();
        $reportPath = base_path('docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-release-gate-22/release-gate-report.json');
        $register = $this->reviewRegister($report);
        $registerRaw = json_encode($register, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $preflight = $this->closeout()->preflight(
            $report,
            hash('sha256', File::get($reportPath)),
            $register,
            hash('sha256', $registerRaw),
            self::BACKEND_SHA,
            self::FRONTEND_SHA,
            'https://api.test',
            'https://frontend.test',
            'https://frontend.test/api/content-release/revalidate',
        );
        $suffix = bin2hex(random_bytes(4));
        $registerPath = storage_path('framework/testing/enneagram-v224-private-register-'.$suffix.'.json');
        $packetPath = storage_path('framework/testing/enneagram-v224-packet-'.$suffix.'.json');
        $outputPath = storage_path('framework/testing/enneagram-v224-closeout-'.$suffix.'.json');
        $rollbackPath = '/tmp/enneagram-v224-console-rollback-'.$suffix.'.token';
        File::ensureDirectoryExists(dirname($registerPath));
        File::put($registerPath, $registerRaw);
        File::put($packetPath, json_encode(
            $preflight['authorization_packet'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
        config()->set('ops.content_release_observability.hmac_revalidation_url', 'https://frontend.test/api/content-release/revalidate');
        config()->set('ops.content_release_observability.hmac_revalidation_secret', self::REVALIDATION_SECRET);
        $this->fakeRuntimeHttp($report);

        try {
            $this->artisan('personality:enneagram-authority-v2-runtime-closeout', [
                '--execute' => true,
                '--review-register' => $registerPath,
                '--backend-deployed-sha' => self::BACKEND_SHA,
                '--frontend-deployed-sha' => self::FRONTEND_SHA,
                '--authorization-packet' => $packetPath,
                '--confirm-authorization-packet-sha256' => (string) $preflight['authorization_packet_sha256'],
                '--operator-approved' => (string) $preflight['authorization_packet']['authorization_phrase'],
                '--rollback-token-output' => $rollbackPath,
                '--api-base-url' => 'https://api.test',
                '--frontend-base-url' => 'https://frontend.test',
                '--output' => $outputPath,
                '--allow-testing' => true,
            ])
                ->expectsOutputToContain('status=PASS_AUTHORIZED_RUNTIME_CLOSEOUT')
                ->expectsOutputToContain('writes_committed=1')
                ->assertSuccessful();

            $result = json_decode(File::get($outputPath), true, 512, JSON_THROW_ON_ERROR);
            $rollbackToken = trim(File::get($rollbackPath));
            $this->assertTrue($result['ok']);
            $this->assertTrue($result['writes_committed']);
            $this->assertTrue($result['closeout_output_persisted']);
            $this->assertSame(hash('sha256', $rollbackToken), $result['rollback_token_sha256']);
            $this->assertStringNotContainsString($rollbackToken, File::get($outputPath));
            $this->assertFalse($result['rollback_token_output']);
            $this->assertSame(0600, fileperms($outputPath) & 0777);
        } finally {
            File::delete([$registerPath, $packetPath, $outputPath]);
            @unlink($rollbackPath);
        }
    }

    /** @param array<string,mixed> $report */
    private function fakeRuntimeHttp(
        array $report,
        bool $rejectRevalidation = false,
        ?string $discoverabilityUrlOverride = null,
        ?string $canonicalUrlOverride = null,
        ?string $hreflangUrlOverride = null,
        ?string $privateReviewerLeak = null,
        ?string $redirectSurface = null,
        ?object $discoverabilityState = null,
        bool $splitPrivateReviewerHtml = false,
        bool $omitSectionHtml = false,
        ?string $privateRouteLeak = null,
        bool $stalePublicPayload = false,
        bool $omitFaqAnswerHtml = false,
        bool $omitVisibleEvidence = false,
        bool $partialVisibleEvidence = false,
    ): void {
        $paths = array_column($report['asset_records'], 'path');
        $urls = array_map(static fn (string $path): string => 'https://frontend.test'.$path, $paths);
        if ($discoverabilityUrlOverride !== null) {
            $urls[0] = $discoverabilityUrlOverride;
        }
        $baseUrlText = implode("\n", $urls);
        Http::fake(function (Request $request) use ($baseUrlText, $canonicalUrlOverride, $discoverabilityState, $hreflangUrlOverride, $omitFaqAnswerHtml, $omitSectionHtml, $omitVisibleEvidence, $partialVisibleEvidence, $privateReviewerLeak, $privateRouteLeak, $redirectSurface, $rejectRevalidation, $splitPrivateReviewerHtml, $stalePublicPayload): \GuzzleHttp\Promise\PromiseInterface|\Illuminate\Http\Client\Response {
            $url = $request->url();
            $additionalUrl = is_object($discoverabilityState) && is_string($discoverabilityState->additional_url ?? null)
                ? trim($discoverabilityState->additional_url)
                : '';
            $urlText = $additionalUrl === '' ? $baseUrlText : $baseUrlText."\n".$additionalUrl;
            $relativeLlmsUrl = is_object($discoverabilityState) && is_string($discoverabilityState->additional_llms_url ?? null)
                ? trim($discoverabilityState->additional_llms_url)
                : '';
            $llmsUrlText = $relativeLlmsUrl === '' ? $urlText : $urlText."\n".$relativeLlmsUrl;
            $trailingSlashLines = explode("\n", $urlText);
            $trailingSlashLines[0] = rtrim($trailingSlashLines[0], '/').'/';
            $trailingSlashUrlText = implode("\n", $trailingSlashLines);
            $trailingSlashSurface = is_object($discoverabilityState)
                ? (string) ($discoverabilityState->trailing_slash_surface ?? '')
                : '';
            if (($redirectSurface === 'api' && str_starts_with($url, 'https://api.test/api/v0.5/personality-content-assets?'))
                || ($redirectSurface === 'html' && str_starts_with($url, 'https://frontend.test/') && ! in_array($url, [
                    'https://frontend.test/sitemap.xml',
                    'https://frontend.test/llms.txt',
                    'https://frontend.test/llms-full.txt',
                ], true))
                || ($redirectSurface === 'sitemap' && $url === 'https://frontend.test/sitemap.xml')) {
                return Http::response('', 302, ['Location' => 'https://staging-mirror.test/redirected']);
            }
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
                $hreflang = [];
                foreach ((array) $asset->hreflang_json as $language => $url) {
                    if (! in_array(strtolower((string) $language), ['en', 'zh-cn', 'x-default'], true)) {
                        continue;
                    }
                    $hreflang[$language] = 'https://frontend.test'.(string) parse_url((string) $url, PHP_URL_PATH);
                }
                $payloadTitle = $stalePublicPayload ? 'Stale cached Enneagram authority' : (string) $asset->title;
                $payloadSummary = $stalePublicPayload ? 'Stale cached backend-authoritative public content.' : (string) $asset->summary;
                $payloadSections = $stalePublicPayload
                    ? [['key' => 'stale', 'title' => 'Stale section', 'body_md' => 'Stale cached section body.']]
                    : (is_array($asset->content_sections_json) ? $asset->content_sections_json : []);
                $authority = is_array($asset->authority_json) ? $asset->authority_json : [];
                $visibleSources = $omitVisibleEvidence ? [] : array_values((array) ($authority['sources'] ?? []));
                $visibleClaimMapping = $omitVisibleEvidence ? [] : (array) ($authority['claim_mapping'] ?? []);
                if ($partialVisibleEvidence) {
                    $visibleSources = array_slice($visibleSources, 0, 1);
                    $visibleClaimMapping = array_slice(array_values($visibleClaimMapping), 0, 1);
                }

                return Http::response([
                    'ok' => true,
                    'personality_public_content_asset_v1' => [
                        'framework' => 'enneagram',
                        'entity_type' => (string) $asset->entity_type,
                        'code' => (string) $asset->entity_key,
                        'locale' => (string) $asset->locale,
                        'title' => $payloadTitle,
                        'summary' => $payloadSummary,
                        'sections' => $payloadSections,
                        'seo' => $stalePublicPayload
                            ? ['description' => $payloadSummary]
                            : (is_array($asset->seo_json) ? $asset->seo_json : []),
                        'canonical_path' => (string) data_get($asset->canonical_json, 'path'),
                        'hreflang' => $hreflang,
                        'faq' => is_array($asset->faq_json) ? $asset->faq_json : [],
                        'media' => ['hero' => null, 'inline' => [], 'og' => null],
                        'review_state' => $stalePublicPayload ? 'stale_review_state' : (string) $asset->review_state,
                        'source_package' => $stalePublicPayload ? 'stale-public-package' : $asset->source_package,
                        'source_hash' => $stalePublicPayload ? str_repeat('f', 64) : $asset->source_hash,
                    ],
                    'personality_public_content_asset_v2' => [
                        'visible_evidence' => [
                            'sources' => $visibleSources,
                            'claim_mapping' => $visibleClaimMapping,
                            'eligible' => ! $omitVisibleEvidence
                                && ($authority['visible_evidence_eligible'] ?? false) === true
                                && $visibleSources !== []
                                && $visibleClaimMapping !== [],
                        ],
                        'editorial_authority' => [
                            'review_state' => (string) $asset->review_state,
                            'reviewer' => null,
                        ],
                        'operator_supplied_value' => $privateReviewerLeak,
                        'private_route' => $privateRouteLeak,
                    ],
                ], 200, ['X-Fermat-Public-Read-Cache' => 'fresh']);
            }
            if ($url === 'https://frontend.test/sitemap.xml') {
                $sitemapUrlText = $trailingSlashSurface === 'sitemap' ? $trailingSlashUrlText : $urlText;

                return Http::response('<urlset>'.implode('', array_map(
                    static fn (string $line): string => '<url><loc>'.$line.'</loc></url>',
                    explode("\n", $sitemapUrlText),
                )).'</urlset>', 200, ['Content-Type' => 'application/xml']);
            }
            if (in_array($url, ['https://frontend.test/llms.txt', 'https://frontend.test/llms-full.txt'], true)) {
                $surface = $url === 'https://frontend.test/llms-full.txt' ? 'llms_full' : 'llms';

                return Http::response(
                    $trailingSlashSurface === $surface ? $trailingSlashUrlText : $llmsUrlText,
                    200,
                    ['Content-Type' => 'text/plain'],
                );
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
                $renderedTitle = $stalePublicPayload ? 'Stale cached Enneagram authority' : (string) $asset->title;
                $renderedSummary = $stalePublicPayload ? 'Stale cached backend-authoritative public content.' : (string) $asset->summary;
                $renderedSections = $stalePublicPayload
                    ? [['key' => 'stale', 'title' => 'Stale section', 'body_md' => 'Stale cached section body.']]
                    : (is_array($asset->content_sections_json) ? $asset->content_sections_json : []);
                $renderedFaq = is_array($asset->faq_json) ? $asset->faq_json : [];
                $authority = is_array($asset->authority_json) ? $asset->authority_json : [];
                $title = htmlspecialchars($renderedTitle, ENT_QUOTES | ENT_HTML5);
                $summary = htmlspecialchars($renderedSummary, ENT_QUOTES | ENT_HTML5);
                $canonical = htmlspecialchars(
                    $canonicalUrlOverride ?? 'https://frontend.test'.$path,
                    ENT_QUOTES | ENT_HTML5,
                );
                $hreflang = [];
                foreach ((array) $asset->hreflang_json as $language => $url) {
                    if (! in_array(strtolower((string) $language), ['en', 'zh-cn', 'x-default'], true)) {
                        continue;
                    }
                    $href = $language === 'en' && $hreflangUrlOverride !== null
                        ? $hreflangUrlOverride
                        : 'https://frontend.test'.(string) parse_url((string) $url, PHP_URL_PATH);
                    $hreflang[] = '<link rel="alternate" hreflang="'
                        .htmlspecialchars((string) $language, ENT_QUOTES | ENT_HTML5)
                        .'" href="'.htmlspecialchars($href, ENT_QUOTES | ENT_HTML5).'">';
                }

                $privateValue = '';
                if ($privateReviewerLeak !== null) {
                    $escapedPrivateValue = htmlspecialchars($privateReviewerLeak, ENT_QUOTES | ENT_HTML5);
                    if ($splitPrivateReviewerHtml && str_contains($escapedPrivateValue, ' ')) {
                        [$prefix, $suffix] = explode(' ', $escapedPrivateValue, 2);
                        $privateValue = '<aside>'.$prefix.' <span>'.$suffix.'</span></aside>';
                    } else {
                        $privateValue = '<aside>'.$escapedPrivateValue.'</aside>';
                    }
                }
                $privateLink = $privateRouteLeak === null
                    ? ''
                    : '<a href="'.htmlspecialchars($privateRouteLeak, ENT_QUOTES | ENT_HTML5).'">private route</a>';

                $sectionHtml = '<section>stale cached section content</section>';
                if (! $omitSectionHtml) {
                    $sectionHtml = '';
                    foreach ($renderedSections as $section) {
                        if (! is_array($section)) {
                            continue;
                        }
                        $sectionTitle = trim((string) ($section['title'] ?? $section['heading'] ?? ''));
                        $sectionBody = trim((string) ($section['body_md'] ?? $section['body'] ?? ''));
                        $sectionHtml .= '<section>';
                        if ($sectionTitle !== '') {
                            $sectionHtml .= '<h2>'.htmlspecialchars($sectionTitle, ENT_QUOTES | ENT_HTML5).'</h2>';
                        }
                        if ($sectionBody !== '') {
                            $sectionHtml .= (string) Str::markdown($sectionBody);
                        }
                        $sectionHtml .= '</section>';
                    }
                }
                $faqHtml = '';
                foreach ($renderedFaq as $faq) {
                    if (! is_array($faq)) {
                        continue;
                    }
                    $question = trim((string) ($faq['question'] ?? $faq['q'] ?? ''));
                    $answer = trim((string) ($faq['answer'] ?? $faq['a'] ?? ''));
                    $faqHtml .= '<section class="faq"><h2>'.htmlspecialchars($question, ENT_QUOTES | ENT_HTML5).'</h2>';
                    if (! $omitFaqAnswerHtml) {
                        $faqHtml .= (string) Str::markdown($answer);
                    }
                    $faqHtml .= '</section>';
                }
                $evidenceHtml = '';
                if (! $omitVisibleEvidence) {
                    foreach ((array) ($authority['sources'] ?? []) as $source) {
                        if (is_array($source) && trim((string) ($source['title'] ?? '')) !== '') {
                            $evidenceHtml .= '<cite>'.htmlspecialchars((string) $source['title'], ENT_QUOTES | ENT_HTML5).'</cite>';
                        }
                    }
                }

                return Http::response('<!doctype html><html><head><title>'.$title.'</title>'
                    .'<meta name="description" content="'.$summary.'">'
                    .'<link rel="canonical" href="'.$canonical.'">'
                    .implode('', $hreflang)
                    .'</head><body><h1>'.$title.'</h1><main>'.$summary.$sectionHtml.$faqHtml.$evidenceHtml.$privateLink.'</main>'.$privateValue.'</body></html>');
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

    private function seedNonReleaseAsset(): PersonalityPublicContentAsset
    {
        return PersonalityPublicContentAsset::query()->create([
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_CORE_TYPE,
            'entity_key' => 'type-draft-extra',
            'slug' => 'enneagram/type-draft-extra',
            'locale' => 'en',
            'title' => 'Non-release Enneagram draft',
            'summary' => 'This CMS draft is intentionally outside the authorized 116-page release set.',
            'content_sections_json' => [],
            'seo_json' => [],
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_NOFOLLOW,
            'canonical_json' => [],
            'hreflang_json' => [],
            'faq_json' => [],
            'media_json' => ['hero' => null, 'inline' => [], 'og' => null],
            'schema_json' => [],
            'method_boundary_json' => [],
            'evidence_notes_json' => [],
            'authority_json' => ['reviewer' => null],
            'internal_links_json' => [],
            'is_public' => false,
            'index_eligible' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_DRAFT,
            'review_state' => 'draft',
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
            'source_package' => 'cms-draft-outside-authorized-release',
            'source_hash' => hash('sha256', 'non-release-enneagram-draft'),
        ]);
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
