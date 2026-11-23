<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTO\Commerce\PurchaseActor;
use App\Models\Attempt;
use App\Services\Commerce\MainstreamReportPurchase;
use Illuminate\Http\Request;

final class PurchaseRequestActor
{
    public static function from(Request $request, ?Attempt $reportAttempt = null): PurchaseActor
    {
        $user = $request->attributes->get('fm_user_id');
        $anon = $request->attributes->get('fm_anon_id');
        $trustedOwner = null;
        if ($reportAttempt !== null) {
            $purchase = app(MainstreamReportPurchase::class);
            if ($purchase->managed($reportAttempt)) {
                $trustedOwner = self::trustedReadOwner($request, $reportAttempt);
            }
        }

        return new PurchaseActor(
            (int) $request->attributes->get('fm_org_id', 0),
            $user !== null ? (string) $user : null,
            is_string($anon) ? $anon : null,
            self::guestAnonId($request),
            $trustedOwner !== null ? (string) $reportAttempt->id : null,
            $trustedOwner,
        );
    }

    public static function trustedReadOwner(Request $request, Attempt $attempt): ?string
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

    public static function guestAnonId(Request $request): ?string
    {
        $key = (string) $request->header('X-Career-Guest-Token', '');

        return preg_match('/^[a-f0-9]{64}$/D', $key) === 1 ? 'c94_'.substr(hash('sha256', $key), 0, 48) : null;
    }
}
