<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\DTO\Commerce\PurchaseActor;
use App\Http\Requests\PurchaseRequestActor;
use App\Models\Attempt;
use App\Models\Order;
use App\Models\Result;
use App\Services\Analytics\ResultVisibilityObservation;
use App\Services\Riasec\RiasecPublicProjectionService;
use Illuminate\Http\Request;

final class RiasecReportPurchase
{
    public function managed(Attempt $attempt): bool
    {
        return (int) $attempt->org_id === 0 && $attempt->scale_code === 'RIASEC'
            && (int) $attempt->question_count === 60
            && data_get($attempt->answers_summary_json, 'meta.riasec60_commerce') === 'v1';
    }

    public function eligible(Attempt $attempt): bool
    {
        $result = Result::withoutGlobalScopes()->where('org_id', 0)->where('attempt_id', $attempt->id)->where('scale_code', 'RIASEC')->first();

        return $this->managed($attempt) && $attempt->submitted_at !== null && $result
            && data_get($result->result_json, 'form_code') === 'riasec_60';
    }

    public function guestOwns(Request $request, Attempt $attempt): bool
    {
        return $this->matchesGuest(PurchaseRequestActor::guestAnonId($request), $attempt);
    }

    public function matchesGuest(?string $actual, Attempt $attempt): bool
    {
        $expected = data_get($attempt->answers_summary_json, 'meta.riasec60_guest_owner');

        return is_string($expected) && $expected !== '' && $actual !== null && hash_equals($expected, $actual);
    }

    public function owner(Request $request, Attempt $attempt): string
    {
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
        abort_unless($attempt->user_id === null && is_string($anon) && $anon !== '' && hash_equals((string) $attempt->anon_id, $anon) && $this->matchesGuest($actor->guestAnonId, $attempt), 404);

        return 'guest:'.$anon;
    }

    public function present(Request $request, Attempt $attempt): array
    {
        abort_unless($this->eligible($attempt), 404);
        $owner = $this->owner($request, $attempt);
        $purchase = app(CareerAssessmentPurchase::class);
        $unlocked = $purchase->hasAccess((string) $attempt->id, $owner);
        $result = Result::withoutGlobalScopes()->where('org_id', 0)->where('attempt_id', $attempt->id)->where('scale_code', 'RIASEC')->firstOrFail();
        $projection = app(RiasecPublicProjectionService::class)->buildV2FromResult($result, $attempt->locale, true, true);
        if (! $unlocked) {
            // The locked overview never transports private interpretations or report/PDF authority.
            $projection = array_intersect_key($projection, array_flip(['schema_version', 'locale', 'form', 'holland_code', 'scores', 'quality', 'interpretation_state', 'result_summary_v1', 'module_visibility_policy', 'content_boundary']));
            $projection['module_visibility_policy']['modules'] = array_values(array_filter($projection['module_visibility_policy']['modules'] ?? [], static fn (array $row): bool => in_array($row['key'] ?? '', ['six_dimension_map', 'hero_activity_chain'], true)));
        }
        $order = $purchase->latestOrder((string) $attempt->id);
        $state = $order ? Order::normalizePaymentState($order->payment_state, $order->status) : null;
        $offers = $unlocked || ($order && $state !== 'canceled') ? [] : $purchase->offers($attempt->region, 'RIASEC');

        return [
            'ok' => true, 'scale_code' => 'RIASEC', 'meta' => ['scale_code' => 'RIASEC'], 'type_code' => $result->type_code,
            'result_visibility_receipt' => app(ResultVisibilityObservation::class)->issue($attempt, $result, $offers !== []),
            'attempt_id' => $attempt->id, 'result' => ['scale_code' => 'RIASEC', 'type_code' => $result->type_code, 'scores_pct' => $result->scores_pct],
            'riasec_public_projection_v2' => $projection,
            'riasec_purchase' => ['attempt_id' => $attempt->id, 'unlocked' => $unlocked,
                'offers' => $offers, 'order_no' => $order->order_no ?? null, 'payment_state' => $state],
            ...($unlocked && isset($projection['private_result_authority']) ? ['riasec_private_result_authority' => $projection['private_result_authority']] : []),
        ];
    }

    public function sample(string $locale): array
    {
        // One fixed fictional profile for all visitors; never accepts answers, attempt IDs or scores.
        $scores = ['R' => 70, 'I' => 75, 'A' => 75, 'S' => 57.5, 'E' => 22.5, 'C' => 0];
        $result = new Result(['scale_code' => 'RIASEC', 'type_code' => 'IAR', 'scores_pct' => $scores,
            'result_json' => ['top_code' => 'IAR', 'primary_type' => 'I', 'secondary_type' => 'A', 'tertiary_type' => 'R',
                'form_code' => 'riasec_60', 'answer_count' => 60, 'scoring_spec_version' => 'riasec_60_likert5_activity_sum_space.v1', 'scores_0_100' => $scores]]);

        return ['ok' => true, 'sample' => true, 'scale_code' => 'RIASEC',
            'riasec_public_projection_v2' => app(RiasecPublicProjectionService::class)->buildV2FromResult($result, $locale, true, true)];
    }
}
