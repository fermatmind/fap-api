<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\Pdf\ResultPagePdfTokenService;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AttemptPublicReportPdfParityTest extends TestCase
{
    use RefreshDatabase;

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder)->run();
    }

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();

        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function createAttempt(string $attemptId, string $scaleCode, string $anonId): void
    {
        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.3',
            'content_package_version' => 'attempt-v1',
            'scoring_spec_version' => 'attempt-score-v1',
        ]);
    }

    private function createResult(string $attemptId): void
    {
        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => [
                'EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'SN' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'TF' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'JP' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'AT' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
            ],
            'scores_pct' => [
                'EI' => 50,
                'SN' => 50,
                'TF' => 50,
                'JP' => 50,
                'AT' => 50,
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'clear',
                'JP' => 'clear',
                'AT' => 'clear',
            ],
            'content_package_version' => 'result-v1',
            'result_json' => [
                'raw_score' => 0,
                'final_score' => 0,
                'breakdown_json' => [],
                'type_code' => 'INTJ-A',
                'axis_scores_json' => [
                    'scores_pct' => [
                        'EI' => 50,
                        'SN' => 50,
                        'TF' => 50,
                        'JP' => 50,
                        'AT' => 50,
                    ],
                    'axis_states' => [
                        'EI' => 'clear',
                        'SN' => 'clear',
                        'TF' => 'clear',
                        'JP' => 'clear',
                        'AT' => 'clear',
                    ],
                ],
            ],
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.3',
            'scoring_spec_version' => 'result-score-v1',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);
    }

    public function test_public_mbti_report_pdf_uses_same_attempt_resolution_as_json_report(): void
    {
        $this->seedScales();
        config()->set('fap.features.report_snapshot_strict_v2', false);
        Storage::fake('local');

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_mbti_pdf_parity';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, 'MBTI', $anonId);
        $this->createResult($attemptId);

        $headers = [
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ];

        $json = $this->withHeaders($headers)->getJson("/api/v0.3/attempts/{$attemptId}/report");
        $json->assertStatus(200);
        $json->assertJsonPath('ok', true);
        $json->assertJsonPath('locked', true);

        Storage::disk('local')->put(
            "artifacts/pdf/MBTI/{$attemptId}/nohash/report_free.pdf",
            'OLD_ONE_PAGE_MBTI_PDF_CACHE'
        );

        $pdf = $this->withHeaders($headers)->get("/api/v0.3/attempts/{$attemptId}/report.pdf");
        $pdf->assertStatus(200);
        $pdf->assertHeader('Content-Type', 'application/pdf');
        $pdf->assertHeader('X-Report-Scale', 'MBTI');
        $pdf->assertHeader('X-Report-Locked', 'true');
        $pdf->assertHeader('X-Pdf-Surface-Version', 'mbti.pdf_surface.v4');

        $pdfBinary = (string) $pdf->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdfBinary);
        $this->assertStringNotContainsString('OLD_ONE_PAGE_MBTI_PDF_CACHE', $pdfBinary);
        $this->assertStringStartsWith(
            'attachment; filename="fermatmind-mbti-report-',
            (string) $pdf->headers->get('Content-Disposition')
        );
        $this->assertStringEndsWith('.pdf"', (string) $pdf->headers->get('Content-Disposition'));
        Storage::disk('local')->assertExists(
            "artifacts/pdf/MBTI/{$attemptId}/nohash-mbti.pdf_surface.v4/report_free.pdf"
        );
    }

    public function test_public_mbti_report_pdf_prefers_gotenberg_result_print_route_when_enabled(): void
    {
        $this->seedScales();
        config()->set('fap.features.report_snapshot_strict_v2', false);
        config()->set('gotenberg.enabled', true);
        config()->set('gotenberg.base_url', 'http://gotenberg:3000');
        config()->set('gotenberg.result_print_base_url', 'http://frontend:3000');
        Storage::fake('local');

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_mbti_pdf_gotenberg';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, 'MBTI', $anonId);
        $this->createResult($attemptId);

        $gotenbergPdf = implode("\n", [
            '%PDF-1.4',
            '<< /Producer (Chromium) >>',
            'MBTI 完整人格报告',
            'Personality Traits',
            'Your Career Path',
            'Your Personal Growth',
            'Your Relationships',
            '%%EOF',
        ]);

        Http::fake([
            'gotenberg:3000/forms/chromium/convert/url' => Http::response($gotenbergPdf, 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $headers = [
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ];

        $pdf = $this->withHeaders($headers)->get("/api/v0.3/attempts/{$attemptId}/report.pdf");

        $pdf->assertStatus(200);
        $pdf->assertHeader('Content-Type', 'application/pdf');
        $pdf->assertHeader('X-Pdf-Surface-Version', 'mbti.pdf_surface.v4');

        $pdfBinary = (string) $pdf->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdfBinary);
        $this->assertStringContainsString('/Producer (Chromium)', $pdfBinary);
        $this->assertStringNotContainsString('mPDF', $pdfBinary);
        $this->assertStringContainsString('Personality Traits', $pdfBinary);
        $this->assertStringContainsString('Your Career Path', $pdfBinary);
        $this->assertStringContainsString('Your Personal Growth', $pdfBinary);
        $this->assertStringContainsString('Your Relationships', $pdfBinary);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'http://gotenberg:3000/forms/chromium/convert/url';
        });

        Storage::disk('local')->assertExists(
            "artifacts/pdf/MBTI/{$attemptId}/nohash-mbti.pdf_surface.v4/report_free.pdf"
        );
    }

    public function test_public_mbti_report_pdf_still_requires_matching_attempt_subject(): void
    {
        $this->seedScales();
        config()->set('fap.features.report_snapshot_strict_v2', false);
        Storage::fake('local');

        $attemptId = (string) Str::uuid();
        $ownerAnonId = 'anon_mbti_pdf_owner';
        $viewerAnonId = 'anon_mbti_pdf_viewer';
        $token = $this->issueAnonToken($viewerAnonId);
        $this->createAttempt($attemptId, 'MBTI', $ownerAnonId);
        $this->createResult($attemptId);

        $headers = [
            'X-Anon-Id' => $viewerAnonId,
            'Authorization' => 'Bearer '.$token,
        ];

        $json = $this->withHeaders($headers)->getJson("/api/v0.3/attempts/{$attemptId}/report");
        $json->assertStatus(404);
        $json->assertJsonPath('error_code', 'ATTEMPT_NOT_FOUND');
        $json->assertJsonPath('message', 'attempt not found.');

        $pdf = $this->withHeaders($headers)->get("/api/v0.3/attempts/{$attemptId}/report.pdf");
        $pdf->assertStatus(404);
    }

    public function test_public_mbti_result_page_pdf_uses_strict_gotenberg_surface(): void
    {
        $this->seedScales();
        config()->set('fap.features.report_snapshot_strict_v2', false);
        config()->set('gotenberg.enabled', true);
        config()->set('gotenberg.base_url', 'http://gotenberg:3000');
        config()->set('gotenberg.result_print_base_url', 'http://frontend:3000');
        config()->set('gotenberg.result_print_path_template', '/{locale}/result/{attempt_id}');
        config()->set('gotenberg.result_print_token_secret', 'test-result-print-secret');
        Storage::fake('local');

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_mbti_result_page_pdf';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, 'MBTI', $anonId);
        $this->createResult($attemptId);

        $gotenbergPdf = implode("\n", [
            '%PDF-1.4',
            '<< /Producer (Chromium) >>',
            'Personality Traits',
            'Your Career Path',
            'Your Personal Growth',
            'Your Relationships',
            '%%EOF',
        ]);

        Http::fake([
            'gotenberg:3000/forms/chromium/convert/url' => Http::response($gotenbergPdf, 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $headers = [
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ];

        $pdf = $this->withHeaders($headers)->get("/api/v0.3/attempts/{$attemptId}/result-page.pdf");

        $pdf->assertStatus(200);
        $pdf->assertHeader('Content-Type', 'application/pdf');
        $pdf->assertHeader('X-Report-Pdf-Engine', 'gotenberg_chromium');
        $pdf->assertHeader('X-Pdf-Surface', 'mbti_result_page_snapshot');
        $pdf->assertHeader('X-Pdf-Surface-Version', 'mbti.result_page_snapshot.v4');
        $pdf->assertHeader('X-Pdf-Render-Version', 'mbti.snapshot.print_layout.v1');
        $pdf->assertHeader('X-Pdf-Print-Asset-Hash', 'sha256:f8b8f8a162f469777924fb60966fac19c29ea4fdad1323b5f9ae1a19286a7614');
        $pdf->assertHeader('X-Pdf-Artifact-Cache', 'MISS');
        $pdf->assertHeader('X-Legacy-Mpdf-Fallback', 'false');
        $this->assertNotSame('', (string) $pdf->headers->get('X-Gotenberg-Trace'));

        $pdfBinary = (string) $pdf->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdfBinary);
        $this->assertStringContainsString('/Producer (Chromium)', $pdfBinary);
        $this->assertStringNotContainsString('mPDF', $pdfBinary);
        $this->assertStringContainsString('Personality Traits', $pdfBinary);
        $this->assertStringContainsString('Your Career Path', $pdfBinary);
        $this->assertStringContainsString('Your Personal Growth', $pdfBinary);
        $this->assertStringContainsString('Your Relationships', $pdfBinary);

        $recordedGotenbergRequest = Http::recorded(
            fn ($request): bool => $request->method() === 'POST'
                && $request->url() === 'http://gotenberg:3000/forms/chromium/convert/url'
        )->first();
        $this->assertNotNull($recordedGotenbergRequest, 'Expected a Gotenberg URL conversion request.');

        [$request] = $recordedGotenbergRequest;
        $traceHeader = $request->header('Gotenberg-Trace');
        $trace = is_array($traceHeader) ? (string) ($traceHeader[0] ?? '') : (string) $traceHeader;
        $payload = collect($request->data())
            ->mapWithKeys(fn (array $part): array => [(string) ($part['name'] ?? '') => (string) ($part['contents'] ?? '')])
            ->all();
        $printUrl = (string) ($payload['url'] ?? '');

        $this->assertStringStartsWith("mbti-result-page-pdf-{$attemptId}-", $trace);
        $this->assertSame("/zh/result/{$attemptId}/print", (string) parse_url($printUrl, PHP_URL_PATH));
        $this->assertStringContainsString('pdf=1', $printUrl);
        $this->assertStringContainsString('surface=mbti.result_page_snapshot.v4', $printUrl);
        $this->assertStringContainsString('pdf_token=', $printUrl);
        $this->assertStringContainsString('result_access_token=', $printUrl);
        $this->assertSame('window.__FERMAT_PDF_READY__ === true', $payload['waitForExpression'] ?? null);
        $this->assertSame('true', $payload['skipNetworkIdleEvent'] ?? null);
        $this->assertSame('true', $payload['skipNetworkAlmostIdleEvent'] ?? null);
        $this->assertSame('true', $payload['printBackground'] ?? null);
        $this->assertSame('true', $payload['preferCssPageSize'] ?? null);
        $this->assertSame('print', $payload['emulatedMediaType'] ?? null);
        $this->assertSame('[400,599]', $payload['failOnHttpStatusCodes'] ?? null);
        $this->assertArrayNotHasKey('failOnConsoleExceptions', $payload);

        Storage::disk('local')->assertExists(
            "artifacts/pdf/MBTI/{$attemptId}/nohash-mbti.result_page_snapshot.v4-mbti.snapshot.print_layout.v1-sha256_f8b8f8a162f469777924fb60966fac19c29ea4fdad1323b5f9ae1a19286a7614-gotenberg_chromium-zh-locked-free/report_free.pdf"
        );
    }

    public function test_mbti_result_page_pdf_token_grants_only_matching_private_print_result_read(): void
    {
        $this->seedScales();
        config()->set('fap.features.report_snapshot_strict_v2', false);
        config()->set('gotenberg.result_print_token_secret', 'test-result-print-secret');

        $attemptId = (string) Str::uuid();
        $otherAttemptId = (string) Str::uuid();
        $this->createAttempt($attemptId, 'MBTI', 'anon_pdf_print_token');
        $this->createResult($attemptId);
        $this->createAttempt($otherAttemptId, 'MBTI', 'anon_pdf_print_token_other');
        $this->createResult($otherAttemptId);

        $attempt = Attempt::query()->where('id', $attemptId)->firstOrFail();
        $token = app(ResultPagePdfTokenService::class)->issueForMbtiResultPageExport($attempt, [
            'locked' => false,
            'variant' => 'full',
        ], 'zh');

        $result = $this->withHeaders([
            'X-Result-Access-Token' => $token,
        ])->get("/api/v0.3/attempts/{$attemptId}/result?locale=zh-CN");

        $result->assertStatus(200);
        $this->assertSame('INTJ-A', $result->json('type_code'));

        $reportAccess = $this->withHeaders([
            'X-Result-Access-Token' => $token,
        ])->get("/api/v0.3/attempts/{$attemptId}/report-access?locale=zh-CN");

        $reportAccess->assertStatus(200)
            ->assertJsonPath('access_state', 'ready')
            ->assertJsonPath('report_state', 'ready')
            ->assertJsonPath('pdf_state', 'ready')
            ->assertJsonPath('reason_code', 'result_page_pdf_token')
            ->assertJsonPath('access_source', 'result_page_pdf_export_token')
            ->assertJsonPath('payload.result_page_pdf_export', true);

        $wrongAttempt = $this->withHeaders([
            'X-Result-Access-Token' => $token,
        ])->get("/api/v0.3/attempts/{$otherAttemptId}/result?locale=zh-CN");

        $wrongAttempt->assertStatus(404);

        $wrongAttemptAccess = $this->withHeaders([
            'X-Result-Access-Token' => $token,
        ])->get("/api/v0.3/attempts/{$otherAttemptId}/report-access?locale=zh-CN");

        $wrongAttemptAccess->assertStatus(404);
    }

    public function test_mbti_result_page_pdf_locked_token_does_not_promote_report_access_to_full(): void
    {
        $this->seedScales();
        config()->set('fap.features.report_snapshot_strict_v2', false);
        config()->set('gotenberg.result_print_token_secret', 'test-result-print-secret');

        $attemptId = (string) Str::uuid();
        $this->createAttempt($attemptId, 'MBTI', 'anon_pdf_print_locked_token');
        $this->createResult($attemptId);

        DB::table('unified_access_projections')->insert([
            'attempt_id' => $attemptId,
            'access_state' => 'ready',
            'report_state' => 'ready',
            'pdf_state' => 'ready',
            'reason_code' => 'entitlement_granted',
            'projection_version' => 1,
            'actions_json' => json_encode(['report' => true, 'pdf' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'payload_json' => json_encode([
                'access_level' => 'full',
                'variant' => 'full',
                'unlock_stage' => 'full',
                'has_active_grant' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'produced_at' => now(),
            'refreshed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $attempt = Attempt::query()->where('id', $attemptId)->firstOrFail();
        $token = app(ResultPagePdfTokenService::class)->issueForMbtiResultPageExport($attempt, [
            'locked' => true,
            'variant' => 'free',
        ], 'zh');

        $response = $this->withHeaders([
            'X-Result-Access-Token' => $token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report-access?locale=zh-CN");

        $response->assertOk()
            ->assertJsonPath('access_state', 'locked')
            ->assertJsonPath('report_state', 'ready')
            ->assertJsonPath('pdf_state', 'missing')
            ->assertJsonPath('reason_code', 'result_page_pdf_token_locked')
            ->assertJsonPath('actions.page_href', "/result/{$attemptId}")
            ->assertJsonPath('actions.pdf_href', null)
            ->assertJsonPath('access_source', 'result_page_pdf_export_token')
            ->assertJsonPath('paywall_suppressed', false)
            ->assertJsonPath('payload.locked', true)
            ->assertJsonPath('payload.access_level', 'free')
            ->assertJsonPath('payload.variant', 'free')
            ->assertJsonPath('payload.unlock_stage', 'locked')
            ->assertJsonPath('payload.result_page_pdf_export', true)
            ->assertJsonPath('payload.result_page_pdf_token_entitlement', 'locked');
    }

    public function test_mbti_result_page_pdf_token_is_rejected_after_attempt_owner_changes(): void
    {
        $this->seedScales();
        config()->set('gotenberg.result_print_token_secret', 'test-result-print-secret');

        $attemptId = (string) Str::uuid();
        $this->createAttempt($attemptId, 'MBTI', 'anon_pdf_print_original_owner');
        $this->createResult($attemptId);

        $attempt = Attempt::query()->where('id', $attemptId)->firstOrFail();
        $token = app(ResultPagePdfTokenService::class)->issueForMbtiResultPageExport($attempt, [
            'locked' => false,
            'variant' => 'full',
        ], 'zh');

        Attempt::query()
            ->where('id', $attemptId)
            ->update(['anon_id' => 'anon_pdf_print_new_owner']);

        $response = $this->withHeaders([
            'X-Result-Access-Token' => $token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report-access?locale=zh-CN");

        $response->assertStatus(404);
        $response->assertJsonPath('error_code', 'ATTEMPT_NOT_FOUND');
    }

    public function test_public_mbti_result_page_pdf_does_not_fallback_to_mpdf_when_gotenberg_fails(): void
    {
        $this->seedScales();
        config()->set('fap.features.report_snapshot_strict_v2', false);
        config()->set('gotenberg.enabled', true);
        config()->set('gotenberg.base_url', 'http://gotenberg:3000');
        config()->set('gotenberg.result_print_base_url', 'http://frontend:3000');
        Storage::fake('local');

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_mbti_result_page_pdf_fail';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, 'MBTI', $anonId);
        $this->createResult($attemptId);

        Http::fake([
            'gotenberg:3000/forms/chromium/convert/url' => static fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30000 milliseconds'),
        ]);

        $pdf = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
            'Origin' => 'https://fermatmind.com',
            'X-Request-Id' => 'pdf-export-request-1',
        ])->get("/api/v0.3/attempts/{$attemptId}/result-page.pdf");

        $pdf->assertStatus(503);
        $pdf->assertHeader('Access-Control-Allow-Origin', 'https://fermatmind.com');
        $cacheControl = (string) $pdf->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $pdf->assertHeader('X-Report-Scale', 'MBTI');
        $pdf->assertHeader('X-Report-Pdf-Engine', 'gotenberg_chromium');
        $pdf->assertHeader('X-Pdf-Surface', 'mbti_result_page_snapshot');
        $pdf->assertHeader('X-Pdf-Surface-Version', 'mbti.result_page_snapshot.v4');
        $pdf->assertHeader('X-Pdf-Render-Version', 'mbti.snapshot.print_layout.v1');
        $pdf->assertHeader('X-Pdf-Print-Asset-Hash', 'sha256:f8b8f8a162f469777924fb60966fac19c29ea4fdad1323b5f9ae1a19286a7614');
        $pdf->assertHeader('X-Legacy-Mpdf-Fallback', 'false');
        $pdf->assertHeader('X-Pdf-Error-Stage', 'gotenberg.convert_url');
        $this->assertStringContainsString('X-Gotenberg-Trace', (string) $pdf->headers->get('Access-Control-Expose-Headers'));
        $this->assertNotSame('', (string) $pdf->headers->get('X-Gotenberg-Trace'));
        $pdf->assertJsonPath('ok', false);
        $pdf->assertJsonPath('code', 'PDF_GENERATION_TIMEOUT');
        $pdf->assertJsonPath('error_code', 'PDF_GENERATION_TIMEOUT');
        $pdf->assertJsonPath('engine', 'gotenberg_chromium');
        $pdf->assertJsonPath('surface', 'mbti.result_page_snapshot.v4');
        $pdf->assertJsonPath('message', 'PDF generation timed out');
        $pdf->assertJsonPath('request_id', 'pdf-export-request-1');
        $this->assertSame($pdf->headers->get('X-Gotenberg-Trace'), $pdf->json('trace'));
        Storage::disk('local')->assertMissing(
            "artifacts/pdf/MBTI/{$attemptId}/nohash-mbti.result_page_snapshot.v4-mbti.snapshot.print_layout.v1-sha256_f8b8f8a162f469777924fb60966fac19c29ea4fdad1323b5f9ae1a19286a7614-gotenberg_chromium-zh-locked-free/report_free.pdf"
        );
    }

    public function test_public_mbti_result_page_pdf_does_not_hit_legacy_mpdf_surface_cache(): void
    {
        $this->seedScales();
        config()->set('fap.features.report_snapshot_strict_v2', false);
        config()->set('gotenberg.enabled', true);
        config()->set('gotenberg.base_url', 'http://gotenberg:3000');
        config()->set('gotenberg.result_print_base_url', 'http://frontend:3000');
        Storage::fake('local');

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_mbti_result_page_pdf_cache';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, 'MBTI', $anonId);
        $this->createResult($attemptId);

        Storage::disk('local')->put(
            "artifacts/pdf/MBTI/{$attemptId}/nohash-mbti.pdf_surface.v4/report_free.pdf",
            '%PDF-1.4 old mPDF artifact'
        );
        Storage::disk('local')->put(
            "artifacts/pdf/MBTI/{$attemptId}/nohash-mbti.result_page_export.v1-gotenberg_chromium-zh-locked-free/report_free.pdf",
            '%PDF-1.4 old result-page export artifact'
        );
        Storage::disk('local')->put(
            "artifacts/pdf/MBTI/{$attemptId}/nohash-mbti.result_page_export.v2-gotenberg_chromium-zh-locked-free/report_free.pdf",
            '%PDF-1.4 old v2 result-page export artifact'
        );
        Storage::disk('local')->put(
            "artifacts/pdf/MBTI/{$attemptId}/nohash-mbti.result_page_snapshot.v3-gotenberg_chromium-zh-locked-free/report_free.pdf",
            '%PDF-1.4 old v3 summary snapshot artifact'
        );
        Storage::disk('local')->put(
            "artifacts/pdf/MBTI/{$attemptId}/nohash-mbti.result_page_snapshot.v4-gotenberg_chromium-zh-locked-free/report_free.pdf",
            '%PDF-1.4 old v4 snapshot artifact without render version'
        );

        Http::fake([
            'gotenberg:3000/forms/chromium/convert/url' => Http::response('%PDF-1.4 new chromium export', 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $pdf = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->get("/api/v0.3/attempts/{$attemptId}/result-page.pdf");

        $pdf->assertStatus(200);
        $pdf->assertHeader('X-Pdf-Artifact-Cache', 'MISS');
        $this->assertStringContainsString('new chromium export', (string) $pdf->getContent());
        $this->assertStringNotContainsString('old mPDF artifact', (string) $pdf->getContent());
        $this->assertStringNotContainsString('old result-page export artifact', (string) $pdf->getContent());
        $this->assertStringNotContainsString('old v2 result-page export artifact', (string) $pdf->getContent());
        $this->assertStringNotContainsString('old v3 summary snapshot artifact', (string) $pdf->getContent());
        $this->assertStringNotContainsString('old v4 snapshot artifact without render version', (string) $pdf->getContent());
    }

    public function test_public_mbti_result_page_pdf_cache_hit_preserves_engine_headers(): void
    {
        $this->seedScales();
        config()->set('fap.features.report_snapshot_strict_v2', false);
        config()->set('gotenberg.enabled', true);
        config()->set('gotenberg.base_url', 'http://gotenberg:3000');
        config()->set('gotenberg.result_print_base_url', 'http://frontend:3000');
        Storage::fake('local');

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_mbti_result_page_pdf_hit';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, 'MBTI', $anonId);
        $this->createResult($attemptId);

        Storage::disk('local')->put(
            "artifacts/pdf/MBTI/{$attemptId}/nohash-mbti.result_page_snapshot.v4-mbti.snapshot.print_layout.v1-sha256_f8b8f8a162f469777924fb60966fac19c29ea4fdad1323b5f9ae1a19286a7614-gotenberg_chromium-zh-locked-free/report_free.pdf",
            '%PDF-1.4 cached chromium export'
        );

        Http::fake();

        $pdf = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->get("/api/v0.3/attempts/{$attemptId}/result-page.pdf");

        $pdf->assertStatus(200);
        $pdf->assertHeader('X-Report-Pdf-Engine', 'gotenberg_chromium');
        $pdf->assertHeader('X-Pdf-Surface', 'mbti_result_page_snapshot');
        $pdf->assertHeader('X-Pdf-Surface-Version', 'mbti.result_page_snapshot.v4');
        $pdf->assertHeader('X-Pdf-Render-Version', 'mbti.snapshot.print_layout.v1');
        $pdf->assertHeader('X-Pdf-Print-Asset-Hash', 'sha256:f8b8f8a162f469777924fb60966fac19c29ea4fdad1323b5f9ae1a19286a7614');
        $pdf->assertHeader('X-Pdf-Artifact-Cache', 'HIT');
        $pdf->assertHeader('X-Legacy-Mpdf-Fallback', 'false');
        $this->assertStringContainsString('cached chromium export', (string) $pdf->getContent());
        Http::assertNothingSent();
    }

    public function test_public_mbti_result_page_pdf_print_asset_hash_change_misses_old_artifact(): void
    {
        $this->seedScales();
        config()->set('fap.features.report_snapshot_strict_v2', false);
        config()->set('gotenberg.enabled', true);
        config()->set('gotenberg.base_url', 'http://gotenberg:3000');
        config()->set('gotenberg.result_print_base_url', 'http://frontend:3000');
        config()->set('gotenberg.result_print_asset_hash', 'sha256:1111111111111111111111111111111111111111111111111111111111111111');
        Storage::fake('local');

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_mbti_result_page_pdf_asset_hash';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, 'MBTI', $anonId);
        $this->createResult($attemptId);

        Storage::disk('local')->put(
            "artifacts/pdf/MBTI/{$attemptId}/nohash-mbti.result_page_snapshot.v4-mbti.snapshot.print_layout.v1-sha256_f8b8f8a162f469777924fb60966fac19c29ea4fdad1323b5f9ae1a19286a7614-gotenberg_chromium-zh-locked-free/report_free.pdf",
            '%PDF-1.4 cached old print asset hash export'
        );

        Http::fake([
            'gotenberg:3000/forms/chromium/convert/url' => Http::response('%PDF-1.4 new print asset hash export', 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $pdf = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->get("/api/v0.3/attempts/{$attemptId}/result-page.pdf");

        $pdf->assertStatus(200);
        $pdf->assertHeader('X-Pdf-Print-Asset-Hash', 'sha256:1111111111111111111111111111111111111111111111111111111111111111');
        $pdf->assertHeader('X-Pdf-Artifact-Cache', 'MISS');
        $this->assertStringContainsString('new print asset hash export', (string) $pdf->getContent());
        $this->assertStringNotContainsString('cached old print asset hash export', (string) $pdf->getContent());
        Storage::disk('local')->assertExists(
            "artifacts/pdf/MBTI/{$attemptId}/nohash-mbti.result_page_snapshot.v4-mbti.snapshot.print_layout.v1-sha256_1111111111111111111111111111111111111111111111111111111111111111-gotenberg_chromium-zh-locked-free/report_free.pdf"
        );
    }
}
