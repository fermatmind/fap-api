<?php

declare(strict_types=1);

namespace App\Services\Riasec;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\ReportAccess;
use App\Services\Report\RiasecReportComposer;
use Throwable;

final class RiasecResultPageV2ProductionSmokeVerifier
{
    public const PROCEDURE_ID = 'riasec_result_page_v2_post_deploy_smoke_v0_2';

    public const SNAPSHOT_ID = 'riasec_result_page_v2_prod_approved_v0_2';

    /** @var list<string> */
    private const PRIVATE_KEYS = [
        'attempt_id',
        'user_id',
        'anon_id',
        'org_id',
        'raw_score',
        'raw_scores',
        'score_vector',
        'dimension_vector',
        'percentile',
        'selector_trace',
        'share_block',
        'token',
        'secret',
    ];

    public function __construct(
        private readonly RiasecReportComposer $composer,
    ) {}

    /** @return array<string,mixed> */
    public function verify(): array
    {
        $originalEnvironment = (string) app()->environment();
        $configKeys = array_keys($this->productionConfig());
        $originalConfig = [];
        foreach ($configKeys as $key) {
            $originalConfig[$key] = config('riasec_result_page_v2.'.$key);
        }

        $errors = [];
        $forms = [];

        try {
            app()->detectEnvironment(static fn (): string => 'production');
            foreach ($this->productionConfig() as $key => $value) {
                config()->set('riasec_result_page_v2.'.$key, $value);
            }

            foreach (['riasec_60' => 60, 'riasec_140' => 140] as $formCode => $questionCount) {
                $full = $this->compose($formCode, $questionCount, ReportAccess::VARIANT_FULL);
                $free = $this->compose($formCode, $questionCount, ReportAccess::VARIANT_FREE);
                $wrapper = data_get($full, 'report._meta.result_page_v2');

                if (! is_array($wrapper)) {
                    $errors[] = $formCode.':full_result_page_v2_missing';
                } else {
                    if (data_get($wrapper, 'runtime_use') !== 'production') {
                        $errors[] = $formCode.':runtime_use_not_production';
                    }
                    if ((int) data_get($full, 'report._meta.riasec_public_projection_v2.form.question_count') !== $questionCount) {
                        $errors[] = $formCode.':question_count_mismatch';
                    }
                    foreach ($this->privateKeys($wrapper) as $privateKey) {
                        $errors[] = $formCode.':private_key_present:'.$privateKey;
                    }
                }

                if (data_get($free, 'report._meta.result_page_v2') !== null) {
                    $errors[] = $formCode.':locked_or_free_payload_present';
                }

                $forms[$formCode] = [
                    'question_count' => $questionCount,
                    'full_payload_present' => is_array($wrapper),
                    'locked_payload_hidden' => data_get($free, 'report._meta.result_page_v2') === null,
                ];
            }

            config()->set('riasec_result_page_v2.production_emergency_disabled', true);
            $fallback = $this->compose('riasec_60', 60, ReportAccess::VARIANT_FULL);
            $legacyFallbackPassed = (bool) data_get($fallback, 'ok')
                && data_get($fallback, 'report.top_code') === 'RIA'
                && data_get($fallback, 'report._meta.result_page_v2') === null;
            if (! $legacyFallbackPassed) {
                $errors[] = 'legacy_fallback_failed';
            }
        } catch (Throwable $throwable) {
            $errors[] = 'smoke_exception:'.$throwable::class;
            $legacyFallbackPassed = false;
        } finally {
            app()->detectEnvironment(static fn (): string => $originalEnvironment);
            foreach ($originalConfig as $key => $value) {
                config()->set('riasec_result_page_v2.'.$key, $value);
            }
        }

        return [
            'schema_version' => 'fap.riasec.result_page_v2.production_smoke.v0.2',
            'procedure_id' => self::PROCEDURE_ID,
            'snapshot_id' => self::SNAPSHOT_ID,
            'decision' => $errors === [] ? 'pass' : 'fail',
            'read_only' => true,
            'forms' => $forms,
            'checks' => [
                'full_report_payload' => $errors === [],
                'locked_payload_hidden' => $errors === [],
                'private_fields_filtered' => ! $this->hasErrorPrefix($errors, ':private_key_present:'),
                'legacy_fallback' => $legacyFallbackPassed ?? false,
            ],
            'execution' => [
                'database_write_performed' => false,
                'cms_write_performed' => false,
                'production_import_performed' => false,
                'production_rollout_performed' => false,
                'environment_write_performed' => false,
            ],
            'errors' => $errors,
        ];
    }

    /** @return array<string,mixed> */
    private function compose(string $formCode, int $questionCount, string $variant): array
    {
        $attempt = new Attempt;
        $attempt->attempt_id = 'riasec_production_smoke_fixture';
        $attempt->scale_code = 'RIASEC';
        $attempt->locale = 'zh-CN';
        $attempt->org_id = 0;
        $attempt->answers_summary_json = ['meta' => ['form_code' => $formCode]];

        $result = new Result;
        $result->scale_code = 'RIASEC';
        $result->type_code = 'RIA';
        $result->result_json = [
            'top_code' => 'RIA',
            'primary_type' => 'R',
            'secondary_type' => 'I',
            'tertiary_type' => 'A',
            'form_code' => $formCode,
            'answer_count' => $questionCount,
            'scores_0_100' => ['R' => 100, 'I' => 80, 'A' => 60, 'S' => 40, 'E' => 20, 'C' => 10],
        ];

        return $this->composer->composeVariant($attempt, $result, $variant, ['snapshot_bound' => true]);
    }

    /** @return array<string,mixed> */
    private function productionConfig(): array
    {
        return [
            'production_runtime_enabled' => true,
            'production_rollout_enabled' => true,
            'production_rollout_configured' => true,
            'production_rollout_manual_approval_granted' => true,
            'production_import_gate_passed' => true,
            'production_emergency_disabled' => false,
            'production_release_snapshot_id' => self::SNAPSHOT_ID,
            'production_approved_release_snapshot_ids' => [self::SNAPSHOT_ID],
            'production_disabled_release_snapshot_ids' => [],
            'production_rollout_mode' => 'allowlist_only',
            'production_rollout_percentage' => 0,
            'production_rollout_max_percentage' => 0,
            'production_rollout_allowed_attempt_ids' => ['riasec_production_smoke_fixture'],
            'production_rollout_allowed_user_ids' => [],
            'production_rollout_allowed_anon_ids' => [],
            'production_rollout_allowed_org_ids' => [],
            'production_rollout_require_tenant_scope' => true,
            'production_rollout_allowed_tenant_ids' => ['0'],
            'production_rollout_allowed_scale_codes' => ['RIASEC'],
            'production_rollout_allowed_form_codes' => ['riasec_60', 'riasec_140'],
            'production_rollout_allowed_locales' => ['zh-CN'],
            'production_post_deploy_smoke_required' => true,
            'production_post_deploy_smoke_procedure_id' => self::PROCEDURE_ID,
        ];
    }

    /** @param array<string,mixed> $payload
     * @return list<string>
     */
    private function privateKeys(array $payload): array
    {
        $found = [];
        array_walk_recursive($payload, static function (mixed $value, string|int $key) use (&$found): void {
            if (is_string($key) && in_array(strtolower($key), self::PRIVATE_KEYS, true)) {
                $found[] = strtolower($key);
            }
        });

        return array_values(array_unique($found));
    }

    /** @param list<string> $errors */
    private function hasErrorPrefix(array $errors, string $needle): bool
    {
        return array_any($errors, static fn (string $error): bool => str_contains($error, $needle));
    }
}
