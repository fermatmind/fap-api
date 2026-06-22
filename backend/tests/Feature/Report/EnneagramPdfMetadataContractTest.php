<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\Pdf\ReportPdfDocumentService;
use App\Services\Report\ReportGatekeeper;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class EnneagramPdfMetadataContractTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('formsProvider')]
    public function test_enneagram_pdf_metadata_is_form_aware(
        string $formCode,
        string $expectedLabel,
        string $expectedSlug,
        string $anonId
    ): void {
        (new ScaleRegistrySeeder)->run();

        $token = $this->issueAnonToken($anonId);
        $attemptId = $this->createSubmittedEnneagramAttempt($anonId, $token, $formCode);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->get('/api/v0.3/attempts/'.$attemptId.'/report.pdf?inline=1');

        $response->assertOk();
        $response->assertHeader('X-Pdf-Surface-Version', 'enneagram.pdf_surface.v1');
        $response->assertHeader('X-Report-Form-Code', $formCode);
        $response->assertHeader('X-Report-Form-Label', $expectedLabel);
        $response->assertHeader('X-Cross-Form-Comparable', 'false');
        $this->assertEnneagramPdfHeadersArePublicSafe($response->headers->all());
        $this->assertEnneagramPdfBodyIsPublicSafe((string) $response->getContent(), $attemptId);
        $this->assertStringContainsString(
            sprintf('fermatmind-enneagram-%s-%s.pdf', $expectedSlug, now()->format('Y-m-d')),
            (string) $response->headers->get('Content-Disposition')
        );

        /** @var Attempt $attempt */
        $attempt = Attempt::query()->findOrFail($attemptId);
        /** @var Result $result */
        $result = Result::query()->where('attempt_id', $attemptId)->firstOrFail();
        $gate = app(ReportGatekeeper::class)->resolve(0, $attemptId, null, $anonId, 'public', false, false);
        $metadata = app(ReportPdfDocumentService::class)->metadata($attempt, $gate, $result);

        $this->assertSame('enneagram.pdf_surface.v1', $metadata['pdf_surface_version']);
        $this->assertSame($formCode, $metadata['form_code']);
        $this->assertSame($expectedLabel, $metadata['form_label']);
        $this->assertSame(false, $metadata['cross_form_comparable']);
        $this->assertNotSame('', (string) $metadata['filename_hint']);
        $this->assertSame('enneagram.report.v2', $metadata['report_schema_version']);
        $this->assertSame('enneagram_projection.v2', $metadata['projection_version']);
        $this->assertNotSame('', (string) $metadata['interpretation_context_id']);
        $this->assertNotSame('', (string) $metadata['content_release_hash']);
        $this->assertNotSame('', (string) $metadata['content_snapshot_status']);
        $this->assertNotEmpty((array) $metadata['snapshot_binding_v1']);
    }

    /**
     * @param  array<string,list<string|null>>  $headers
     */
    private function assertEnneagramPdfHeadersArePublicSafe(array $headers): void
    {
        foreach ([
            'x-report-schema-version',
            'x-projection-version',
            'x-report-engine-version',
            'x-interpretation-context-id',
            'x-content-release-hash',
            'x-content-snapshot-status',
        ] as $header) {
            $this->assertArrayNotHasKey($header, $headers, $header);
        }
    }

    private function assertEnneagramPdfBodyIsPublicSafe(string $pdfBinary, string $attemptId): void
    {
        foreach ([
            $attemptId,
            'Attempt ID:',
            'raw_score',
            'raw_scores',
            'raw_score_vector',
            'score_vector',
            'schema_version',
            'report_schema_version',
            'projection_version',
            'interpretation_context_id',
            'content_release_hash',
            'content_snapshot_status',
            'sha256:',
            'enneagram.report.v2',
            'enneagram_projection.v2',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $pdfBinary, $forbidden);
        }
    }

    /**
     * @return iterable<string,array{string,string,string,string}>
     */
    public static function formsProvider(): iterable
    {
        yield 'e105' => ['enneagram_likert_105', 'E105 标准版', 'e105', 'anon_enneagram_pdf_meta_105'];
        yield 'fc144' => ['enneagram_forced_choice_144', 'FC144 深度版', 'fc144', 'anon_enneagram_pdf_meta_144'];
    }

    private function createSubmittedEnneagramAttempt(string $anonId, string $token, string $formCode): string
    {
        $start = $this->withHeaders(['X-Anon-Id' => $anonId])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'ENNEAGRAM',
            'anon_id' => $anonId,
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'form_code' => $formCode,
        ]);
        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');

        $questions = $this->getJson('/api/v0.3/scales/ENNEAGRAM/questions?form_code='.$formCode);
        $questions->assertStatus(200);
        $answers = $this->buildAnswersFromItems((array) $questions->json('questions.items'));

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 180000,
        ]);
        $submit->assertStatus(200);

        return $attemptId;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array{question_id:string,code:string}>
     */
    private function buildAnswersFromItems(array $items): array
    {
        $answers = [];
        foreach ($items as $index => $item) {
            $questionId = trim((string) ($item['question_id'] ?? ''));
            $options = is_array($item['options'] ?? null) ? $item['options'] : [];
            if ($questionId === '' || $options === []) {
                continue;
            }
            $selected = $options[$index % count($options)] ?? $options[0];
            $answers[] = [
                'question_id' => $questionId,
                'code' => trim((string) ($selected['code'] ?? '')),
            ];
        }

        return array_values(array_filter($answers, static fn (array $answer): bool => $answer['code'] !== ''));
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
}
