<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Jobs\GenerateReportPdfJob;
use App\Models\User;
use App\Services\Commerce\CareerAssessmentPurchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MainstreamReportCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private string $keyFile;

    private array $guestKeys = [];

    public function withToken($value, $type = 'Bearer')
    {
        return parent::withToken($value, $type)->withHeader('X-Career-Guest-Token', $this->guestKeys[$value] ?? '');
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('report_unlock.mbti_access_mode', 'paid_unlock');
    }

    protected function tearDown(): void
    {
        if (isset($this->keyFile)) {
            unlink($this->keyFile);
        }
        parent::tearDown();
    }

    public static function versions(): array
    {
        $versions = ['MBTI' => [93, 144], 'BIG5_OCEAN' => [90, 120], 'ENNEAGRAM' => [105, 144], 'IQ_RAVEN' => [30, 40],
            'EQ_60' => [60], 'JUNG_ARCHETYPES' => [48], 'JUNG_COGNITIVE_FUNCTIONS' => [64], 'DISC_BEHAVIOR' => [24]];
        $cases = [];
        foreach ($versions as $scale => $counts) {
            foreach ($counts as $count) {
                foreach (['zh-CN', 'zh-TW', 'en'] as $locale) {
                    $cases[$scale.'-'.$count.'-'.$locale] = [$scale, $count, $locale];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('versions')]
    public function test_guest_payment_signed_callback_refund_and_private_access(string $scale, int $count, string $locale): void
    {
        $key = $this->configureWaffo();
        config(['payments.providers.alipay.enabled' => true, 'pay.alipay.default.app_id' => 'fixture', 'pay.alipay.default.seller_id' => 'fixture']);
        $token = $this->token(true);
        $impostor = $this->token(true);
        $actor = DB::table('auth_tokens')->where('token_hash', hash('sha256', $token))->first();
        DB::table('auth_tokens')->where('token_hash', hash('sha256', $impostor))->update(['anon_id' => $actor->anon_id]);
        foreach (['CNY', 'USD'] as $currency) {
            $id = $this->fixture($token, $scale, $count, $locale);
            $this->withToken($token)->getJson('/api/v0.3/attempts/'.$id.'/result')->assertOk()
                ->assertJsonPath('report_purchase.unlocked', false)->assertJsonCount(3, 'report_purchase.offers')
                ->assertJsonMissingPath('result.private_secret')->assertJsonMissingPath('report');
            $this->withToken($token)->getJson('/api/v0.3/attempts/'.$id.'/report')->assertOk()->assertJsonMissingPath('report');
            $this->withToken($token)->getJson('/api/v0.3/attempts/'.$id.'/report.pdf')->assertForbidden();
            $this->withToken($token)->getJson('/api/v0.3/attempts/'.$id.'/result-page.pdf')->assertForbidden();
            $this->withToken($token)->postJson('/api/v0.3/attempts/'.$id.'/invite-unlocks')->assertForbidden();
            $this->withToken($token)->getJson('/api/v0.3/attempts/'.$id.'/report-access')->assertOk()->assertJsonPath('access_state', 'locked')->assertJsonPath('report_state', 'ready')->assertJsonPath('actions.page_href', '/result/'.$id);
            $this->withToken($impostor)->getJson('/api/v0.3/attempts/'.$id.'/result')->assertNotFound();
            $sku = \App\Services\Commerce\MainstreamReportPurchase::skuMap()[$scale][$currency];
            $input = ['attempt_id' => $id, 'sku' => $sku, 'provider' => 'waffo'];
            $this->withToken($impostor)->postJson('/api/v0.3/orders/checkout', $input)->assertNotFound();
            $this->withToken($token)->postJson('/api/v0.3/orders/checkout', array_replace($input, ['sku' => CareerAssessmentPurchase::RIASEC_SKUS[$currency]]))->assertStatus(400);
            $number = $this->withToken($token)->postJson('/api/v0.3/orders/checkout', $input)->assertOk()->json('order_no');
            $this->withToken($token)->postJson('/api/v0.3/orders/checkout', $input)->assertOk()->assertJsonPath('order_no', $number);
            $this->withToken($impostor)->getJson('/api/v0.3/orders/'.$number)->assertNotFound();
            $event = ['eventType' => 'order.completed', 'eventId' => 'paid_'.$number, 'mode' => 'test', 'storeId' => 'STO_fixture', 'timestamp' => now()->format('Y-m-d\TH:i:s.v\Z'),
                'data' => ['orderId' => 'ORD_'.$number, 'orderStatus' => 'completed', 'paymentStatus' => 'succeeded', 'currency' => $currency, 'chargedAmount' => '2.99',
                    'orderMerchantExternalId' => $number, 'orderMetadata' => ['fap_order_no' => $number, 'fap_attempt_id' => $id, 'fap_product_id' => 'PROD_career94']]];
            $this->deliver($event, $key, true)->assertStatus(401);
            $wrong = $event;
            $wrong['data']['chargedAmount'] = '1.99';
            $this->deliver($wrong, $key)->assertJsonPath('ok', false);
            $this->deliver($event, $key)->assertOk()->assertJsonPath('ok', true);
            $this->deliver($event, $key)->assertOk()->assertJsonPath('ok', true);
            $this->withToken($token)->getJson('/api/v0.3/attempts/'.$id.'/report-access')->assertOk()->assertJsonPath('access_state', 'ready')->assertJsonPath('report_purchase.unlocked', true);
            $this->assertTrue(app(CareerAssessmentPurchase::class)->hasAccess($id, 'guest:'.$actor->anon_id));
            $this->withToken($token)->postJson('/api/v0.5/career-assessment/orders/'.$number.'/refund', ['reason_code' => 'not_as_expected', 'acknowledged' => true])->assertOk()->assertJsonPath('request.status', 'pending');
            $refund = array_replace($event, ['eventType' => 'refund.succeeded', 'eventId' => 'refund_'.$number]);
            $refund['data'] += ['refundStatus' => 'succeeded', 'refundedAmount' => '2.99', 'originalPayment' => ['total' => '2.99']];
            $this->deliver($refund, $key)->assertOk()->assertJsonPath('ok', true);
            $this->deliver($refund, $key)->assertOk()->assertJsonPath('ok', true);
            $this->withToken($token)->getJson('/api/v0.3/attempts/'.$id.'/result')->assertOk()->assertJsonPath('report_purchase.unlocked', false)->assertJsonMissingPath('report');
            $this->assertFalse(app(CareerAssessmentPurchase::class)->hasAccess($id, 'guest:'.$actor->anon_id));
        }
    }

    public function test_paid_web_report_pdf_job_runs_without_browser_identity(): void
    {
        $this->seed(\Database\Seeders\ScaleRegistrySeeder::class);
        $disk = Storage::fake('local');
        config(['payments.career94.enabled' => true, 'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.app_id' => 'fixture', 'pay.alipay.default.seller_id' => 'fixture']);
        $token = $this->token(true);
        $id = $this->fixture($token, 'ENNEAGRAM', 105, 'en');
        $pdfUrl = '/api/v0.3/attempts/'.$id.'/report.pdf';
        $inputs = ['target_attempt_id' => $id, 'provider' => 'alipay', 'sku' => \App\Services\Commerce\MainstreamReportPurchase::skuMap()['ENNEAGRAM']['CNY']];
        $number = $this->withToken($token)->postJson('/api/v0.3/orders', $inputs)->assertOk()->json('order_no');
        $job = new GenerateReportPdfJob(0, $id, 'payment_unlock', $number);
        $this->app->instance('request', Request::create('/'));
        $this->app->call([$job, 'handle']);
        $this->assertDatabaseMissing('events', ['attempt_id' => $id, 'event_code' => 'report_pdf_generated']);

        $outcome = app(\App\Services\Commerce\PaymentWebhookProcessor::class)->process('alipay', [
            'out_trade_no' => $number, 'trade_no' => 'ali_'.$number, 'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '2.99', 'notify_id' => 'paid_'.$number,
        ]);
        $this->assertTrue($outcome['ok']);
        DB::table('report_snapshots')->where('attempt_id', $id)->update([
            'status' => 'ready',
            'report_full_json' => json_encode(['locale' => 'en', '_meta' => ['enneagram_report_v2' => [
                'candidates' => [['type_name' => 'Observer', 'score_display' => '71', 'sections' => [
                    ['title' => 'Patterns at work', 'paragraphs' => ['Notice when pausing to ask a question clarifies the team’s next decision.']],
                ]]],
            ]]], JSON_THROW_ON_ERROR),
        ]);
        $this->app->call([$job, 'handle']);

        $artifact = DB::table('events')->where('attempt_id', $id)->where('event_code', 'report_pdf_generated')->value('meta_json');
        $artifact = json_decode((string) $artifact, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('full', $artifact['variant']);
        $this->assertStringStartsWith('%PDF-', $disk->get($artifact['artifact_path']));
        $this->withToken($this->token(true))->getJson($pdfUrl)->assertNotFound();
    }

    public function test_disc_preserves_all_fifteen_existing_scores_and_gates_the_current_report(): void
    {
        config(['payments.career94.enabled' => true, 'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.app_id' => 'fixture', 'pay.alipay.default.seller_id' => 'fixture']);
        $token = $this->token(true);
        $witnesses = json_decode(file_get_contents(base_path('tests/Fixtures/commerce/disc-existing-score-witnesses.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($witnesses as $witness) {
            $this->assertSame(array_intersect_key($witness, array_flip(['counts', 'leaders', 'profile_id'])), app(\App\Services\Assessment\DiscReport::class)->score($witness['answers']));
            $input = ['answers' => $witness['answers'], 'locale' => 'zh-TW', 'client_ref' => (string) Str::uuid()];
            $id = $this->withToken($token)->postJson('/api/v0.3/disc-assessment/results', $input)->assertOk()->assertJsonPath('report_purchase.unlocked', false)->json('attempt_id');
            $this->withToken($token)->postJson('/api/v0.3/disc-assessment/results', $input)->assertOk()->assertJsonPath('attempt_id', $id);
            $this->withToken($token)->getJson('/api/v0.3/attempts/'.$id.'/report')->assertOk()->assertJsonMissingPath('report.disc.profile');
            $this->withToken($token)->get('/api/v0.3/attempts/'.$id.'/report.pdf')->assertForbidden();
            $number = $this->withToken($token)->postJson('/api/v0.3/orders', ['target_attempt_id' => $id, 'provider' => 'alipay', 'sku' => \App\Services\Commerce\MainstreamReportPurchase::skuMap()['DISC_BEHAVIOR']['CNY']])->assertOk()->json('order_no');
            $outcome = app(\App\Services\Commerce\PaymentWebhookProcessor::class)->process('alipay', ['out_trade_no' => $number, 'trade_no' => 'ali_'.$number,
                'trade_status' => 'TRADE_SUCCESS', 'total_amount' => '2.99', 'notify_id' => 'paid_'.$number]);
            $this->assertTrue($outcome['ok']);
            $this->withToken($token)->getJson('/api/v0.3/attempts/'.$id.'/report')->assertOk()
                ->assertJsonPath('report_purchase.unlocked', true)->assertJsonPath('report.disc.profile.id', $witness['profile_id'])
                ->assertJsonPath('report.disc.counts', $witness['counts']);
            $this->withToken($token)->getJson('/api/v0.3/attempts/'.$id.'/report-access')
                ->assertOk()->assertJsonPath('complete_pdf', true);
            $pdf = $this->withToken($token)->get('/api/v0.3/attempts/'.$id.'/report.pdf?display_locale=zh-TW')
                ->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Report-Variant', 'full');
            $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        }
        $wrong = $witnesses[0]['answers'];
        unset($wrong[array_key_first($wrong)]);
        $this->withToken($token)->postJson('/api/v0.3/disc-assessment/results', ['answers' => $wrong, 'locale' => 'en', 'client_ref' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_invalid_results_are_not_offered_for_purchase_and_enrollment_cannot_be_forged(): void
    {
        $this->configureWaffo();
        $token = $this->token(true);
        $id = $this->fixture($token, 'IQ_RAVEN', 40, 'en');
        \App\Models\Result::withoutGlobalScopes()->where('attempt_id', $id)->update(['is_valid' => false]);
        $this->withToken($token)->getJson('/api/v0.3/attempts/'.$id.'/result')->assertOk()->assertJsonCount(0, 'report_purchase.offers');
        $this->withToken($token)->postJson('/api/v0.3/orders', ['target_attempt_id' => $id, 'provider' => 'waffo', 'sku' => \App\Services\Commerce\MainstreamReportPurchase::skuMap()['IQ_RAVEN']['USD']])->assertStatus(400);
        $attempt = \App\Models\Attempt::withoutGlobalScopes()->findOrFail($id);
        $attempt->answers_summary_json = [];
        $this->assertFalse(app(\App\Services\Commerce\MainstreamReportPurchase::class)->managed($attempt));
    }

    public function test_export_tokens_are_read_only_and_refund_still_revokes_their_full_access(): void
    {
        $this->configureWaffo();
        $token = $this->token(true);
        $id = $this->fixture($token, 'MBTI', 93, 'en');
        $attempt = \App\Models\Attempt::withoutGlobalScopes()->findOrFail($id);
        $printToken = app(\App\Services\Report\Pdf\ResultPagePdfTokenService::class)->issueForMbtiResultPageExport($attempt, ['locked' => false, 'variant' => 'full'], 'en');
        $this->flushHeaders();
        $this->getJson('/api/v0.3/attempts/'.$id.'/report-access?access_token='.urlencode($printToken))->assertOk()->assertJsonPath('report_purchase.unlocked', false);
        $request = \Illuminate\Http\Request::create('/api/v0.3/attempts/'.$id.'/report', 'GET', ['access_token' => $printToken]);
        $this->assertSame('guest:'.$attempt->anon_id, app(\App\Services\Commerce\MainstreamReportPurchase::class)->trustedReadOwner($request, $attempt));
        $request = \Illuminate\Http\Request::create('/api/v0.3/attempts/'.$id.'/report', 'POST', ['access_token' => $printToken]);
        $this->assertNull(app(\App\Services\Commerce\MainstreamReportPurchase::class)->trustedReadOwner($request, $attempt));
    }

    public function test_native_public_forms_receive_server_owned_enrollment(): void
    {
        $this->seed(\Database\Seeders\ScaleRegistrySeeder::class);
        $this->seed(\Database\Seeders\JungAssessmentIndexabilitySeeder::class);
        config(['payments.career94.enabled' => true]);
        $token = $this->token(true);
        $forms = ['MBTI' => ['mbti_93' => 93, 'mbti_144' => 144], 'BIG5_OCEAN' => ['big5_90' => 90],
            'ENNEAGRAM' => ['enneagram_likert_105' => 105],
            'IQ_RAVEN' => ['IQ_OWNER_ORIGINAL_30' => 30, 'IQ_MERGED_40' => 40], 'EQ_60' => ['eq_60' => 60],
            'JUNG_ARCHETYPES' => ['jung_archetypes_48' => 48], 'JUNG_COGNITIVE_FUNCTIONS' => ['jung_cognitive_functions_64' => 64]];
        foreach ($forms as $scale => $versions) {
            foreach ($versions as $form => $count) {
                $id = $this->withToken($token)->postJson('/api/v0.3/attempts/start', ['scale_code' => $scale, 'form_code' => $form, 'locale' => 'en',
                    'meta' => ['web_report_commerce' => 'off', 'web_report_guest_owner' => 'attacker-value']])->assertOk()->json('attempt_id');
                $attempt = \App\Models\Attempt::withoutGlobalScopes()->findOrFail($id);
                $this->assertSame($count, $attempt->question_count);
                $this->assertTrue(app(\App\Services\Commerce\MainstreamReportPurchase::class)->managed($attempt), $scale.' '.$form.' '.json_encode($attempt->only(['scale_code', 'org_id', 'question_count', 'answers_summary_json'])));
                $this->assertSame('v1', data_get($attempt->answers_summary_json, 'meta.web_report_commerce'));
            }
        }
    }

    public function test_native_jung_and_both_iq_forms_keep_existing_full_reports_after_payment(): void
    {
        $this->seed(\Database\Seeders\ScaleRegistrySeeder::class);
        $this->seed(\Database\Seeders\JungAssessmentIndexabilitySeeder::class);
        config(['payments.career94.enabled' => true, 'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.app_id' => 'fixture', 'pay.alipay.default.seller_id' => 'fixture']);
        $token = $this->token(true);
        foreach (['JUNG_ARCHETYPES' => 'jung_archetypes_48', 'JUNG_COGNITIVE_FUNCTIONS' => 'jung_cognitive_functions_64', 'IQ_RAVEN' => 'IQ_OWNER_ORIGINAL_30', 'IQ_INTELLIGENCE_QUOTIENT' => 'IQ_MERGED_40'] as $scale => $form) {
            $id = $this->withToken($token)->postJson('/api/v0.3/attempts/start', ['scale_code' => $scale, 'form_code' => $form, 'locale' => 'en'])->assertOk()->json('attempt_id');
            $attempt = \App\Models\Attempt::withoutGlobalScopes()->findOrFail($id);
            $answers = [];
            if (str_starts_with($scale, 'IQ_')) {
                $bank = app(\App\Services\Iq\IqOwnerOriginal30BankService::class)->forAttempt($attempt);
                for ($index = 0; $index < $attempt->question_count; $index++) {
                    $item = $bank->publicQuestionPayload($attempt, $index)['questions']['items'][0];
                    $answers[] = ['question_id' => $item['question_id'], 'code' => $item['options'][$index % count($item['options'])]['code']];
                }
            } else {
                $bank = new \App\Services\Assessment\Jung\JungBank($scale);
                foreach ($bank->data['items'] as $index => $item) {
                    $answers[] = ['question_id' => $item['id'], 'code' => (string) (($index % 5) + 1)];
                }
            }
            $this->withToken($token)->postJson('/api/v0.3/attempts/submit', ['attempt_id' => $id, 'duration_ms' => 600000, 'answers' => $answers])->assertOk()
                ->assertJsonPath('report_purchase.unlocked', false)->assertJsonMissingPath('report');
            if (str_starts_with($scale, 'IQ_')) {
                $stored = app(\App\Services\Commerce\MainstreamReportPurchase::class)->result($attempt);
                $score = data_get($stored->result_json, 'breakdown_json.score_result', data_get($stored->result_json, 'axis_scores_json.score_result', $stored->result_json));
                $reference = app(\App\Services\Iq\IqBetaReferenceScore::class)->fromScore($score);
                $this->assertNotNull($reference);
                $this->withToken($token)->getJson('/api/v0.3/attempts/'.$id.'/report')->assertOk()
                    ->assertJsonPath('result.summary.model_reference_score', $reference['display_score'])
                    ->assertJsonMissingPath('report.dimensions')
                    ->assertJsonMissingPath('result.breakdown_json');
            }
            $sku = \App\Services\Commerce\MainstreamReportPurchase::skuMap()[$attempt->scale_code]['CNY'];
            $number = $this->withToken($token)->postJson('/api/v0.3/orders', ['target_attempt_id' => $id, 'sku' => $sku, 'provider' => 'alipay'])->assertOk()->json('order_no');
            $paid = app(\App\Services\Commerce\PaymentWebhookProcessor::class)->process('alipay', ['out_trade_no' => $number, 'trade_no' => 'ali_'.$number,
                'trade_status' => 'TRADE_SUCCESS', 'total_amount' => '2.99', 'notify_id' => 'paid_'.$number]);
            $this->assertTrue($paid['ok']);
            if (str_starts_with($scale, 'IQ_')) {
                $snapshot = app(\App\Services\Report\ReportSnapshotStore::class)->createSnapshotForAttempt(['org_id' => 0, 'attempt_id' => $id,
                    'trigger_source' => 'payment_webhook', 'order_no' => $number, 'user_id' => null, 'anon_id' => $attempt->anon_id]);
                $this->assertTrue($snapshot['ok'] ?? false, json_encode($snapshot));
            }
            $report = $this->withToken($token)->getJson('/api/v0.3/attempts/'.$id.'/report')->assertOk()->assertJsonPath('report_purchase.unlocked', true)->assertJsonPath('locked', false);
            $pdf = $this->withToken($token)->get('/api/v0.3/attempts/'.$id.'/report.pdf');
            $this->assertSame(200, $pdf->status(), $form.' '.($pdf->status() === 200 ? '' : (string) $pdf->json('error_code')));
            $pdf->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Report-Variant', 'full');
            $this->assertStringStartsWith('%PDF-', $pdf->getContent());
            $ownerKey = $this->guestKeys[$token];
            $this->guestKeys[$token] = str_repeat($ownerKey[0] === 'a' ? 'b' : 'a', 64);
            $this->withToken($token)->get('/api/v0.3/attempts/'.$id.'/report.pdf')->assertNotFound();
            $this->guestKeys[$token] = $ownerKey;
            if (str_starts_with($scale, 'JUNG_')) {
                $report->assertJsonStructure(['report' => ['jung' => ['scores', 'reading' => ['archetypes', 'functions']]]]);
            } else {
                $this->assertArrayHasKey('summary', $report->json('report') ?? [], json_encode(['scale' => $scale, 'keys' => array_keys($report->json('report') ?? []), 'meta' => $report->json('meta'), 'generating' => $report->json('generating')]));
            }
        }
    }

    public function test_retired_big5_form_is_not_enrolled_for_new_web_purchases(): void
    {
        $this->seed(\Database\Seeders\ScaleRegistrySeeder::class);
        config(['payments.career94.enabled' => true]);
        $token = $this->token(true);
        $this->withToken($token)->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'BIG5_OCEAN', 'form_code' => 'big5_120', 'locale' => 'en',
        ])->assertNotFound()->assertJsonPath('error_code', 'FORM_NOT_FOUND');
        $this->getJson('/api/v0.3/scales/BIG5_OCEAN/questions?locale=en&form=big5_120')
            ->assertNotFound()->assertJsonPath('error_code', 'FORM_NOT_FOUND');
    }

    public function test_native_personality_and_eq_forms_keep_the_existing_full_report_payload(): void
    {
        $this->seed(\Database\Seeders\ScaleRegistrySeeder::class);
        config(['payments.career94.enabled' => true, 'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.app_id' => 'fixture', 'pay.alipay.default.seller_id' => 'fixture']);
        $token = $this->token(true);
        foreach (['MBTI' => ['mbti_93', 'mbti_144'], 'BIG5_OCEAN' => ['big5_90'],
            'ENNEAGRAM' => ['enneagram_likert_105'], 'EQ_60' => ['eq_60']] as $scale => $forms) {
            foreach ($forms as $form) {
                $questions = $this->getJson('/api/v0.3/scales/'.$scale.'/questions?locale=en&form='.$form)->assertOk()->json('questions.items');
                $id = $this->withToken($token)->postJson('/api/v0.3/attempts/start', ['scale_code' => $scale, 'form_code' => $form, 'locale' => 'en'])->assertOk()->json('attempt_id');
                $attempt = \App\Models\Attempt::withoutGlobalScopes()->findOrFail($id);
                $answers = [];
                foreach ($questions as $index => $item) {
                    $options = $item['options'] ?? ($scale === 'EQ_60' ? array_map(static fn ($code) => ['code' => $code], ['A', 'B', 'C', 'D', 'E']) : []);
                    $this->assertNotEmpty($options, $scale.' '.$form.' '.json_encode(array_keys($item)));
                    $chosen = $options[hexdec(substr(hash('sha256', $form.$index), 0, 4)) % count($options)];
                    $answers[] = ['question_id' => $item['question_id'], 'code' => $chosen['code']];
                }
                $this->travel(12)->minutes();
                $this->withToken($token)->postJson('/api/v0.3/attempts/submit', ['attempt_id' => $id, 'duration_ms' => 600000, 'answers' => $answers])->assertOk()->assertJsonPath('report_purchase.unlocked', false);
                $orderResponse = $this->withToken($token)->postJson('/api/v0.3/orders', ['target_attempt_id' => $id, 'sku' => \App\Services\Commerce\MainstreamReportPurchase::skuMap()[$scale]['CNY'], 'provider' => 'alipay']);
                $scored = \App\Models\Result::withoutGlobalScopes()->where('attempt_id', $id)->first();
                $this->assertSame(200, $orderResponse->status(), $scale.' '.$form.' '.json_encode(['error' => $orderResponse->json('error_code'), 'message' => $orderResponse->json('message'), 'valid' => $scored->is_valid, 'quality' => data_get($scored->result_json, 'quality')]));
                $number = $orderResponse->json('order_no');
                $paid = app(\App\Services\Commerce\PaymentWebhookProcessor::class)->process('alipay', ['out_trade_no' => $number, 'trade_no' => 'ali_'.$number,
                    'trade_status' => 'TRADE_SUCCESS', 'total_amount' => '2.99', 'notify_id' => 'paid_'.$number]);
                $this->assertTrue($paid['ok']);
                $snapshot = app(\App\Services\Report\ReportSnapshotStore::class)->createSnapshotForAttempt(['org_id' => 0, 'attempt_id' => $id, 'trigger_source' => 'payment_webhook',
                    'order_no' => $number, 'user_id' => null, 'anon_id' => $attempt->anon_id]);
                $this->assertTrue($snapshot['ok'] ?? false, $scale.' '.$form.' '.json_encode($snapshot));
                $this->withToken($token)->getJson('/api/v0.3/attempts/'.$id.'/report')->assertOk()->assertJsonPath('locked', false)->assertJsonPath('report_purchase.unlocked', true)->assertJsonMissingPath('meta.snapshot_error');
                if ($scale === 'BIG5_OCEAN') {
                    $pdf = $this->withToken($token)->get('/api/v0.3/attempts/'.$id.'/report.pdf')
                        ->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Report-Variant', 'full');
                    $this->assertStringStartsWith('%PDF-', $pdf->getContent());
                }
            }
        }
    }

    public function test_eq_result_page_uses_real_scores_only_after_purchase(): void
    {
        $this->seed(\Database\Seeders\ScaleRegistrySeeder::class);
        config(['payments.career94.enabled' => true, 'payments.providers.alipay.enabled' => true,
            'pay.alipay.default.app_id' => 'fixture', 'pay.alipay.default.seller_id' => 'fixture']);
        $token = $this->token(true);
        foreach (['EQ_60' => ['eq_60']] as $scale => $forms) {
            foreach ($forms as $form) {
                $questions = $this->getJson('/api/v0.3/scales/'.$scale.'/questions?locale=en&form='.$form)->assertOk()->json('questions.items');
                $id = $this->withToken($token)->postJson('/api/v0.3/attempts/start', ['scale_code' => $scale, 'form_code' => $form, 'locale' => 'en'])->assertOk()->json('attempt_id');
                $attempt = \App\Models\Attempt::withoutGlobalScopes()->findOrFail($id);
                $answers = [];
                foreach ($questions as $index => $item) {
                    $options = $item['options'] ?? ($scale === 'EQ_60' ? array_map(static fn ($code) => ['code' => $code], ['A', 'B', 'C', 'D', 'E']) : []);
                    $this->assertNotEmpty($options, $scale.' '.$form.' '.json_encode(array_keys($item)));
                    $chosen = $options[hexdec(substr(hash('sha256', $form.$index), 0, 4)) % count($options)];
                    $answers[] = ['question_id' => $item['question_id'], 'code' => $chosen['code']];
                }
                $this->travel(12)->minutes();
                $this->withToken($token)->postJson('/api/v0.3/attempts/submit', ['attempt_id' => $id, 'duration_ms' => 600000, 'answers' => $answers])->assertOk()->assertJsonPath('report_purchase.unlocked', false);
                $locked = $this->withToken($token)->getJson('/api/v0.3/attempts/'.$id.'/report')->assertOk();
                $locked->assertJsonPath('locked', true)->assertJsonPath('eq60_unlock_v1.schema', 'eq60.unlock.v1')->assertJsonMissingPath('eq60_result_page_v1');
                $this->assertStringNotContainsString('careers', $locked->getContent());
                $this->assertStringNotContainsString('dimension_cards', $locked->getContent());
                $orderResponse = $this->withToken($token)->postJson('/api/v0.3/orders', ['target_attempt_id' => $id, 'sku' => \App\Services\Commerce\MainstreamReportPurchase::skuMap()[$scale]['CNY'], 'provider' => 'alipay']);
                $scored = \App\Models\Result::withoutGlobalScopes()->where('attempt_id', $id)->first();
                $this->assertSame(200, $orderResponse->status(), $scale.' '.$form.' '.json_encode(['error' => $orderResponse->json('error_code'), 'message' => $orderResponse->json('message'), 'valid' => $scored->is_valid, 'quality' => data_get($scored->result_json, 'quality')]));
                $number = $orderResponse->json('order_no');
                $paid = app(\App\Services\Commerce\PaymentWebhookProcessor::class)->process('alipay', ['out_trade_no' => $number, 'trade_no' => 'ali_'.$number,
                    'trade_status' => 'TRADE_SUCCESS', 'total_amount' => '2.99', 'notify_id' => 'paid_'.$number]);
                $this->assertTrue($paid['ok']);
                $snapshot = app(\App\Services\Report\ReportSnapshotStore::class)->createSnapshotForAttempt(['org_id' => 0, 'attempt_id' => $id, 'trigger_source' => 'payment_webhook',
                    'order_no' => $number, 'user_id' => null, 'anon_id' => $attempt->anon_id]);
                $this->assertTrue($snapshot['ok'] ?? false, $scale.' '.$form.' '.json_encode($snapshot));
                $full = $this->withToken($token)->getJson('/api/v0.3/attempts/'.$id.'/report')->assertOk()->assertJsonPath('locked', false)->assertJsonPath('report_purchase.unlocked', true);
                $full->assertJsonPath('eq60_result_page_v1.schema', 'eq60.result_page.v1');
                $this->assertSame($full->json('report.interpretation.core_formulation_id'), $full->json('eq60_result_page_v1.result_id'));
                $page = $full->json('eq60_result_page_v1');
                foreach (['en', 'zh-CN', 'zh-TW'] as $language) {
                    if ($page['quality'] === null) {
                        $this->assertCount(8, $page['copy'][$language]['careers']);
                        foreach ($page['copy'][$language]['dimensions'] as $dimension) {
                            $this->assertEquals($full->json('report.scores.dimensions.'.$dimension['code'].'.standard_score'), $dimension['score']);
                        }
                    }
                }
                $this->assertStringNotContainsString('raw_scores', json_encode($page));
                config(['scale_identity.api_response_scale_code_mode' => 'v2']);
                $aliased = $this->withToken($token)->getJson('/api/v0.3/attempts/'.$id.'/report')->assertOk();
                $aliased->assertJsonPath('scale_code', 'EQ_EMOTIONAL_INTELLIGENCE');
                $this->assertSame($page, $aliased->json('eq60_result_page_v1'));

            }
        }
    }

    public function test_history_and_public_summary_do_not_expose_paid_report_content(): void
    {
        config(['payments.career94.enabled' => true]);
        $token = $this->token(true);
        $id = $this->fixture($token, 'MBTI', 93, 'en');
        $response = $this->withToken($token)->getJson('/api/v0.3/me/attempts')->assertOk();
        $this->assertStringNotContainsString('NEVER_TRANSPORT_THIS', $response->getContent());
        $this->assertStringContainsString($id, $response->getContent());
        $this->withToken($token)->withHeader('X-Career-Guest-Token', str_repeat('0', 64))->getJson('/api/v0.3/me/attempts')->assertOk()->assertJsonMissing(['attempt_id' => $id]);
        $attempt = \App\Models\Attempt::withoutGlobalScopes()->findOrFail($id);
        $result = \App\Models\Result::withoutGlobalScopes()->where('attempt_id', $id)->firstOrFail();
        $summary = app(\App\Services\V0_3\ShareService::class)->buildPublicSummaryPayload($attempt, $result);
        $this->assertNull($summary['summary']);
        $this->assertEmpty($summary['dimensions']);
        $this->assertFalse($summary['compare_enabled']);
        $this->assertStringNotContainsString('NEVER_TRANSPORT_THIS', json_encode($summary));
        $this->withToken($token)->postJson('/api/v0.3/attempts/'.$id.'/share')->assertForbidden();
    }

    private function fixture(string $token, string $scale, int $count, string $locale): string
    {
        $actor = DB::table('auth_tokens')->where('token_hash', hash('sha256', $token))->first();
        $id = (string) Str::uuid();
        \App\Models\Attempt::withoutGlobalScopes()->create(['id' => $id, 'org_id' => 0, 'user_id' => null, 'anon_id' => $actor->anon_id, 'scale_code' => $scale,
            'scale_version' => 'v0.3', 'client_platform' => 'web', 'region' => 'CN_MAINLAND', 'locale' => $locale, 'question_count' => $count, 'started_at' => now(), 'submitted_at' => now(),
            'answers_summary_json' => ['meta' => ['web_report_commerce' => 'v1', 'web_report_guest_owner' => CareerAssessmentPurchase::guestAnonId(new \Illuminate\Http\Request([], [], [], [], [], ['HTTP_X_CAREER_GUEST_TOKEN' => $this->guestKeys[$token]]))]]]);
        \App\Models\Result::withoutGlobalScopes()->create(['id' => (string) Str::uuid(), 'attempt_id' => $id, 'org_id' => 0, 'scale_code' => $scale, 'scale_version' => 'v0.3',
            'scores_json' => [], 'scores_pct' => ['I' => 75, 'A' => 65], 'type_code' => 'fixture', 'result_json' => ['private_secret' => 'NEVER_TRANSPORT_THIS', 'status' => 'scored'], 'computed_at' => now()]);

        return $id;
    }

    private function token(bool $guest = false): string
    {
        $token = 'fm_'.Str::uuid();
        if ($guest) {
            $this->guestKeys[$token] = bin2hex(random_bytes(32));
        }
        DB::table('auth_tokens')->insert([
            'anon_id' => (string) Str::uuid(), 'token_hash' => hash('sha256', $token), 'user_id' => $guest ? null : User::factory()->create()->getKey(),
            'org_id' => 0, 'role' => 'public', 'expires_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $token;
    }

    private function configureWaffo(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $this->keyFile = tempnam(sys_get_temp_dir(), 'career94-test-');
        openssl_pkey_export_to_file($key, $this->keyFile);
        chmod($this->keyFile, 0600);
        config([
            'app.frontend_url' => 'https://fermatmind.com',
            'payments.career94.enabled' => true,
            'payments.providers.waffo.enabled' => true,
            'payments.waffo' => ['environment' => 'test', 'merchant_id' => 'MER_fixture', 'store_id' => 'STO_fixture',
                'career94_product_id' => 'PROD_career94', 'private_key_path' => $this->keyFile,
                'webhook_public_key' => openssl_pkey_get_details($key)['key']],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.waffo.ai/v1/graphql' => Http::response(['data' => ['onetimeOrders' => []]]),
            'https://api.waffo.ai/v1/actions/checkout/create-session' => function () {
                $ref = 'cs_'.Str::random(20);

                return Http::response(['data' => ['checkoutUrl' => 'https://pancake.waffo.ai/store/fixture/checkout/'.$ref.'?env=test',
                    'sessionId' => $ref, 'expiresAt' => now()->addMinutes(30)->toIso8601String()]]);
            },
        ]);

        return $key;
    }

    private function deliver(array $event, \OpenSSLAsymmetricKey $key, bool $tamper = false)
    {
        $raw = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = (int) floor(microtime(true) * 1000);
        openssl_sign($timestamp.'.'.$raw, $signature, $key, OPENSSL_ALGO_SHA256);

        return $this->call('POST', '/api/v0.3/webhooks/payment/waffo', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_WAFFO_SIGNATURE' => 't='.$timestamp.',v1='.base64_encode($signature),
        ], $raw.($tamper ? ' ' : ''));
    }
}
