<?php

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** @deprecated Split into AttemptReadController + AttemptWriteController. */
class AttemptsController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        throw new \RuntimeException('Deprecated controller.');
    }

    public function submit(Request $request): JsonResponse
    {
        throw new \RuntimeException('Deprecated controller.');
    }

    public function result(Request $request, string $id): JsonResponse
    {
        throw new \RuntimeException('Deprecated controller.');
    }

    public function report(Request $request, string $id): JsonResponse
    {
        throw new \RuntimeException('Deprecated controller.');
    }
}
