<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Http\Requests\V0_3\CreateMbtiCompareInviteRequest;
use App\Services\V0_3\MbtiCompareInviteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MbtiCompareInviteController extends Controller
{
    public function __construct(private readonly MbtiCompareInviteService $service) {}

    public function store(CreateMbtiCompareInviteRequest $request, string $shareId): JsonResponse
    {
        $result = $this->service->create((string) $request->route('shareId', $shareId), $request->validated());

        return response()->json(array_merge(['ok' => true], $result));
    }

    public function show(string $inviteId): JsonResponse
    {
        $result = $this->service->show($inviteId);

        return response()->json(array_merge(['ok' => true], $result));
    }

    public function showPrivate(Request $request, string $inviteId): JsonResponse
    {
        $result = $this->service->showPrivate(
            $inviteId,
            $request->attributes->get('user_id'),
            $request->attributes->get('anon_id')
        );

        return response()->json(array_merge(['ok' => true], $result));
    }
}
