<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\DTO\Commerce\PurchaseActor;
use App\Http\Requests\PurchaseRequestActor;
use App\Models\Attempt;
use App\Models\Order;
use App\Models\Result;
use App\Services\Analytics\ResultVisibilityObservation;
use App\Services\Iq\IqBetaReferenceScore;
use App\Services\Report\Pdf\CompleteAssessmentPdf;
use App\Services\Report\Pdf\ReportPdfDocumentService;
use Illuminate\Http\Request;

/** Web-only, per-attempt purchases; old attempts keep their original access policy. */
final class MainstreamReportPurchase
{
    public const COUNTS = [
        'MBTI' => [93, 144], 'BIG5_OCEAN' => [90, 120], 'ENNEAGRAM' => [105, 144],
        'IQ_RAVEN' => [30, 40], 'IQ_INTELLIGENCE_QUOTIENT' => [30, 40], 'EQ_60' => [60],
        'JUNG_ARCHETYPES' => [48], 'JUNG_COGNITIVE_FUNCTIONS' => [64], 'DISC_BEHAVIOR' => [24],
    ];

    public static function supports(string $scale): bool
    {
        return isset(self::COUNTS[$scale]);
    }

    public static function supportsForm(string $scale, ?string $form, int $count): bool
    {
        return in_array($count, self::COUNTS[$scale] ?? [], true);
    }

    public static function skuMap(): array
    {
        $map = [];
        foreach (array_keys(self::COUNTS) as $scale) {
            $map[$scale] = ['CNY' => $scale.'_WEB_REPORT_CNY_299', 'USD' => $scale.'_WEB_REPORT_USD_299'];
        }

        return $map;
    }

    public static function skus(): array
    {
        return array_merge(...array_values(array_map('array_values', self::skuMap())));
    }

    public function managed(?Attempt $attempt): bool
    {
        // MBTI follows its native free-full policy, including attempts enrolled
        // before this exception. Ownership remains enforced by native readers.
        return $attempt !== null
            && ! \App\Support\Mbti\MbtiReportAccessPolicy::grantsFreeFull((string) $attempt->scale_code)
            && (int) $attempt->org_id === 0
            && self::supportsForm((string) $attempt->scale_code, null, (int) $attempt->question_count)
            && data_get($attempt->answers_summary_json, 'meta.web_report_commerce') === 'v1';
    }

    public function guestOwns(Request $request, Attempt $attempt): bool
    {
        return $this->matchesGuest(PurchaseRequestActor::guestAnonId($request), $attempt);
    }

    public function matchesGuest(?string $actual, Attempt $attempt): bool
    {
        $expected = data_get($attempt->answers_summary_json, 'meta.web_report_guest_owner');

        return is_string($expected) && $expected !== '' && $actual !== null && hash_equals($expected, $actual);
    }

    public function owner(Request $request, Attempt $attempt): string
    {
        abort_unless((int) $request->attributes->get('fm_org_id', 0) === 0, 404);
        if (($reader = $this->trustedReadOwner($request, $attempt)) !== null) {
            return $reader;
        }

        return $this->ownerForActor(PurchaseRequestActor::from($request), $attempt);
    }

    public function ownerForActor(PurchaseActor $actor, Attempt $attempt): string
    {
        abort_unless($actor->orgId === 0, 404);
        $user = $actor->userId;
        $anon = $actor->anonId;
        if ($user !== null && (string) $user === (string) $attempt->user_id) {
            return (string) $user;
        }
        abort_unless($attempt->user_id === null && is_string($anon) && $anon !== ''
            && hash_equals((string) $attempt->anon_id, $anon) && $this->matchesGuest($actor->guestAnonId, $attempt), 404);

        return 'guest:'.$anon;
    }

