<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\ReportAccess;
use App\Services\Report\RiasecReportComposer;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class RiasecPilotAllowlistGateTest extends TestCase
{
    public function test_pilot_gate_defaults_closed_without_allowlist(): void
    {
        Log::spy();
        $this->enablePilotGate();

        $payload = $this->compose($this->attempt());

        $this->assertNull($payload);
        Log::shouldHaveReceived('info')->once()->with('RIASEC_RESULT_PAGE_V2_PILOT_GATE', \Mockery::on(
            static fn (array $context): bool => ($context['decision'] ?? null) === 'deny'
                && ($context['reason'] ?? null) === 'pilot_allowlist_empty'
                && ($context['raw_identifier_exported'] ?? true) === false
        ));
    }

    public function test_pilot_gate_allows_attempt_user_anon_and_org_allowlist_matches(): void
    {
        foreach ([
            'attempt_id' => 'pilot_attempt_1',
            'user_id' => 'pilot_user_1',
            'anon_id' => 'pilot_anon_1',
            'org_id' => '314159',
        ] as $field => $allowedValue) {
            $this->enablePilotGate();
            config()->set('riasec_result_page_v2.pilot_access_allowed_'.$field.'s', [$allowedValue]);

            $attempt = $this->attempt([$field => $allowedValue]);
            $payload = $this->compose($attempt);

            $this->assertIsArray($payload, $field);
            $this->assertSame('allow', data_get($payload, 'gate.pilot_gate_decision'));
            $this->assertSame('pilot_allowlist_allowed', data_get($payload, 'gate.pilot_gate_reason'));
            $this->assertSame($field, data_get($payload, 'gate.pilot_gate_matched_rule'));
            $this->assertFalse((bool) data_get($payload, 'gate.raw_identifier_exported', true));
            $this->assertStringNotContainsString($allowedValue, json_encode($payload, JSON_THROW_ON_ERROR));
        }
    }

    public function test_pilot_gate_denies_wrong_form_locale_environment_and_kill_switch(): void
    {
        $this->enablePilotGate([
            'pilot_access_allowed_anon_ids' => ['pilot_anon_1'],
            'pilot_allowed_form_codes' => ['riasec_140'],
        ]);
        $this->assertNull($this->compose($this->attempt(['anon_id' => 'pilot_anon_1'])));

        $this->enablePilotGate([
            'pilot_access_allowed_anon_ids' => ['pilot_anon_1'],
            'pilot_allowed_locales' => ['en-US'],
        ]);
        $this->assertNull($this->compose($this->attempt(['anon_id' => 'pilot_anon_1'])));

        $this->enablePilotGate([
            'pilot_access_allowed_anon_ids' => ['pilot_anon_1'],
            'pilot_allowed_environments' => ['staging'],
        ]);
        $this->assertNull($this->compose($this->attempt(['anon_id' => 'pilot_anon_1'])));

        $this->enablePilotGate([
            'pilot_access_allowed_anon_ids' => ['pilot_anon_1'],
            'pilot_kill_switch_enabled' => true,
        ]);
        $this->assertNull($this->compose($this->attempt(['anon_id' => 'pilot_anon_1'])));
    }

    public function test_pilot_gate_denies_production_by_default(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        $this->app['env'] = 'production';
        $this->enablePilotGate([
            'allowed_environments' => ['production', 'testing'],
            'pilot_allowed_environments' => ['production', 'testing'],
            'pilot_access_allowed_anon_ids' => ['pilot_anon_1'],
            'pilot_production_allowlist_enabled' => false,
        ]);

        $payload = $this->compose($this->attempt(['anon_id' => 'pilot_anon_1']));

        $this->assertNull($payload);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function enablePilotGate(array $overrides = []): void
    {
        config()->set('riasec_result_page_v2.enabled', true);
        config()->set('riasec_result_page_v2.staging_runtime_enabled', false);
        config()->set('riasec_result_page_v2.pilot_runtime_enabled', true);
        config()->set('riasec_result_page_v2.pilot_kill_switch_enabled', false);
        config()->set('riasec_result_page_v2.allowed_environments', ['testing']);
        config()->set('riasec_result_page_v2.pilot_allowed_environments', ['testing']);
        config()->set('riasec_result_page_v2.pilot_production_allowlist_enabled', false);
        config()->set('riasec_result_page_v2.pilot_allowed_form_codes', ['riasec_60', 'riasec_140']);
        config()->set('riasec_result_page_v2.pilot_allowed_locales', ['zh-CN']);
        config()->set('riasec_result_page_v2.pilot_access_allowed_attempt_ids', []);
        config()->set('riasec_result_page_v2.pilot_access_allowed_user_ids', []);
        config()->set('riasec_result_page_v2.pilot_access_allowed_anon_ids', []);
        config()->set('riasec_result_page_v2.pilot_access_allowed_org_ids', []);
        config()->set('riasec_result_page_v2.production_runtime_enabled', false);
        config()->set('riasec_result_page_v2.production_rollout_enabled', false);
        config()->set('riasec_result_page_v2.production_rollout_manual_approval_granted', false);

        foreach ($overrides as $key => $value) {
            config()->set('riasec_result_page_v2.'.$key, $value);
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function attempt(array $overrides = []): Attempt
    {
        $attempt = new Attempt;
        $attempt->attempt_id = 'pilot_attempt_default';
        $attempt->user_id = 'pilot_user_default';
        $attempt->anon_id = 'pilot_anon_default';
        $attempt->org_id = '271828';
        $attempt->scale_code = 'RIASEC';
        $attempt->locale = 'zh-CN';

        foreach ($overrides as $key => $value) {
            $attempt->{$key} = $value;
        }

        return $attempt;
    }

    private function riasecResult(): Result
    {
        $result = new Result;
        $result->scale_code = 'RIASEC';
        $result->type_code = 'RIA';
        $result->result_json = [
            'top_code' => 'RIA',
            'primary_type' => 'R',
            'secondary_type' => 'I',
            'tertiary_type' => 'A',
            'form_code' => 'riasec_60',
            'answer_count' => 60,
            'scores_0_100' => [
                'R' => 100,
                'I' => 80,
                'A' => 60,
                'S' => 40,
                'E' => 20,
                'C' => 10,
            ],
        ];

        return $result;
    }

    private function compose(Attempt $attempt): ?array
    {
        $result = app(RiasecReportComposer::class)->composeVariant(
            $attempt,
            $this->riasecResult(),
            ReportAccess::VARIANT_FULL,
            [
                'snapshot_bound' => true,
                'riasec_result_page_v2_pilot' => true,
            ]
        );
        $this->assertTrue((bool) ($result['ok'] ?? false));

        return data_get($result, 'report._meta.result_page_v2');
    }
}
