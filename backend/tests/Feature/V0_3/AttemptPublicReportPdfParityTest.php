<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\Pdf\ReportPdfDocumentService;
use App\Services\Report\Pdf\ResultPagePdfTokenService;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AttemptPublicReportPdfParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Locked-cache and print-token contracts explicitly exercise paid preview mode.
        config()->set('report_unlock.mbti_access_mode', 'paid_unlock');
    }

    private function seedScales(bool $paidPreview = true): void
    {
        (new ScaleRegistrySeeder)->run();
        if ($paidPreview) {
            $scale = \App\Models\ScaleRegistry::query()->where('org_id', 0)->where('code', 'MBTI')->firstOrFail();
            $fixture = $scale->only($scale->getFillable());
            $fixture['capabilities_json']['paywall_mode'] = 'full';
            $fixture['commercial_json']['report_unlock_sku'] = 'MBTI_REPORT_FULL_199';
            $fixture['commercial_json']['report_benefit_code'] = 'MBTI_REPORT_FULL';
            app(\App\Services\Scale\ScaleRegistryWriter::class)->upsertScale($fixture);
        }
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

    private function seedEnglishClone(): void
    {
        $profile = \App\Models\PersonalityProfile::create([
            'org_id' => 0, 'scale_code' => 'MBTI', 'type_code' => 'INTJ', 'slug' => 'intj',
            'locale' => 'en', 'title' => 'INTJ', 'status' => 'published', 'is_public' => true, 'schema_version' => 'v2',
        ]);
        \App\Models\PersonalityProfileVariant::create([
            'personality_profile_id' => $profile->id, 'canonical_type_code' => 'INTJ', 'variant_code' => 'A',
            'runtime_type_code' => 'INTJ-A', 'schema_version' => 'v2', 'is_published' => true,
        ]);
        $reader = app(\App\PersonalityCms\DesktopClone\Baseline\PersonalityDesktopCloneBaselineReader::class);
        $rows = app(\App\PersonalityCms\DesktopClone\Baseline\PersonalityDesktopCloneBaselineNormalizer::class)
            ->normalizeDocuments($reader->read($reader->resolveSourceDir(), ['en']), ['INTJ-A']);
        app(\App\PersonalityCms\DesktopClone\Baseline\PersonalityDesktopCloneBaselineImporter::class)
            ->import($rows, ['dry_run' => false, 'upsert' => true, 'status' => 'published']);
    }

    public function test_complete_english_report_exposes_original_content_only_after_full_gate(): void
    {
        $this->seedScales(false);
        $this->seedEnglishClone();
        config()->set('report_unlock.mbti_access_mode', 'free_full');
        config()->set('fap.features.report_snapshot_strict_v2', false);
        $attemptId = (string) Str::uuid();
        $anonId = 'en14_full_content_owner';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, 'MBTI', $anonId);
        $this->createResult($attemptId);
        Attempt::whereKey($attemptId)->update(['locale' => 'en']);
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Anon-Id' => $anonId])
            ->getJson("/api/v0.3/attempts/{$attemptId}/report?locale=en");
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertJsonPath('mbti_pdf_content.locale', 'en');
        $response->assertJsonPath('mbti_pdf_content._meta.access_source', 'authorized-report-full');
        $response->assertJsonMissingPath('mbti_pdf_content._meta.record_id');
        $this->assertNotEmpty($response->json('mbti_pdf_content.content.chapters.career.lockedBlocks.0.blurredItems.0.body'));
        $public = $this->getJson('/api/v0.5/personality/intj-a/desktop-clone?locale=en');
        $public->assertOk()->assertJsonPath('content.chapters.career.lockedBlocks.0.blurredItems.0.is_locked', true);
        $public->assertJsonMissingPath('content.chapters.career.lockedBlocks.0.blurredItems.0.body');
        $otherToken = $this->issueAnonToken('another-owner');
        $this->withHeaders(['Authorization' => 'Bearer '.$otherToken, 'X-Anon-Id' => 'another-owner'])
            ->getJson("/api/v0.3/attempts/{$attemptId}/report?locale=en")->assertNotFound();
    }

    public function test_english_report_without_published_clone_does_not_invent_content(): void
    {
        $this->seedScales();
        config()->set('fap.features.report_snapshot_strict_v2', false);
        $attemptId = (string) Str::uuid();
        $this->createAttempt($attemptId, 'MBTI', 'locked-owner');
        $this->createResult($attemptId);
        Attempt::whereKey($attemptId)->update(['locale' => 'en']);
        $token = $this->issueAnonToken('locked-owner');
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Anon-Id' => 'locked-owner'])
            ->getJson("/api/v0.3/attempts/{$attemptId}/report?locale=en")
            ->assertOk()->assertJsonPath('locked', false)->assertJsonMissingPath('mbti_pdf_content');
    }

    public function test_print_cache_key_changes_with_published_body_and_intro_revision(): void
    {
        $this->seedScales(false);
        $this->seedEnglishClone();
        $attemptId = (string) Str::uuid();
        $this->createAttempt($attemptId, 'MBTI', 'cache-content-owner');
        $this->createResult($attemptId);
        $attempt = Attempt::findOrFail($attemptId);
        $attempt->update(['locale' => 'en']);
        $result = Result::where('attempt_id', $attemptId)->firstOrFail();
        $pdf = app(ReportPdfDocumentService::class);
        $before = $pdf->resolveArtifactPath($attempt, 'full', $result);
        $record = \App\Models\PersonalityProfileVariantCloneContent::firstOrFail();
        $content = $record->content_json;
        $content['hero']['summary'] .= ' Existing English revision.';
        $record->update(['content_json' => $content]);
        $afterBody = $pdf->resolveArtifactPath($attempt, 'full', $result);
        $this->assertNotSame($before, $afterBody);
        $this->assertLessThanOrEqual(255, strlen(basename(dirname($afterBody))));
        \App\Models\PersonalityResultIntroduction::create([
            'full_code' => 'INTJ-A', 'locale' => 'en', 'paragraphs' => ['Synthetic paragraph one.', 'Synthetic paragraph two.'],
            'revision' => 1, 'content_hash' => str_repeat('a', 64), 'status' => 'published', 'published_at' => now()->subMinute(),
        ]);
        $this->assertNotSame($afterBody, $pdf->resolveArtifactPath($attempt, 'full', $result));
    }

    private function emailReadGrant(string $attemptId, string $anonId): array
    {
        $cipher = app(\App\Support\PiiCipher::class);
        $email = $cipher->normalizeEmail('synthetic-pdf@example.test');
        $binding = \App\Models\AttemptEmailBinding::create([
            'id' => (string) Str::uuid(), 'org_id' => 0, 'attempt_id' => $attemptId,
            'pii_email_key_version' => $cipher->currentKeyVersion(),
            'email_hash' => $cipher->emailHash($email), 'email_enc' => $cipher->encrypt($email),
            'bound_anon_id' => $anonId, 'status' => 'active', 'source' => 'result_gate', 'first_bound_at' => now()->subMinute(),
        ]);

        return [$binding, app(\App\Services\Results\ResultAccessTokenService::class)->issueForBinding($binding)['token']];
    }

    public function test_mbti_email_read_grant_keeps_pdf_entitlement_and_rejects_invalid_bindings(): void
    {
        $this->seedScales();
        config()->set('fap.features.email_first_result_access', true);
        config()->set('fap.features.report_snapshot_strict_v2', false);
        config()->set('gotenberg.enabled', true);
        config()->set('gotenberg.base_url', 'http://gotenberg:3000');
        config()->set('gotenberg.result_print_base_url', 'http://frontend:3000');
        Storage::fake('local');
        Http::fake(['gotenberg:3000/forms/chromium/convert/url' => Http::response('%PDF-1.4 synthetic authorized preview')]);
        $attemptId = (string) Str::uuid();
        $this->createAttempt($attemptId, 'MBTI', 'email-pdf-owner');
        $this->createResult($attemptId);
        [$binding, $token] = $this->emailReadGrant($attemptId, 'email-pdf-owner');
        $this->withHeaders(['X-Result-Access-Token' => $token])->getJson("/api/v0.3/attempts/{$attemptId}/report")
            ->assertOk()->assertJsonPath('locked', true);
        foreach (['report.pdf', 'result-page.pdf'] as $endpoint) {
            $this->withHeaders(['X-Result-Access-Token' => $token])->get("/api/v0.3/attempts/{$attemptId}/{$endpoint}")
                ->assertOk()->assertHeader('X-Report-Locked', 'true')->assertHeader('X-Report-Variant', 'free');
            $this->withHeaders(['X-Result-Access-Token' => $token, 'X-Org-Id' => '987654321'])
                ->getJson("/api/v0.3/attempts/{$attemptId}/{$endpoint}")->assertNotFound();
            $this->flushHeaders()->withHeaders(['X-Result-Access-Token' => $token])
                ->getJson('/api/v0.3/attempts/'.Str::uuid().'/'.$endpoint)->assertNotFound();
            $this->travel(2)->days();
            $this->getJson("/api/v0.3/attempts/{$attemptId}/{$endpoint}")->assertNotFound();
            $this->travelBack();
        }
        $binding->update(['status' => 'revoked']);
        foreach (['report.pdf', 'result-page.pdf'] as $endpoint) {
            $this->getJson("/api/v0.3/attempts/{$attemptId}/{$endpoint}")->assertNotFound();
        }
    }

    public function test_mbti_pdf_denials_are_private_and_not_stored(): void
    {
        $this->seedScales(false);
        $attemptId = (string) Str::uuid();
        $this->createAttempt($attemptId, 'MBTI', 'denied-pdf-owner');
        $this->createResult($attemptId);
        foreach (['report.pdf', 'result-page.pdf'] as $endpoint) {
            $this->flushHeaders()->getJson("/api/v0.3/attempts/{$attemptId}/{$endpoint}")
                ->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
            $this->withHeaders(['Authorization' => 'Bearer '.$this->issueAnonToken('another-pdf-owner'), 'X-Anon-Id' => 'another-pdf-owner'])
                ->getJson("/api/v0.3/attempts/{$attemptId}/{$endpoint}")
                ->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
            $this->flushHeaders()->getJson('/api/v0.3/attempts/'.Str::uuid().'/'.$endpoint)
                ->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
        }
    }

    public function test_result_quality_and_form_changes_cannot_reuse_a_previous_pdf(): void
    {
        $this->seedScales(false);
        $attemptId = (string) Str::uuid();
        $this->createAttempt($attemptId, 'MBTI', 'cache-quality-owner');
        $this->createResult($attemptId);
        $attempt = Attempt::findOrFail($attemptId);
        $result = Result::where('attempt_id', $attemptId)->firstOrFail();
        $service = app(ReportPdfDocumentService::class);
        Storage::fake('local');
        $before = $service->resolveArtifactPath($attempt, 'full', $result);
        Storage::disk('local')->put($before, '%PDF-1.4 previous complete report');
        $payload = $result->result_json;
        $payload['quality'] = ['level' => 'D', 'grade' => 'D', 'flags' => ['INCOMPLETE_ANSWERS']];
        $result->update(['result_json' => $payload]);
        $afterQuality = $service->resolveArtifactPath($attempt, 'full', $result);
        $this->assertNotSame($before, $afterQuality);
        $this->assertNull($service->readArtifact($attempt, 'full', $result));
        $attempt->update(['form_code' => 'mbti_93', 'question_count' => 93]);
        $this->assertNotSame($afterQuality, $service->resolveArtifactPath($attempt, 'full', $result));
        foreach (['generating', 'snapshot_error'] as $state) {
            try {
                $service->getOrGenerateMbtiResultPageExport($attempt, ['variant' => 'full', 'locked' => false, $state => true], $result);
                $this->fail('Unready reports must not read cached PDFs.');
            } catch (\App\Services\Report\Pdf\ResultPagePdfExportException $e) {
                $this->assertSame('REPORT_NOT_READY', $e->errorCode());
            }
        }
    }

    public function test_pdf_does_not_cache_a_body_revision_changed_during_generation(): void
    {
        $this->seedScales(false);
        $this->seedEnglishClone();
        config()->set('report_unlock.mbti_access_mode', 'free_full');
        config()->set('fap.features.report_snapshot_strict_v2', false);
        config()->set('gotenberg.enabled', true);
        config()->set('gotenberg.base_url', 'http://gotenberg:3000');
        config()->set('gotenberg.result_print_base_url', 'http://frontend:3000');
        Storage::fake('local');
        $attemptId = (string) Str::uuid();
        $this->createAttempt($attemptId, 'MBTI', 'changing-body-owner');
        $this->createResult($attemptId);
        $attempt = Attempt::findOrFail($attemptId);
        $attempt->update(['locale' => 'en']);
        $result = Result::where('attempt_id', $attemptId)->firstOrFail();
        $oldPath = app(ReportPdfDocumentService::class)->resolveArtifactPath($attempt, 'full', $result);
        Http::fake(['gotenberg:3000/forms/chromium/convert/url' => static function () {
            $record = \App\Models\PersonalityProfileVariantCloneContent::firstOrFail();
            $content = $record->content_json;
            $content['hero']['summary'] .= ' Changed while Chromium was running.';
            $record->update(['content_json' => $content]);

            return Http::response('%PDF-1.4 Chromium synthetic response');
        }]);
        $token = $this->issueAnonToken('changing-body-owner');
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Anon-Id' => 'changing-body-owner'])
            ->getJson("/api/v0.3/attempts/{$attemptId}/report.pdf")
            ->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private');
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertMissing(app(ReportPdfDocumentService::class)->resolveArtifactPath($attempt, 'full', $result));
    }

    public function test_partial_module_gate_cannot_read_complete_clone_content(): void
    {
        $service = app(\App\Services\Cms\PersonalityDesktopCloneContentService::class);
        $this->assertNull($service->getPublishedForAuthorizedReport('INTJ-A', 0, 'en', [
            'locked' => false, 'variant' => 'full', 'modules_allowed' => ['core_free', 'core_full', 'career'],
        ]));
        $this->assertNull($service->getPublishedForAuthorizedReport('INTJ-A', 0, 'en', [
            'locked' => true, 'variant' => 'full', 'modules_allowed' => ['core_full', 'career', 'relationships'],
        ]));
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
        $pdf->assertStatus(503);
        $pdf->assertJsonPath('ok', false);
        $pdf->assertJsonPath('code', 'RESULT_PAGE_PDF_EXPORT_FAILED');
        $pdf->assertHeader('X-Legacy-Mpdf-Fallback', 'false');
        $this->assertStringContainsString('private', (string) $pdf->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $pdf->headers->get('Cache-Control'));
        Storage::disk('local')->assertMissing(
            "artifacts/pdf/MBTI/{$attemptId}/nohash-mbti.pdf_surface.v5/report_free.pdf"
        );
    }

    public static function reportAccessModes(): array
    {
        $cases = [];
        foreach (['zh-CN', 'en'] as $locale) {
            foreach ([93, 144] as $count) {
                foreach ([['paid_unlock', 'free', 'true'], ['free_full', 'full', 'false']] as $mode) {
                    // English freemium is already full-free even in paid-unlock mode.
                    $expected = $locale === 'en' ? [$mode[0], 'full', 'false'] : $mode;
                    $cases[$locale.'-'.$count.'-'.$mode[0]] = [...$expected, $locale, $count];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('reportAccessModes')]
    public function test_public_mbti_report_pdf_prefers_gotenberg_result_print_route_when_enabled(string $mode, string $variant, string $locked, string $locale, int $questionCount): void
    {
        $this->seedScales($mode === 'paid_unlock');
        config()->set('report_unlock.mbti_access_mode', $mode);
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
        $attempt = Attempt::findOrFail($attemptId);
        $attempt->update(['locale' => $locale, 'question_count' => $questionCount, 'form_code' => 'mbti_'.$questionCount]);

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
        $pdf->assertHeader('X-Report-Locked', $locked);
        $pdf->assertHeader('X-Pdf-Surface-Version', 'mbti.result_page_snapshot.v4');
        $pdf->assertHeader('X-Legacy-Mpdf-Fallback', 'false');
        $this->assertStringContainsString('private', (string) $pdf->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $pdf->headers->get('Cache-Control'));

        $pdfBinary = (string) $pdf->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdfBinary);
        $this->assertStringContainsString('/Producer (Chromium)', $pdfBinary);
        $this->assertStringNotContainsString('mPDF', $pdfBinary);
        $this->assertStringContainsString('Personality Traits', $pdfBinary);
        $this->assertStringContainsString('Your Career Path', $pdfBinary);
        $this->assertStringContainsString('Your Personal Growth', $pdfBinary);
        $this->assertStringContainsString('Your Relationships', $pdfBinary);

        $recorded = Http::recorded()->first();
        $this->assertNotNull($recorded);
        [$request] = $recorded;
        $fields = collect($request->data())->mapWithKeys(
            fn (array $item): array => [$item['name'] => $item['contents']]
        )->all();
        $printLocale = $locale === 'zh-CN' ? 'zh' : 'en';
        $this->assertSame("/{$printLocale}/result/{$attemptId}/print", parse_url($fields['url'], PHP_URL_PATH));
        parse_str((string) parse_url($fields['url'], PHP_URL_QUERY), $query);
        $grant = app(ResultPagePdfTokenService::class)->verifyMbtiResultPageExport($query['result_access_token'], 0, $attemptId);
        $this->assertIsArray($grant);
        $this->assertSame($printLocale, $grant['locale']);
        $this->assertSame($variant, $grant['variant']);
        $this->assertSame($locked === 'true' ? 'locked' : 'unlocked', $grant['entitlement']);
        $this->assertSame('window.__FERMAT_PDF_READY__ === true', $fields['waitForExpression']);
        $this->assertSame('print', $fields['emulatedMediaType']);

        $service = app(ReportPdfDocumentService::class);
        $this->assertSame($gotenbergPdf, $service->readArtifact($attempt, $variant, Result::where('attempt_id', $attemptId)->firstOrFail()));
        Storage::disk('local')->assertExists($service->resolveArtifactPath($attempt, $variant));
        $snapshot = $this->withHeaders($headers)->get("/api/v0.3/attempts/{$attemptId}/result-page.pdf");
        $snapshot->assertOk()->assertHeader('X-Pdf-Artifact-Cache', 'HIT');
        $this->assertSame($pdfBinary, $snapshot->getContent());
        Http::assertSentCount(1);
    }

    #[DataProvider('pdfEndpoints')]
    public function test_public_mbti_report_pdf_still_requires_matching_attempt_subject(string $endpoint): void
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

        $pdf = $this->withHeaders($headers)->get("/api/v0.3/attempts/{$attemptId}/{$endpoint}");
        $pdf->assertStatus(404);
    }

    #[DataProvider('pdfEndpoints')]
    public function test_expired_owner_token_cannot_export_a_private_mbti_pdf(string $endpoint): void
    {
        $this->seedScales();
        Storage::fake('local');
        Http::fake();
        $attemptId = (string) Str::uuid();
        $anon = 'synthetic_expired_pdf_owner';
        $token = $this->issueAnonToken($anon);
        $this->createAttempt($attemptId, 'MBTI', $anon);
        $this->createResult($attemptId);
        DB::table('fm_tokens')->where('token_hash', hash('sha256', $token))->update(['expires_at' => now()->subMinute()]);
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'X-Anon-Id' => $anon])
            ->get("/api/v0.3/attempts/{$attemptId}/{$endpoint}");
        $this->assertContains($response->status(), [401, 404]);
        $this->assertNotSame('application/pdf', $response->headers->get('Content-Type'));
        Http::assertNothingSent();
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
        $pdf->assertHeader('X-Pdf-Print-Asset-Hash', 'sha256:845eb8bb158e07c265c33fa90569bee0c6fcd44bdcb9947773f2a0773ebdb72c');
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
            app(ReportPdfDocumentService::class)->resolveArtifactPath(Attempt::findOrFail($attemptId), 'free')
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

    public static function mbtiFormsAndLocales(): array
    {
        return [['zh-CN', 93], ['zh-CN', 144], ['en', 93], ['en', 144]];
    }

    #[DataProvider('mbtiFormsAndLocales')]
    public function test_mbti_result_page_pdf_locked_token_does_not_promote_report_access_to_full(string $locale, int $count): void
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
        $attempt->update(['locale' => $locale, 'question_count' => $count, 'form_code' => 'mbti_'.$count]);
        $token = app(ResultPagePdfTokenService::class)->issueForMbtiResultPageExport($attempt, [
            'locked' => true,
            'variant' => 'free',
        ], $locale === 'en' ? 'en' : 'zh');

        $response = $this->withHeaders([
            'X-Result-Access-Token' => $token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report-access?locale={$locale}");

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

    public static function pdfEndpoints(): array
    {
        return [['report.pdf'], ['result-page.pdf']];
    }

    #[DataProvider('pdfEndpoints')]
    public function test_public_mbti_result_page_pdf_does_not_fallback_to_mpdf_when_gotenberg_fails(string $endpoint): void
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
        ])->get("/api/v0.3/attempts/{$attemptId}/{$endpoint}");

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
        $pdf->assertHeader('X-Pdf-Print-Asset-Hash', 'sha256:845eb8bb158e07c265c33fa90569bee0c6fcd44bdcb9947773f2a0773ebdb72c');
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
            app(ReportPdfDocumentService::class)->resolveArtifactPath(Attempt::findOrFail($attemptId), 'free')
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
            "artifacts/pdf/MBTI/{$attemptId}/635205126b60d889ad3ffc2d77a14d6f561e39e6c279c6b9ca6b7753d43f1fcd-mbti.result_page_snapshot.v4-gotenberg_chromium-zh-locked-free/report_free.pdf",
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
            app(ReportPdfDocumentService::class)->resolveArtifactPath(Attempt::findOrFail($attemptId), 'free'),
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
        $pdf->assertHeader('X-Pdf-Print-Asset-Hash', 'sha256:845eb8bb158e07c265c33fa90569bee0c6fcd44bdcb9947773f2a0773ebdb72c');
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
            str_replace('sha256_'.str_repeat('1', 64), 'sha256_938d48a69090b837cd6b6677c1d3bbe7fc903fb38e3957bcde1eb421d9691b67', app(ReportPdfDocumentService::class)->resolveArtifactPath(Attempt::findOrFail($attemptId), 'free')),
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
            app(ReportPdfDocumentService::class)->resolveArtifactPath(Attempt::findOrFail($attemptId), 'free')
        );
    }

    public function test_mbti_report_pdf_rejects_old_unversioned_artifacts_and_versions_attempt_manifest(): void
    {
        $this->seedScales(false);
        config()->set('report_unlock.mbti_access_mode', 'free_full');
        config()->set('fap.features.report_snapshot_strict_v2', false);
        config()->set('gotenberg.enabled', true);
        config()->set('gotenberg.base_url', 'http://gotenberg:3000');
        config()->set('gotenberg.result_print_base_url', 'http://frontend:3000');
        Storage::fake('local');

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_mbti_pdf_obsolete_cache';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, 'MBTI', $anonId);
        $this->createResult($attemptId);
        $attempt = Attempt::findOrFail($attemptId);
        $attempt->update(['answers_summary_json' => ['meta' => ['pack_release_manifest_hash' => 'attempt-content-manifest']]]);
        $store = app(\App\Services\Storage\ArtifactStore::class);
        foreach ($store->pdfLegacyPaths('MBTI', $attemptId, 'attempt-content-manifest', 'full') as $path) {
            Storage::disk('local')->put($path, '%PDF-1.4 OLD_TITLE_ONLY');
        }
        Storage::disk('local')->put("artifacts/pdf/MBTI/{$attemptId}/attempt-content-manifest/report_full.pdf", '%PDF-1.4 OLD_TITLE_ONLY');
        $service = app(ReportPdfDocumentService::class);
        $this->assertSame('attempt-content-manifest-mbti.pdf_surface.v5', $service->resolveManifestHash($attempt));
        $this->assertNull($service->readArtifact($attempt, 'full'));
        Http::fake(['gotenberg:3000/forms/chromium/convert/url' => Http::response('%PDF-1.4 CURRENT_SNAPSHOT')]);
        $headers = ['X-Anon-Id' => $anonId, 'Authorization' => 'Bearer '.$token];
        $response = $this->withHeaders($headers)->get("/api/v0.3/attempts/{$attemptId}/report.pdf");
        $response->assertOk()->assertHeader('X-Pdf-Artifact-Cache', 'MISS');
        $this->assertStringNotContainsString('OLD_TITLE_ONLY', (string) $response->getContent());
        $this->assertSame('%PDF-1.4 CURRENT_SNAPSHOT', $service->readArtifact($attempt, 'full'));
        config()->set('gotenberg.result_print_asset_hash', 'sha256:'.str_repeat('2', 64));
        $this->assertNull($service->readArtifact($attempt, 'full'));
        $this->withHeaders($headers)->get("/api/v0.3/attempts/{$attemptId}/report.pdf")
            ->assertOk()->assertHeader('X-Pdf-Artifact-Cache', 'MISS');
        Http::assertSentCount(2);
        // A valid strict artifact remains readable during an engine outage; legacy bytes never substitute for it.
        config()->set('gotenberg.enabled', false);
        $this->assertSame('%PDF-1.4 CURRENT_SNAPSHOT', $service->readArtifact($attempt, 'full'));
    }

    #[DataProvider('mbtiFormsAndLocales')]
    public function test_engine_disabled_cannot_export_legacy_mixed_locale_copy_or_technical_tags(string $locale, int $count): void
    {
        $this->seedScales(false);
        config()->set('report_unlock.mbti_access_mode', 'free_full');
        config()->set('fap.features.report_snapshot_strict_v2', false);
        config()->set('gotenberg.enabled', false);
        Storage::fake('local');
        Http::preventStrayRequests();
        $attemptId = (string) Str::uuid();
        $anonId = 'anon_mbti_disabled_engine';
        $token = $this->issueAnonToken($anonId);
        $this->createAttempt($attemptId, 'MBTI', $anonId);
        $this->createResult($attemptId);
        Attempt::findOrFail($attemptId)->update(['locale' => $locale, 'question_count' => $count, 'form_code' => 'mbti_'.$count]);
        foreach (['report.pdf', 'result-page.pdf'] as $endpoint) {
            $response = $this->withHeaders(['X-Anon-Id' => $anonId, 'Authorization' => 'Bearer '.$token])
                ->get("/api/v0.3/attempts/{$attemptId}/{$endpoint}");
            $response->assertStatus(503)->assertJsonPath('ok', false)->assertHeader('X-Legacy-Mpdf-Fallback', 'false');
            $this->assertStringNotContainsString('%PDF-', (string) $response->getContent());
            $this->assertStringNotContainsString('kind:axis', (string) $response->getContent());
        }
        $this->assertSame([], Storage::disk('local')->allFiles('artifacts/pdf/MBTI/'.$attemptId));
        Http::assertNothingSent();
    }
}
