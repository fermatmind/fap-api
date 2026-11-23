<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTO\Commerce\PurchaseActor;
use App\Services\Commerce\CareerAssessmentPurchase;
use Illuminate\Http\Request;

final class PurchaseRequestActor
{
    public static function from(Request $request): PurchaseActor
    {
        $user = $request->attributes->get('fm_user_id');
        $anon = $request->attributes->get('fm_anon_id');

        return new PurchaseActor(
            (int) $request->attributes->get('fm_org_id', 0),
            $user !== null ? (string) $user : null,
            is_string($anon) ? $anon : null,
            CareerAssessmentPurchase::guestAnonId($request),
        );
    }
}
