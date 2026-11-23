<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTO\Commerce\PurchaseActor;
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
            self::guestAnonId($request),
        );
    }

    public static function guestAnonId(Request $request): ?string
    {
        $key = (string) $request->header('X-Career-Guest-Token', '');

        return preg_match('/^[a-f0-9]{64}$/D', $key) === 1 ? 'c94_'.substr(hash('sha256', $key), 0, 48) : null;
    }
}
