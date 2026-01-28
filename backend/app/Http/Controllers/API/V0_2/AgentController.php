<?php

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use App\Jobs\SendAgentMessageJob;
use App\Services\Analytics\EventRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AgentController extends Controller
{
    /**
     * GET /api/v0.2/me/agent/settings
     */
    public function settings(Request $request)
    {
        $userId = $this->requireUserId($request);
        if ($userId === null) {
            return $this->userIdError();
        }

        if (!Schema::hasTable('user_agent_settings')) {
            return response()->json(['ok' => false, 'error' => 'settings_table_missing'], 500);
        }

        $row = DB::table('user_agent_settings')->where('user_id', $userId)->first();

        return response()->json([
            'ok' => true,
            'settings' => $row,
        ]);
    }

    /**
     * POST /api/v0.2/me/agent/settings
     */
    public function updateSettings(Request $request)
    {
        $userId = $this->requireUserId($request);
        if ($userId === null) {
            return $this->userIdError();
        }

        if (!Schema::hasTable('user_agent_settings')) {
            return response()->json(['ok' => false, 'error' => 'settings_table_missing'], 500);
        }

        $enabled = (bool) $request->input('enabled', false);
        $quietHours = $request->input('quiet_hours', []);
        $thresholds = $request->input('thresholds', []);
        $channels = $request->input('channels', []);
        $maxPerDay = (int) $request->input('max_messages_per_day', config('agent.max_messages_per_day', 2));
        $cooldown = (int) $request->input('cooldown_minutes', config('agent.cooldown_minutes', 240));

        $payload = [
            'enabled' => $enabled,
            'quiet_hours_json' => json_encode($quietHours, JSON_UNESCAPED_UNICODE),
            'thresholds_json' => json_encode($thresholds, JSON_UNESCAPED_UNICODE),
            'channels_json' => json_encode($channels, JSON_UNESCAPED_UNICODE),
            'max_messages_per_day' => $maxPerDay,
            'cooldown_minutes' => $cooldown,
            'updated_at' => now(),
        ];

        $exists = DB::table('user_agent_settings')->where('user_id', $userId)->exists();
        if ($exists) {
            DB::table('user_agent_settings')->where('user_id', $userId)->update($payload);
        } else {
            $payload['user_id'] = $userId;
            $payload['created_at'] = now();
            DB::table('user_agent_settings')->insert($payload);
        }

        app(EventRecorder::class)->recordFromRequest($request, 'agent_settings_updated', $userId, [
            'enabled' => $enabled,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/v0.2/me/agent/messages
     */
    public function messages(Request $request)
    {
        $userId = $this->requireUserId($request);
        if ($userId === null) {
            return $this->userIdError();
        }

        if (!Schema::hasTable('agent_messages')) {
            return response()->json(['ok' => false, 'error' => 'agent_messages_missing'], 500);
        }

        $rows = DB::table('agent_messages')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'ok' => true,
            'items' => $rows,
        ]);
    }

    /**
     * POST /api/v0.2/me/agent/messages/{id}/feedback
     */
    public function feedback(Request $request, string $id)
    {
        $userId = $this->requireUserId($request);
        if ($userId === null) {
            return $this->userIdError();
        }

        if (!Schema::hasTable('agent_feedback') || !Schema::hasTable('agent_messages')) {
            return response()->json(['ok' => false, 'error' => 'agent_feedback_missing'], 500);
        }

        $message = DB::table('agent_messages')->where('id', $id)->where('user_id', $userId)->first();
        if (!$message) {
            return response()->json(['ok' => false, 'error' => 'message_not_found'], 404);
        }

        $rating = trim((string) $request->input('rating', ''));
        if ($rating === '') {
            return response()->json(['ok' => false, 'error' => 'rating_required'], 422);
        }

        $feedbackId = (string) Str::uuid();
        DB::table('agent_feedback')->insert([
            'id' => $feedbackId,
            'user_id' => $userId,
            'message_id' => $id,
            'rating' => $rating,
            'reason' => $request->input('reason', null),
            'notes' => $request->input('notes', null),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('agent_messages')->where('id', $id)->update([
            'feedback_at' => now(),
            'updated_at' => now(),
        ]);

        app(EventRecorder::class)->recordFromRequest($request, 'agent_message_feedback', $userId, [
            'message_id' => $id,
            'rating' => $rating,
            'reason' => $request->input('reason', null),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/v0.2/me/agent/messages/{id}/ack
     */
    public function ack(Request $request, string $id)
    {
        $userId = $this->requireUserId($request);
        if ($userId === null) {
            return $this->userIdError();
        }

        if (!Schema::hasTable('agent_messages')) {
            return response()->json(['ok' => false, 'error' => 'agent_messages_missing'], 500);
        }

        $message = DB::table('agent_messages')->where('id', $id)->where('user_id', $userId)->first();
        if (!$message) {
            return response()->json(['ok' => false, 'error' => 'message_not_found'], 404);
        }

        DB::table('agent_messages')->where('id', $id)->update([
            'acked_at' => now(),
            'updated_at' => now(),
        ]);

        app(EventRecorder::class)->recordFromRequest($request, 'agent_message_view', $userId, [
            'message_id' => $id,
        ]);

        return response()->json(['ok' => true]);
    }

    private function requireUserId(Request $request): ?int
    {
        $userId = trim((string) $request->attributes->get('fm_user_id', ''));
        if ($userId === '') {
            return null;
        }

        return (int) $userId;
    }

    private function userIdError()
    {
        return response()->json([
            'ok' => false,
            'error' => 'USER_ID_REQUIRED',
        ], 401);
    }
}
