<?php

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Http\Requests\V0_3\MeAttemptsIndexRequest;
use App\Services\V0_3\MeFacadeService;
use Illuminate\Http\JsonResponse;

class MeController extends Controller
{
    public function __construct(private readonly MeFacadeService $me)
    {
    }

    public function attempts(MeAttemptsIndexRequest $request): JsonResponse
    {
        $result = $this->me->listAttempts($request->pageSize(), $request->page());

        return response()->json(array_merge(['ok' => true], $result), 200);
    }
}
