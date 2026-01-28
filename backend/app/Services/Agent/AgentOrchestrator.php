<?php

namespace App\Services\Agent;

use App\Services\Agent\Explainers\WhyThisMessageBuilder;
use App\Services\Agent\Notifiers\InAppNotifier;
use App\Services\Agent\Policies\ConsentPolicy;
use App\Services\Agent\Policies\RiskPolicy;
use App\Services\Agent\Policies\ThrottlePolicy;
use App\Services\Agent\Triggers\LowMoodStreakTrigger;
use App\Services\Agent\Triggers\NoActivityTrigger;
use App\Services\Agent\Triggers\SleepVolatilityTrigger;
use App\Services\AI\BudgetLedger;
use App\Services\AI\BudgetLedgerException;
use App\Services\Analytics\EventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class AgentOrchestrator
{
    public function runForUser(int $userId, array $settings = []): array
    {
        if (!(bool) config('agent.enabled', false)) {
            return ['ok' => false, 'error' => 'agent_disabled'];
        }

        $consent = app(ConsentPolicy::class)->check($userId);
        if (!($consent['allowed'] ?? false)) {
            $this->recordDecision($userId, null, 'suppress', $consent['reason'] ?? 'consent_missing');
            app(EventRecorder::class)->record('agent_suppressed_by_policy', $userId, [
                'reason' => $consent['reason'] ?? 'consent_missing',
            ], ['channel' => 'agent']);
            return ['ok' => true, 'suppressed' => true, 'reason' => $consent['reason'] ?? 'consent_missing'];
        }

        $throttle = app(ThrottlePolicy::class)->check($userId, $settings);
        if (!($throttle['allowed'] ?? false)) {
            $this->recordDecision($userId, null, 'suppress', $throttle['reason'] ?? 'throttled');
            app(EventRecorder::class)->record('agent_suppressed_by_policy', $userId, [
                'reason' => $throttle['reason'] ?? 'throttled',
            ], ['channel' => 'agent']);
            return ['ok' => true, 'suppressed' => true, 'reason' => $throttle['reason'] ?? 'throttled'];
        }

        $triggers = [
            app(SleepVolatilityTrigger::class)->evaluate($userId, $settings['sleep_volatility'] ?? []),
            app(LowMoodStreakTrigger::class)->evaluate($userId, $settings['low_mood_streak'] ?? []),
            app(NoActivityTrigger::class)->evaluate($userId, $settings['no_activity'] ?? []),
        ];

        foreach ($triggers as $trigger) {
            if (($trigger['fired'] ?? false) === true) {
                return $this->handleTrigger($userId, $trigger);
            }
        }

        return ['ok' => true, 'suppressed' => true, 'reason' => 'no_trigger'];
    }

    private function handleTrigger(int $userId, array $trigger): array
    {
        $triggerId = $this->recordTrigger($userId, $trigger);
        $decisionId = $this->recordDecision($userId, $triggerId, 'send', 'trigger_fired');

        $risk = app(RiskPolicy::class)->assess([
            'text' => $trigger['metrics']['summary'] ?? '',
        ]);
        $why = app(WhyThisMessageBuilder::class)->build(
            (string) ($trigger['trigger_type'] ?? 'unknown'),
            $trigger['metrics'] ?? [],
            $trigger['source_refs'] ?? [],
            ['risk' => $risk]
        );

        $payload = $this->buildMessagePayload($trigger, $risk, $decisionId, $why);
        $payload['evidence_json'] = $payload['evidence_json'] ?? ($trigger['source_refs'] ?? []);

        app(EventRecorder::class)->record('agent_trigger_fired', $userId, [
            'trigger_id' => $triggerId,
            'trigger_type' => $trigger['trigger_type'] ?? 'unknown',
            'metrics' => $trigger['metrics'] ?? [],
        ], ['channel' => 'agent']);

        app(EventRecorder::class)->record('agent_decision_made', $userId, [
            'decision_id' => $decisionId,
            'trigger_id' => $triggerId,
            'decision' => 'send',
        ], ['channel' => 'agent']);

        if (($risk['level'] ?? 'low') === 'high') {
            app(EventRecorder::class)->record('agent_safety_escalated', $userId, [
                'trigger_id' => $triggerId,
                'decision_id' => $decisionId,
                'reason' => $risk['reason'] ?? 'high',
            ], ['channel' => 'agent']);
        }

        $budget = $this->checkBudget();
        if (!($budget['ok'] ?? false)) {
            $payload = $this->applyBudgetDegrade($payload, $budget);
            if ((bool) config('agent.breaker.suppress_on_budget_exceeded', true)) {
                app(EventRecorder::class)->record('agent_suppressed_by_policy', $userId, [
                    'reason' => 'budget_exceeded',
                    'error_code' => $budget['error_code'] ?? null,
                ], ['channel' => 'agent']);
                return ['ok' => true, 'suppressed' => true, 'reason' => 'budget_exceeded'];
            }

            app(EventRecorder::class)->record('agent_message_failed', $userId, [
                'error_code' => $budget['error_code'] ?? null,
                'reason' => 'budget_degraded',
            ], ['channel' => 'agent']);
        }

        app(EventRecorder::class)->record('agent_message_queued', $userId, [
            'decision_id' => $decisionId,
            'trigger_id' => $triggerId,
        ], ['channel' => 'agent']);

        $payload = [
            'decision_id' => $decisionId,
            'title' => $payload['title'] ?? null,
            'body' => $payload['body'] ?? '',
            'template_key' => $payload['template_key'] ?? null,
            'content_hash' => $payload['content_hash'] ?? null,
            'idempotency_key' => $payload['idempotency_key'] ?? null,
            'why_json' => $payload['why_json'] ?? $why,
            'evidence_json' => $payload['evidence_json'] ?? ($trigger['source_refs'] ?? []),
        ];

        $notifier = app(InAppNotifier::class);
        $message = $notifier->send($userId, $payload);

        if (($message['ok'] ?? false) && !empty($message['id'])) {
            app(EventRecorder::class)->record('agent_message_sent', $userId, [
                'message_id' => $message['id'],
                'decision_id' => $decisionId,
            ], ['channel' => 'agent']);
        }

        return [
            'ok' => true,
            'trigger_id' => $triggerId,
            'decision_id' => $decisionId,
            'message_id' => $message['id'] ?? null,
            'risk' => $risk,
        ];
    }

    private function recordTrigger(int $userId, array $trigger): ?string
    {
        if (!Schema::hasTable('agent_triggers')) {
            return null;
        }

        $idempotencyKey = hash('sha256', $userId . ':' . ($trigger['trigger_type'] ?? '') . ':' . date('Y-m-d'));
        $existing = DB::table('agent_triggers')->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return (string) ($existing->id ?? '');
        }

        $id = (string) Str::uuid();
        try {
            DB::table('agent_triggers')->insert([
                'id' => $id,
                'user_id' => $userId,
                'trigger_type' => (string) ($trigger['trigger_type'] ?? 'unknown'),
                'status' => 'fired',
                'fired_at' => now(),
                'idempotency_key' => $idempotencyKey,
                'payload_json' => json_encode($trigger, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\\Throwable $e) {
            $existing = DB::table('agent_triggers')->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return (string) ($existing->id ?? '');
            }
        }

        return $id;
    }

    private function recordDecision(int $userId, ?string $triggerId, string $decision, string $reason): ?string
    {
        if (!Schema::hasTable('agent_decisions')) {
            return null;
        }

        $idempotencyKey = hash('sha256', $userId . ':' . ($triggerId ?? '') . ':' . $decision);
        $existing = DB::table('agent_decisions')->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return (string) ($existing->id ?? '');
        }

        $id = (string) Str::uuid();
        try {
            DB::table('agent_decisions')->insert([
                'id' => $id,
                'user_id' => $userId,
                'trigger_id' => $triggerId,
                'decision' => $decision,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'policy_json' => json_encode(['reason' => $reason], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\\Throwable $e) {
            $existing = DB::table('agent_decisions')->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return (string) ($existing->id ?? '');
            }
        }

        return $id;
    }

    private function checkBudget(): array
    {
        $ledger = app(BudgetLedger::class);
        $provider = (string) config('ai.provider', 'mock');
        $model = (string) config('ai.model', 'mock-model');
        $subject = (string) config('agent.budgets.subject', 'agent_message');
        $cost = (float) config('agent.budgets.default_cost', 0.001);

        try {
            $ledger->checkAndThrow($provider, $model, $subject, 0, $cost, 'day');
            $ledger->incrementTokens($provider, $model, $subject, 0, 0, $cost);
            return ['ok' => true];
        } catch (BudgetLedgerException $e) {
            $failOpen = (bool) config('agent.breaker.fail_open', false);
            if ($failOpen) {
                return ['ok' => true, 'degraded' => true, 'error' => $e->getMessage(), 'error_code' => $e->errorCode()];
            }

            return ['ok' => false, 'error' => $e->getMessage(), 'error_code' => $e->errorCode()];
        }
    }

    private function buildMessagePayload(array $trigger, array $risk, ?string $decisionId, array $why): array
    {
        $triggerType = (string) ($trigger['trigger_type'] ?? 'unknown');
        $metrics = $trigger['metrics'] ?? [];

        if (($risk['level'] ?? 'low') === 'high') {
            $template = config('agent.risk_templates.high');
            $title = $template['title'] ?? '我们注意到你可能需要支持';
            $body = $template['body'] ?? '如果你正在经历困难时刻，请考虑联系可信赖的人或专业支持。';
            return [
                'decision_id' => $decisionId,
                'title' => $title,
                'body' => $body,
                'template_key' => 'risk_high',
                'content_hash' => hash('sha256', $title . $body),
                'idempotency_key' => 'decision:' . $decisionId,
                'why_json' => $why,
            ];
        }

        $summary = $this->triggerSummary($triggerType, $metrics);
        $title = '你的近况更新';
        $body = $summary !== '' ? $summary : '我们注意到一些新的趋势，想和你确认是否需要帮助。';

        return [
            'decision_id' => $decisionId,
            'title' => $title,
            'body' => $body,
            'template_key' => 'agent_default',
            'content_hash' => hash('sha256', $title . $body),
            'idempotency_key' => 'decision:' . $decisionId,
            'why_json' => $why,
        ];
    }

    private function triggerSummary(string $triggerType, array $metrics): string
    {
        if ($triggerType === 'sleep_volatility') {
            $stddev = $metrics['stddev'] ?? null;
            $mean = $metrics['mean'] ?? null;
            if ($stddev !== null && $mean !== null) {
                return '过去一周的睡眠波动较大（均值 ' . $mean . 'h，波动 ' . $stddev . '）。如需支持，请告诉我们。';
            }
            return '过去一周的睡眠波动较大，如需支持请告诉我们。';
        }

        if ($triggerType === 'low_mood_streak') {
            $lowDays = $metrics['low_days'] ?? null;
            if ($lowDays !== null) {
                return '最近 ' . $lowDays . ' 天情绪偏低，如果需要支持我们在这里。';
            }
            return '最近情绪偏低，如需支持我们在这里。';
        }

        if ($triggerType === 'no_activity') {
            return '最近几天未检测到活动，如果需要提醒或帮助，请告诉我们。';
        }

        return '我们注意到一些新的趋势，想和你确认是否需要帮助。';
    }

    private function applyBudgetDegrade(array $payload, array $budget): array
    {
        $template = config('agent.risk_templates.high');
        $title = $template['title'] ?? '我们注意到你可能需要支持';
        $body = $template['body'] ?? '如果你正在经历困难时刻，请考虑联系可信赖的人或专业支持。';

        $payload['title'] = $title;
        $payload['body'] = $body;
        $payload['template_key'] = 'budget_degraded';
        $payload['content_hash'] = hash('sha256', $title . $body);
        $payload['budget_error'] = $budget['error_code'] ?? null;

        return $payload;
    }
}
