<?php

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use App\Http\Requests\V0_2\BindEmailRequest;
use App\Http\Requests\V0_2\MeAttemptsIndexRequest;
use App\Http\Requests\V0_2\VerifyEmailBindingRequest;
use App\Services\Legacy\LegacyMeFacadeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __construct(private readonly LegacyMeFacadeService $me)
    {
    }

    public function attempts(MeAttemptsIndexRequest $request): JsonResponse
    {
        $result = $this->me->listAttempts($request->pageSize(), $request->page());

        return response()->json(array_merge(['ok' => true], $result), 200);
    }

    public function profile(Request $request): JsonResponse
    {
        $result = $this->me->getProfile();

        return response()->json(array_merge(['ok' => true], $result), 200);
    }

    public function bindEmail(BindEmailRequest $request): JsonResponse
    {
        $result = $this->me->bindEmail($request->emailValue());

        return response()->json(array_merge(['ok' => true], $result), 200);
    }

    public function verifyBinding(VerifyEmailBindingRequest $request): JsonResponse
    {
        $result = $this->me->verifyBinding($request->token());

        return response()->json(array_merge(['ok' => true], $result), 200);
    }

    public function sleepData(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 30);
        $result = $this->me->sleepData($days);

        return response()->json(array_merge(['ok' => true], $result), 200);
    }

    public function moodData(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 30);
        $result = $this->me->moodData($days);

        return response()->json(array_merge(['ok' => true], $result), 200);
    }

    public function screenTimeData(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 30);
        $result = $this->me->screenTimeData($days);

        return response()->json(array_merge(['ok' => true], $result), 200);
    }
}
