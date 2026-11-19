<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Models\Attempt;
use App\Models\Order;
use App\Services\Assessment\CareerAssessmentDeepReport;
use App\Services\Assessment\Scorers\CareerAssessmentScorer;
use App\Services\Commerce\CareerAssessmentPurchase;
use App\Services\Riasec\RiasecPublicProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

final class CareerAssessmentController
{
    public function questions(CareerAssessmentScorer $scorer): JsonResponse
    {
        return $this->respond($scorer->questionnaire());
    }

    public function score(Request $request, CareerAssessmentScorer $scorer): JsonResponse
    {
        return $this->submit($request, $scorer, false);
    }

    public function store(Request $request, CareerAssessmentScorer $scorer): JsonResponse
    {
        return $this->submit($request, $scorer, true);
    }

    public function show(Request $request, string $id, CareerAssessmentPurchase $purchase): JsonResponse
    {
        $userId = $this->ownerId($request);
        $attempt = $userId !== null ? CareerAssessmentPurchase::scopeOwner(Attempt::withoutGlobalScopes()->whereKey($id), $userId)
            ->where('org_id', 0)->where('scale_code', CareerAssessmentPurchase::SCALE)
            ->whereNotNull('submitted_at')->first() : null;
        if (! $attempt && ($anon = CareerAssessmentPurchase::guestAnonId($request)) !== null) {
            $userId = 'guest:'.$anon;
            $attempt = CareerAssessmentPurchase::scopeOwner(Attempt::withoutGlobalScopes()->whereKey($id), $userId)
                ->where('org_id', 0)->where('scale_code', CareerAssessmentPurchase::SCALE)->whereNotNull('submitted_at')->first();
        }
        if (! $attempt) {
            return $this->respond(['error_code' => 'ASSESSMENT_NOT_FOUND'], 404);
        }

        return $this->respond($this->publicResult($attempt, $purchase, $purchase->hasAccess((string) $attempt->id, $userId)));
    }

    private function submit(Request $request, CareerAssessmentScorer $scorer, bool $persist): JsonResponse
    {
        if (! $request->isJson()) {
            return $this->respond(['error_code' => 'EXPECTED_JSON'], 415);
        }

        try {
            $payload = json_decode($request->getContent(), associative: true, depth: 8, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->respond(['error_code' => 'INVALID_JSON'], 400);
        }

        $formVersion = $payload['form_version'] ?? null;
        $answers = $payload['answers'] ?? null;
        $items = array_column($scorer->questionnaire()['items'], null, 'id');
        if (! is_string($formVersion) || ! is_array($answers) || array_diff_key($items, $answers) !== []) {
            return $this->respond(['error_code' => 'INCOMPLETE_ASSESSMENT'], 422);
        }

        try {
            $scores = $scorer->score($formVersion, $answers);
        } catch (InvalidArgumentException) {
            return $this->respond(['error_code' => 'INVALID_ASSESSMENT'], 422);
        }

        $locale = ($payload['locale'] ?? null) === 'en' ? 'en' : 'zh-CN';
        if ($persist) {
            return $this->persist($request, $payload, $answers, $scores, $locale);
        }

        if (($payload['include_report'] ?? false) === true) {
            $scores['riasec_public_projection_v2'] = app(RiasecPublicProjectionService::class)
                ->buildForCareerAssessment($scores['axes']['interest'], $locale);
            $purchase = app(CareerAssessmentPurchase::class);
            if ($scores['interest_shape']['state'] === 'differentiated') {
                $scores['purchase_markets'] = array_values(array_filter(
                    ['CN_MAINLAND', 'INTERNATIONAL'],
                    static fn (string $market): bool => $purchase->offers($market) !== []
                ));
                $scores['purchase_offers'] = array_merge(...array_map(
                    static fn (string $market): array => $purchase->offers($market),
                    $scores['purchase_markets']
                ));
            }
        }

        return $this->respond($scores);
    }

    private function persist(Request $request, array $payload, array $answers, array $scores, string $locale): JsonResponse
    {
        $userId = $this->ownerId($request);
        if ($userId === null) {
            return $this->respond(['error_code' => 'ACCOUNT_REQUIRED'], 403);
        }

        $id = $payload['submission_id'] ?? null;
        $market = $payload['market'] ?? null;
        if (! is_string($id) || ! Str::isUuid($id) || ! in_array($market, ['CN_MAINLAND', 'INTERNATIONAL'], true)) {
            return $this->respond(['error_code' => 'INVALID_SUBMISSION'], 422);
        }

        ksort($answers);
        $digest = hash('sha256', json_encode($answers, JSON_THROW_ON_ERROR));
        $attempt = Attempt::withoutGlobalScopes()->firstOrCreate(['id' => strtolower($id)], [
            'org_id' => 0,
            'user_id' => str_starts_with($userId, 'guest:') ? null : $userId,
            'anon_id' => str_starts_with($userId, 'guest:') ? substr($userId, 6) : (string) Str::uuid(),
            'scale_code' => CareerAssessmentPurchase::SCALE,
            'scale_version' => $scores['form_version'],
            'question_count' => 94,
            'answers_summary_json' => [],
            'answers_digest' => $digest,
            'client_platform' => 'web',
            'region' => $market,
            'locale' => $locale,
            'scoring_spec_version' => $scores['model_version'],
            'submitted_at' => now(),
            'result_json' => $scores,
        ]);
        if (! CareerAssessmentPurchase::owns($attempt, $userId) || (int) $attempt->org_id !== 0
            || $attempt->scale_code !== CareerAssessmentPurchase::SCALE
            || $attempt->answers_digest !== $digest || $attempt->region !== $market) {
            return $this->respond(['error_code' => 'SUBMISSION_CONFLICT'], 409);
        }

        return $this->show($request, (string) $attempt->id, app(CareerAssessmentPurchase::class));
    }

    private function ownerId(Request $request): ?string
    {
        return CareerAssessmentPurchase::ownerKey($request);
    }

    private function publicResult(Attempt $attempt, CareerAssessmentPurchase $purchase, bool $unlocked): array
    {
        $result = $attempt->result_json;
        $result['riasec_public_projection_v2'] = app(RiasecPublicProjectionService::class)
            ->buildForCareerAssessment($result['axes']['interest'], $attempt->locale);

        $order = $purchase->latestOrder($attempt->id);
        $state = $order ? Order::normalizePaymentState($order->payment_state, $order->status) : null;
        $offers = $unlocked || ($result['interest_shape']['state'] ?? '') !== 'differentiated' ? [] : $purchase->offers($attempt->region);
        if ($order && $state !== 'canceled') {
            $offers = in_array($state, ['created', 'pending'], true)
                ? array_values(array_filter($offers, static fn (array $offer): bool => $offer['provider'] === $order->provider))
                : [];
        }

        return [
            'attempt_id' => $attempt->id,
            'market' => $attempt->region,
            'result' => $result,
            'unlocked' => $unlocked,
            ...($unlocked ? ['premium' => app(CareerAssessmentDeepReport::class)->build($result, $attempt->locale)] : []),
            'offers' => $offers,
            'order_no' => $order->order_no ?? null,
            'payment_state' => $state,
        ];
    }

    private function respond(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, [
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
