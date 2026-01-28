<?php

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateInsightJob;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class InsightsController extends Controller
{
    /**
     * POST /api/v0.2/insights/generate
     */
    public function generate(Request $request)
    {
        if (!(bool) config('ai.enabled', true) || !(bool) config('ai.insights_enabled', true)) {
            return response()->json([
                'ok' => false,
                'error' => 'AI_DISABLED',
                'error_code' => 'AI_DISABLED',
                'message' => 'AI insights are currently disabled.',
            ], 503);
        }

        if (!Schema::hasTable('ai_insights')) {
            return response()->json([
                'ok' => false,
                'error' => 'TABLE_MISSING',
                'error_code' => 'AI_TABLE_MISSING',
                'message' => 'AI insights table not found.',
            ], 500);
        }

        $periodType = trim((string) $request->input('period_type', ''));
        if (!in_array($periodType, ['week', 'month'], true)) {
            return response()->json([
                'ok' => false,
                'error' => 'INVALID_PERIOD_TYPE',
                'message' => 'period_type must be week or month.',
            ], 422);
        }

        $periodStart = $this->parseDate((string) $request->input('period_start', ''));
        $periodEnd = $this->parseDate((string) $request->input('period_end', ''));

        if (!$periodStart || !$periodEnd) {
            return response()->json([
                'ok' => false,
                'error' => 'INVALID_PERIOD_RANGE',
                'message' => 'period_start and period_end must be valid dates.',
            ], 422);
        }

        $userId = trim((string) $request->attributes->get('fm_user_id', ''));
        $anonIdInput = trim((string) $request->input('anon_id', ''));
        $anonId = $userId === '' ? $anonIdInput : '';

        $inputPayload = [
            'period_type' => $periodType,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'user_id' => $userId,
            'anon_id' => $anonId,
            'prompt_version' => (string) config('ai.prompt_version', 'v1.0.0'),
            'provider' => (string) config('ai.provider', 'mock'),
            'model' => (string) config('ai.model', 'mock-model'),
        ];

        $inputHash = hash('sha256', json_encode($inputPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $id = (string) Str::uuid();
        DB::table('ai_insights')->insert([
            'id' => $id,
            'user_id' => $userId !== '' ? $userId : null,
            'anon_id' => $anonId !== '' ? $anonId : null,
            'period_type' => $periodType,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'input_hash' => $inputHash,
            'prompt_version' => (string) config('ai.prompt_version', 'v1.0.0'),
            'model' => (string) config('ai.model', 'mock-model'),
            'provider' => (string) config('ai.provider', 'mock'),
            'tokens_in' => 0,
            'tokens_out' => 0,
            'cost_usd' => 0,
            'status' => 'queued',
            'output_json' => null,
            'evidence_json' => null,
            'error_code' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            GenerateInsightJob::dispatch($id);
        } catch (\Throwable $e) {
            DB::table('ai_insights')->where('id', $id)->update([
                'status' => 'failed',
                'error_code' => 'AI_DISPATCH_FAILED',
                'updated_at' => now(),
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'AI_DISPATCH_FAILED',
                'message' => 'Failed to dispatch insight generation.',
            ], 500);
        }

        $status = 'queued';
        if ((string) config('queue.default', 'sync') === 'sync') {
            $fresh = DB::table('ai_insights')->where('id', $id)->first();
            if ($fresh && !empty($fresh->status)) {
                $status = (string) $fresh->status;
            }
        }

        return response()->json([
            'ok' => true,
            'id' => $id,
            'status' => $status,
        ]);
    }

    /**
     * GET /api/v0.2/insights/{id}
     */
    public function show(Request $request, string $id)
    {
        if (!Schema::hasTable('ai_insights')) {
            return response()->json([
                'ok' => false,
                'error' => 'TABLE_MISSING',
            ], 500);
        }

        $row = DB::table('ai_insights')->where('id', $id)->first();
        if (!$row) {
            return response()->json([
                'ok' => false,
                'error' => 'NOT_FOUND',
            ], 404);
        }

        $auth = $this->authorizeInsight($request, $row);
        if ($auth !== null) {
            return $auth;
        }

        $outputJson = $this->decodeJson($row->output_json ?? null);
        $evidenceJson = $this->decodeJson($row->evidence_json ?? null);

        return response()->json([
            'ok' => true,
            'id' => $row->id,
            'status' => $row->status,
            'output_json' => $outputJson,
            'evidence_json' => $evidenceJson,
            'tokens_in' => (int) ($row->tokens_in ?? 0),
            'tokens_out' => (int) ($row->tokens_out ?? 0),
            'cost_usd' => (float) ($row->cost_usd ?? 0),
            'prompt_version' => (string) ($row->prompt_version ?? ''),
            'model' => (string) ($row->model ?? ''),
            'provider' => (string) ($row->provider ?? ''),
            'error_code' => (string) ($row->error_code ?? ''),
        ]);
    }

    /**
     * POST /api/v0.2/insights/{id}/feedback
     */
    public function feedback(Request $request, string $id)
    {
        if (!Schema::hasTable('ai_insights') || !Schema::hasTable('ai_insight_feedback')) {
            return response()->json([
                'ok' => false,
                'error' => 'TABLE_MISSING',
            ], 500);
        }

        $row = DB::table('ai_insights')->where('id', $id)->first();
        if (!$row) {
            return response()->json([
                'ok' => false,
                'error' => 'NOT_FOUND',
            ], 404);
        }

        $auth = $this->authorizeInsight($request, $row);
        if ($auth !== null) {
            return $auth;
        }

        $rating = (int) $request->input('rating', 0);
        if ($rating < 1 || $rating > 5) {
            return response()->json([
                'ok' => false,
                'error' => 'INVALID_RATING',
                'message' => 'rating must be between 1 and 5.',
            ], 422);
        }

        $reason = trim((string) $request->input('reason', ''));
        if ($reason !== '' && strlen($reason) > 64) {
            $reason = substr($reason, 0, 64);
        }

        $comment = trim((string) $request->input('comment', ''));
        if ($comment !== '' && strlen($comment) > 2000) {
            $comment = substr($comment, 0, 2000);
        }

        $feedbackId = (string) Str::uuid();
        DB::table('ai_insight_feedback')->insert([
            'id' => $feedbackId,
            'insight_id' => $row->id,
            'rating' => $rating,
            'reason' => $reason !== '' ? $reason : null,
            'comment' => $comment !== '' ? $comment : null,
            'created_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'id' => $feedbackId,
        ]);
    }

    private function authorizeInsight(Request $request, object $row): ?\Illuminate\Http\JsonResponse
    {
        $rowUserId = trim((string) ($row->user_id ?? ''));
        $rowAnonId = trim((string) ($row->anon_id ?? ''));

        if ($rowUserId !== '') {
            $reqUserId = trim((string) $request->attributes->get('fm_user_id', ''));
            if ($reqUserId === '' || $reqUserId !== $rowUserId) {
                return response()->json([
                    'ok' => false,
                    'error' => 'FORBIDDEN',
                ], 403);
            }
        } elseif ($rowAnonId !== '') {
            $allowDev = (bool) config('ai.dev_allow_anon_header', true);
            $headerAnon = trim((string) $request->header('X-FAP-Anon-Id', ''));
            if ($headerAnon === '') {
                if ($allowDev) {
                    return null;
                }
                return response()->json([
                    'ok' => false,
                    'error' => 'FORBIDDEN',
                ], 403);
            }
            if ($headerAnon !== $rowAnonId && !$allowDev) {
                return response()->json([
                    'ok' => false,
                    'error' => 'FORBIDDEN',
                ], 403);
            }
        }

        return null;
    }

    private function parseDate(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function decodeJson($value)
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : $value;
        }

        return $value;
    }
}
