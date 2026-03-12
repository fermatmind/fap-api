<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Http\Requests\V0_3\EmailPreferencesUpdateRequest;
use App\Http\Requests\V0_3\EmailUnsubscribeRequest;
use App\Services\Email\EmailPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmailPreferenceController extends Controller
{
    public function show(Request $request, EmailPreferenceService $preferences): JsonResponse
    {
        $token = trim((string) $request->query('token', ''));
        $result = $preferences->showByToken($token);

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'error_code' => $result['error_code'] ?? 'INVALID_TOKEN',
            ], (int) ($result['status'] ?? 422));
        }

        return response()->json([
            'ok' => true,
            'email_masked' => $result['email_masked'] ?? '***',
            'preferences' => $result['preferences'] ?? [
                'marketing_updates' => false,
                'report_recovery' => true,
                'product_updates' => false,
            ],
        ]);
    }

    public function update(
        EmailPreferencesUpdateRequest $request,
        EmailPreferenceService $preferences
    ): JsonResponse {
        $result = $preferences->updateByToken((string) $request->validated('token'), $request->validated());

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'error_code' => $result['error_code'] ?? 'INVALID_TOKEN',
            ], (int) ($result['status'] ?? 422));
        }

        return response()->json([
            'ok' => true,
            'preferences' => $result['preferences'] ?? [
                'marketing_updates' => false,
                'report_recovery' => true,
                'product_updates' => false,
            ],
        ]);
    }

    public function unsubscribe(
        EmailUnsubscribeRequest $request,
        EmailPreferenceService $preferences
    ): JsonResponse {
        $result = $preferences->unsubscribeByToken(
            (string) $request->validated('token'),
            (string) $request->validated('reason', 'user_request')
        );

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'error_code' => $result['error_code'] ?? 'INVALID_TOKEN',
            ], (int) ($result['status'] ?? 422));
        }

        return response()->json([
            'ok' => true,
            'status' => $result['status_text'] ?? 'unsubscribed',
        ]);
    }
}
