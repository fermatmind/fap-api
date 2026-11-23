<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Requests\PurchaseRequestActor;
use App\Models\Attempt;
use App\Services\Commerce\MainstreamReportPurchase;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MainstreamReportAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->route('id') ?? $request->route('attempt_id') ?? $request->input('attempt_id');
        if (! is_string($id) || ! \Illuminate\Support\Str::isUuid($id)) {
            return $next($request);
        }
        $attempt = Attempt::withoutGlobalScopes()->where('org_id', 0)->whereKey($id)->first();
        $commerce = app(MainstreamReportPurchase::class);
        if (! $commerce->managed($attempt)) {
            return $next($request);
        }

        // Native lifecycle readers have optional authentication. Paid cohorts always
        // validate the database-backed token before using its injected actor.
        $guard = function (Request $request) use ($next, $attempt, $commerce) {
            $commerce->owner($request, $attempt);
            $name = (string) $request->route()?->getName();
            if (str_contains($name, 'invite_unlock') || str_contains($name, 'rewarded_ad') || str_contains($name, 'gift_request')) {
                abort(403, 'This report requires payment.');
            }
            $purchase = $commerce->purchase($request, $attempt);
            if ($name === 'api.v0_3.attempts.report_access') {
                return $this->json($commerce->access($request, $attempt, $purchase));
            }
            $read = in_array($name, ['api.v0_3.attempts.result', 'api.v0_3.attempts.report'], true);
            if ($read && $commerce->result($attempt)) {
                if (! $purchase['unlocked']) {
                    return $this->json($commerce->overview($request, $attempt, $purchase));
                }
                if ($attempt->scale_code === 'DISC_BEHAVIOR') {
                    return $this->json(app(\App\Services\Assessment\DiscReport::class)->present($request, $attempt, $purchase));
                }
            }
            if (! $purchase['unlocked'] && (str_contains($request->path(), '.pdf')
                || str_contains($request->path(), '/eq/') || str_contains($request->path(), '/enneagram/')
                || str_ends_with($request->path(), '/share'))) {
                abort(403, 'Payment is required.');
            }
            $response = $next($request);
            if ($name === 'api.v0_3.attempts.result_observation') {
                return $response;
            }

            if ($response instanceof JsonResponse && $response->isSuccessful()) {
                $payload = $response->getData(true);
                if (is_array($payload)) {
                    if (! $purchase['unlocked'] && $commerce->result($attempt)) {
                        $safe = $commerce->overview($request, $attempt);
                        // Submission status is transport state, never report content.
                        if (isset($payload['submission'])) {
                            $safe['submission'] = array_intersect_key((array) $payload['submission'], array_flip(['id', 'state', 'retry_after_ms']));
                        }

                        return $this->json($safe, $response->getStatusCode());
                    }
                    unset($payload['locale_freemium_policy']);
                    $payload['report_purchase'] = $commerce->purchase($request, $attempt);

                    return $this->json($payload, $response->getStatusCode());
                }
            }

            return $response;
        };
        if (PurchaseRequestActor::trustedReadOwner($request, $attempt) !== null) {
            return $guard($request);
        }

        return app(FmTokenAuth::class)->handle($request, $guard);
    }

    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
