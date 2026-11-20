<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Assessment\DiscReport;
use App\Services\Commerce\CareerAssessmentPurchase;
use App\Services\Commerce\MainstreamReportPurchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DiscAssessmentController
{
    public function store(Request $request, DiscReport $reports, MainstreamReportPurchase $commerce): JsonResponse
    {
        $data = $request->validate(['answers' => 'required|array|size:24', 'locale' => 'required|in:zh-CN,zh-TW,en', 'client_ref' => 'required|uuid']);
        abort_unless((int) $request->attributes->get('fm_org_id', 0) === 0 && config('payments.career94.enabled'), 404);
        $user = $request->attributes->get('fm_user_id');
        $anon = $request->attributes->get('fm_anon_id');
        $private = CareerAssessmentPurchase::guestAnonId($request);
        abort_unless($user !== null || (is_string($anon) && $anon !== '' && $private !== null), 422, 'A private browser credential is required.');
        $score = $reports->score($data['answers']);
        $digest = hash('sha256', 'disc|'.($user ?? $anon).'|'.($user !== null ? 'account' : $private).'|'.$data['client_ref']);
        // The native attempts table has no unique client_ref column. Serialize just
        // this actor/ref to make retries idempotent without a schema mutation.
        $attempt = Cache::lock('disc:submit:'.$digest, 15)->block(5, function () use ($data, $digest, $score, $user, $anon, $private, $reports) {
            return DB::transaction(function () use ($data, $digest, $score, $user, $anon, $private, $reports) {
                $existing = Attempt::withoutGlobalScopes()->where('org_id', 0)->where('scale_code', 'DISC_BEHAVIOR')->where('answers_digest', $digest)->first();
                if ($existing) {
                    return $existing;
                }
                $bank = $reports->source();
                $attempt = Attempt::withoutGlobalScopes()->create(['id' => (string) Str::uuid(), 'org_id' => 0, 'anon_id' => $anon ?? 'user:'.$user, 'user_id' => $user,
                    'scale_code' => 'DISC_BEHAVIOR', 'scale_version' => $bank['version'], 'region' => 'CN_MAINLAND', 'locale' => $data['locale'],
                    'question_count' => 24, 'client_platform' => 'web', 'started_at' => now(), 'submitted_at' => now(), 'answers_digest' => $digest,
                    'answers_summary_json' => ['meta' => ['form_code' => $bank['source']['formCode'], 'web_report_commerce' => 'v1', 'web_report_guest_owner' => $private]],
                ]);
                Result::withoutGlobalScopes()->create(['id' => (string) Str::uuid(), 'attempt_id' => $attempt->id, 'org_id' => 0, 'scale_code' => 'DISC_BEHAVIOR',
                    'scale_version' => $bank['version'], 'type_code' => $score['profile_id'], 'scores_json' => [], 'scores_pct' => $score['counts'],
                    'result_json' => $score + ['form_code' => $bank['source']['formCode']], 'is_valid' => true, 'computed_at' => now()]);

                return $attempt;
            });
        });

        return response()->json($commerce->overview($request, $attempt), 200, ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
