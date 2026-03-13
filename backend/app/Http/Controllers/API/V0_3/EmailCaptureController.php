<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Http\Requests\V0_3\EmailCaptureRequest;
use App\Services\Email\EmailCaptureService;
use Illuminate\Http\JsonResponse;

final class EmailCaptureController extends Controller
{
    public function store(EmailCaptureRequest $request, EmailCaptureService $captures): JsonResponse
    {
        /** @var array<string,mixed> $payload */
        $payload = $request->validated();
        $result = $captures->capture((string) ($payload['email'] ?? ''), $payload);

        return response()->json($result);
    }
}
