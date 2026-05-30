<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Foundation;

use App\Http\Controllers\Controller;
use App\Http\Resources\Foundation\DailyGivingRecordResource;
use App\Models\DailyGivingRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DailyGivingRecordController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) ($request->input('per_page', 20)), 100);
        $sort = in_array((string) $request->input('sort', 'donation_date'), ['donation_date', 'published_at', 'amount_minor'], true)
            ? (string) $request->input('sort', 'donation_date')
            : 'donation_date';
        $order = strtolower((string) $request->input('order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = DailyGivingRecord::query()
            ->publishedPublic()
            ->orderBy($sort, $order);

        $paginator = $query->paginate($perPage);

        $items = DailyGivingRecordResource::collection($paginator->items())->resolve();

        return response()->json([
            'ok' => true,
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(string $recordCode): JsonResponse
    {
        $record = DailyGivingRecord::query()
            ->publishedPublic()
            ->where('record_code', $recordCode)
            ->first();

        if (! $record) {
            return response()->json([
                'ok' => false,
                'error_code' => 'NOT_FOUND',
                'message' => 'Giving record not found.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'record' => (new DailyGivingRecordResource($record))->resolve(),
        ]);
    }

    public function months(): JsonResponse
    {
        $months = DailyGivingRecord::query()
            ->publishedPublic()
            ->select('donation_date')
            ->distinct()
            ->orderBy('donation_date', 'desc')
            ->pluck('donation_date')
            ->map(fn ($date): string => $date instanceof \Illuminate\Support\Carbon ? $date->format('Y-m') : substr((string) $date, 0, 7))
            ->unique()
            ->values()
            ->all();

        return response()->json([
            'ok' => true,
            'months' => $months,
        ]);
    }

    public function monthRecords(string $yearMonth): JsonResponse
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', $yearMonth, $matches)) {
            return response()->json([
                'ok' => false,
                'error_code' => 'INVALID_ARGUMENT',
                'message' => 'Invalid year_month format. Use YYYY-MM.',
            ], 422);
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];

        $items = DailyGivingRecord::query()
            ->publishedPublic()
            ->whereYear('donation_date', $year)
            ->whereMonth('donation_date', $month)
            ->orderBy('donation_date', 'desc')
            ->get();

        return response()->json([
            'ok' => true,
            'month' => $yearMonth,
            'items' => DailyGivingRecordResource::collection($items)->resolve(),
        ]);
    }
}
