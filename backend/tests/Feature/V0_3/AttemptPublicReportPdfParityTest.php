<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\Pdf\ResultPagePdfTokenService;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $pdf->assertHeader('X-Pdf-Surface', 'mbti_result_page_export');
        $pdf->assertHeader('X-Pdf-Surface-Version', 'mbti.result_page_export.v1');
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

        Http::assertSent(function ($request) use ($attemptId): bool {
            $body = $request->body();

            return $request->method() === 'POST'
                && $request->url() === 'http://gotenberg:3000/forms/chromium/convert/url'
                && str_contains($body, "/zh/result/{$attemptId}")
                && str_contains($body, 'pdf=1')
                && str_contains($body, 'surface=mbti.result_page_export.v1')
                && str_contains($body, 'pdf_token=')
                && str_contains($body, 'result_access_token=')
                && str_contains($body, 'waitForExpression')
                && str_contains($body, 'window.__FERMAT_PDF_READY__ === true')
                && str_contains($body, 'failOnHttpStatusCodes')
                && str_contains($body, '[400,401,403,404,500,502,503]')
                && ! str_contains($body, "\r\n400,401,403,404,500,502,503\r\n");
        });

        Storage::disk('local')->assertExists(
            "artifacts/pdf/MBTI/{$attemptId}/nohash-mbti.result_page_export.v1-gotenberg_chromium-zh-locked-free/report_free.pdf"
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
            'gotenberg:3000/forms/chromium/convert/url' => Http::response('upstream unavailable', 503),
        ]);

        $pdf = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->get("/api/v0.3/attempts/{$attemptId}/result-page.pdf");

        $pdf->assertStatus(503);
        Storage::disk('local')->assertMissing(
            "artifacts/pdf/MBTI/{$attemptId}/nohash-mbti.result_page_export.v1-gotenberg_chromium-zh-locked-free/report_free.pdf"
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
            "artifacts/pdf/MBTI/{$attemptId}/nohash-mbti.result_page_export.v1-gotenberg_chromium-zh-locked-free/report_free.pdf",
            '%PDF-1.4 cached chromium export'
        );

        Http::fake();

        $pdf = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->get("/api/v0.3/attempts/{$attemptId}/result-page.pdf");

        $pdf->assertStatus(200);
        $pdf->assertHeader('X-Report-Pdf-Engine', 'gotenberg_chromium');
        $pdf->assertHeader('X-Pdf-Surface', 'mbti_result_page_export');
        $pdf->assertHeader('X-Pdf-Surface-Version', 'mbti.result_page_export.v1');
        $pdf->assertHeader('X-Pdf-Artifact-Cache', 'HIT');
        $pdf->assertHeader('X-Legacy-Mpdf-Fallback', 'false');
        $this->assertStringContainsString('cached chromium export', (string) $pdf->getContent());
        Http::assertNothingSent();
    }
}