    public function trustedReadOwner(Request $request, Attempt $attempt): ?string
    {
        if (! $request->isMethod('GET') || ! preg_match('~^api/v0\.3/attempts/'.preg_quote((string) $attempt->id, '~').'/(result|report|report-access)$~D', $request->path())) {
            return null;
        }
        $actor = app(\App\Services\Results\ResultEmailReadAccessService::class)->tokenActorForRequest($request, 0, (string) $attempt->id);
        if (is_array($actor) && (($attempt->user_id !== null && (string) $actor['user_id'] === (string) $attempt->user_id)
            || ($attempt->user_id === null && is_string($actor['anon_id']) && hash_equals((string) $attempt->anon_id, $actor['anon_id'])))) {
            return $attempt->user_id !== null ? (string) $attempt->user_id : 'guest:'.$attempt->anon_id;
        }
        $token = $request->header('X-Result-Access-Token') ?? $request->query('result_access_token') ?? $request->query('access_token');
        if (! is_string($token) || $token === '') {
            return null;
        }
        $grant = app(\App\Services\Report\Pdf\ResultPagePdfTokenService::class)->verifyMbtiResultPageExport($token, 0, (string) $attempt->id);
        if (is_array($grant) && ($grant['entitlement'] ?? '') === 'unlocked') {
            return $attempt->user_id !== null ? (string) $attempt->user_id : 'guest:'.$attempt->anon_id;
        }

        return null;
    }

    public function eligible(Attempt $attempt): bool
    {
        if (! $this->managed($attempt) || $attempt->submitted_at === null) {
            return false;
        }
        $result = $this->result($attempt);
        $status = $result ? data_get($result->result_json, 'status', data_get($result->result_json, 'breakdown_json.score_result.status', '')) : '';
        if (! $result || $result->is_valid === false || in_array(strtolower((string) $status), ['blocked_unscored', 'invalid', 'incomplete'], true)) {
            return false;
        }

        return ! in_array(strtolower((string) data_get($result->result_json, 'quality.level', '')), ['invalid', 'd'], true);
    }

    public function result(Attempt $attempt): ?Result
    {
        return Result::withoutGlobalScopes()->where('org_id', 0)->where('attempt_id', $attempt->id)
            ->where('scale_code', $attempt->scale_code)->first();
    }

    public function purchase(Request $request, Attempt $attempt): array
    {
        $owner = $this->owner($request, $attempt);
        $commerce = app(CareerAssessmentPurchase::class);
        $unlocked = $commerce->hasAccess((string) $attempt->id, $owner);
        $order = $commerce->latestOrder((string) $attempt->id);
        $state = $order ? Order::normalizePaymentState($order->payment_state, $order->status) : null;

        return ['attempt_id' => $attempt->id, 'unlocked' => $unlocked, 'order_no' => $order->order_no ?? null,
            'payment_state' => $state, 'offers' => ! $unlocked && (! $order || $state === 'canceled') && $this->eligible($attempt)
                ? $commerce->offers((string) $attempt->region, (string) $attempt->scale_code) : []];
    }

    public function overview(Request $request, Attempt $attempt, ?array $purchase = null): array
    {
        return $this->overviewForPurchase($attempt, $purchase ?? $this->purchase($request, $attempt));
    }

