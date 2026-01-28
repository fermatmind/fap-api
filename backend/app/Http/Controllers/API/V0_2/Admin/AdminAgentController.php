<?php

namespace App\Http\Controllers\API\V0_2\Admin;

use App\Http\Controllers\Controller;
use App\Services\Agent\AgentOrchestrator;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminAgentController extends Controller
{
    /**
     * POST /api/v0.2/admin/agent/disable-trigger
     */
    public function disableTrigger(Request $request)
    {
        $triggerType = trim((string) $request->input('trigger_type', ''));
        if ($triggerType === '') {
            return response()->json(['ok' => false, 'error' => 'trigger_type_required'], 422);
        }

        if (!Schema::hasTable('agent_triggers')) {
            return response()->json(['ok' => false, 'error' => 'agent_triggers_missing'], 500);
        }

        DB::table('agent_triggers')
            ->where('trigger_type', $triggerType)
            ->update([
                'status' => 'disabled',
                'updated_at' => now(),
            ]);

        app(AuditLogger::class)->log($request, 'agent_trigger_disabled', 'agent_trigger', $triggerType, [
            'trigger_type' => $triggerType,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/v0.2/admin/agent/replay/{user_id}
     */
    public function replay(Request $request, string $user_id)
    {
        $userId = (int) $user_id;
        $orchestrator = app(AgentOrchestrator::class);
        $result = $orchestrator->runForUser($userId, []);

        app(AuditLogger::class)->log($request, 'agent_replay', 'user', $user_id, [
            'result' => $result,
        ]);

        return response()->json([
            'ok' => true,
            'decision' => $result,
        ]);
    }
}