    public function overviewForPurchase(Attempt $attempt, array $purchase): array
    {
        $result = $this->result($attempt);
        $scores = $result?->scores_pct;
        $scores = is_array($scores) ? array_filter($scores, static fn ($value) => is_numeric($value)) : [];
        $source = (array) ($result?->result_json ?? []);
        $score = data_get($source, 'breakdown_json.score_result', data_get($source, 'axis_scores_json.score_result', $source));
        $score = is_array($score) ? $score : [];
        if (str_starts_with((string) $attempt->scale_code, 'IQ_')) {
            $scores = [];
        }
        // Only numeric score values and diagnostic state belong in the unpaid response.
        $summary = [];
        foreach (['raw_score', 'correct', 'total', 'percent_correct', 'duration_ms'] as $key) {
            $value = $score[$key] ?? $source[$key] ?? data_get($source, 'summary.'.$key);
            if (is_numeric($value)) {
                $summary[$key] = $value;
            }
        }
        if (str_starts_with((string) $attempt->scale_code, 'IQ_')) {
            $correct = $score['correct_count'] ?? null;
            $total = $score['expected_item_count'] ?? $attempt->question_count;
            if (is_numeric($correct) && is_numeric($total) && $total > 0) {
                $summary['correct'] = (int) $correct;
                $summary['total'] = (int) $total;
                $summary['percent_correct'] = round($correct / $total * 100, 1);
                $reference = $result?->is_valid !== false
                    ? app(IqBetaReferenceScore::class)->fromScore($score)
                    : null;
                if ($reference !== null && $reference['raw_score'] === $summary['correct']
                    && $attempt->question_count === $summary['total']) {
                    $summary['model_reference_score'] = $reference['display_score'];
                }
            }
        }
        if (is_numeric($attempt->duration_ms)) {
            $summary['duration_ms'] = (int) $attempt->duration_ms;
        }
        $quality = data_get($source, 'quality.level', data_get($score, 'quality.level'));
        $type = $result?->type_code;
        $receipt = $attempt->scale_code !== 'MBTI' && $result && $purchase['offers'] !== []
            ? app(ResultVisibilityObservation::class)->issue($attempt, $result, purchaseEligible: true)
            : null;

        $overview = ['ok' => true, 'attempt_id' => $attempt->id, 'scale_code' => $attempt->scale_code,
            'meta' => ['scale_code' => $attempt->scale_code, 'form_code' => data_get($attempt->answers_summary_json, 'meta.form_code')],
            'result' => ['scale_code' => $attempt->scale_code, 'type_code' => $type, 'scores_pct' => $scores, 'summary' => $summary,
                'quality' => is_string($quality) ? ['level' => $quality] : null],
            'report_purchase' => $purchase,
            ...($receipt === null ? [] : ['result_visibility_receipt' => $receipt]),
            'locked' => true, 'access_level' => 'free', 'variant' => 'free',
            'free_full_report_mode' => false, 'paywall_suppressed' => false];
        if ($attempt->scale_code === 'EQ_60') {
            $eqScore = is_array($source['normed_json'] ?? null) ? $source['normed_json'] : $score;
            $teaser = app(\App\Services\Eq\Eq60ResultPageProjection::class)->unlock($eqScore);
            if ($teaser !== null) {
                $overview['eq60_unlock_v1'] = $teaser;
            }
        }

        return $overview;
    }

    public function access(Request $request, Attempt $attempt, array $purchase): array
    {
        $paid = $purchase['unlocked'];
        $complete = $this->result($attempt) !== null;

        return ['ok' => true, 'attempt_id' => $attempt->id, 'scale_code' => $attempt->scale_code,
            'access_state' => $complete ? ($paid ? 'ready' : 'locked') : 'pending',
            'report_state' => $complete ? 'ready' : 'pending', 'pdf_state' => $paid ? 'ready' : 'missing',
            'complete_pdf' => $complete && $paid && CompleteAssessmentPdf::supports((string) $attempt->scale_code),
            ...(ReportPdfDocumentService::supportsTraditionalReportPdf($attempt) ? ['pdf_display_locales' => ['zh-TW']] : []),
            'projection_version' => 1, 'reason_code' => $paid ? 'payment_verified' : 'payment_required',
            'actions' => ['page_href' => '/result/'.$attempt->id, 'pdf_href' => $paid ? '/api/v0.3/attempts/'.$attempt->id.'/report.pdf' : null, 'wait_href' => $complete ? null : '/result/'.$attempt->id, 'history_href' => null, 'lookup_href' => '/orders/lookup'],
            'report_purchase' => $purchase,
            'payload' => ['locked' => ! $paid, 'paywall_suppressed' => false, 'free_full_report_mode' => false]];
    }
}
