<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\BigFive\ReportEngine\Bridge\BigFiveLiveRuntimeBridge;
use App\Services\BigFive\ResultPageV2\Access\BigFiveV2PilotAccessGate;
use App\Services\BigFive\ResultPageV2\Composer\BigFiveV2PilotPayloadComposer;
use App\Services\BigFive\ResultPageV2\Observability\BigFiveV2ProductionRolloutTelemetry;
use App\Services\BigFive\ResultPageV2\Routing\BigFiveV2ProjectionRouteInputAdapter;
use App\Services\BigFive\ResultPageV2\Routing\BigFiveV2RouteDrivenSelectorInputBuilder;
use App\Services\BigFive\ResultPageV2\Routing\BigFiveV2RouteInput;
use App\Services\BigFive\ResultPageV2\Routing\BigFiveV2RouteMatrixLookup;
use App\Services\BigFive\ResultPageV2\Rollout\BigFiveV2ProductionRolloutGate;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2DeterministicSelector;
use App\Services\Report\ReportAccess;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class BigFiveResultPageV2RuntimeWrapper
{
    public function __construct(
        private readonly BigFiveResultPageV2TransformerContract $transformer,
        private readonly BigFiveResultPageV2Validator $validator,
        private readonly BigFiveV2PilotAccessGate $pilotAccessGate,
        private readonly BigFiveV2PilotRuntimeObservability $pilotObservability,
        private readonly BigFiveV2ProductionRolloutGate $productionRolloutGate,
        private readonly BigFiveV2ProductionRolloutTelemetry $productionTelemetry,
    ) {}

    /**
     * @param  array<string,mixed>  $responsePayload
     * @return array<string,mixed>
     */
    public function appendIfEnabled(Attempt $attempt, Result $result, array $responsePayload): array
    {
        return $this->appendIfEnabledWithAudit($attempt, $result, $responsePayload)['payload'];
    }

    /**
     * @param  array<string,mixed>  $responsePayload
     * @return array{payload:array<string,mixed>,audit:array<string,mixed>}
     */
    public function appendIfEnabledWithAudit(Attempt $attempt, Result $result, array $responsePayload): array
    {
        $legacyRuntimeEnabled = $this->legacyRuntimeEnabled();
        $pilotRuntimeEnabled = $this->pilotRuntimeEnabled();
        $audit = $this->audit(
            BigFiveResultPageV2AuditFields::STATUS_NOT_EVALUATED,
            BigFiveResultPageV2AuditFields::REASON_NOT_BIG5
        );

        if (strtoupper(trim((string) ($attempt->scale_code ?? ''))) !== BigFiveResultPageV2Contract::SCALE_CODE) {
            return ['payload' => $responsePayload, 'audit' => $audit];
        }

        if (! $legacyRuntimeEnabled && ! $pilotRuntimeEnabled) {
            $this->pilotObservability->recordFlagOff($attempt, $result, [
                'pilot_runtime_configured' => (bool) config('big5_result_page_v2.pilot_runtime_enabled', false),
                'fallback_reason' => 'pilot_runtime_disabled',
            ]);

            return [
                'payload' => $responsePayload,
                'audit' => $this->audit(
                    BigFiveResultPageV2AuditFields::STATUS_DISABLED,
                    BigFiveResultPageV2AuditFields::REASON_PRODUCTION_RUNTIME_DISABLED
                ),
            ];
        }

        if ($pilotRuntimeEnabled) {
            return $this->appendPilotPayload($attempt, $result, $responsePayload);
        }

        if ((string) app()->environment() === 'production') {
            $decision = $this->productionRolloutGate->decide($attempt);
            $this->productionTelemetry->recordRolloutDecision($attempt, $result, $decision);
            if (! $decision->allowed) {
                return [
                    'payload' => $responsePayload,
                    'audit' => $this->audit(
                        BigFiveResultPageV2AuditFields::STATUS_FALLBACK,
                        BigFiveResultPageV2AuditFields::REASON_PRODUCTION_ROLLOUT_DENIED
                    ),
                ];
            }

            return $this->appendProductionPayload($attempt, $result, $responsePayload);
        }

        try {
            $envelope = $this->transformer->transform($this->buildInput($attempt, $result, $responsePayload));
            $errors = $this->validator->validateEnvelope($envelope);
            if ($errors !== []) {
                Log::warning('BIG5_RESULT_PAGE_V2_RUNTIME_PAYLOAD_INVALID', [
                    'attempt_id' => (string) ($attempt->id ?? ''),
                    'result_id' => (string) ($result->id ?? ''),
                    'error_count' => count($errors),
                    'errors' => array_slice($errors, 0, 10),
                ]);

                return [
                    'payload' => $responsePayload,
                    'audit' => $this->audit(
                        BigFiveResultPageV2AuditFields::STATUS_INVALID,
                        BigFiveResultPageV2AuditFields::REASON_PAYLOAD_VALIDATION_FAILED,
                        count($errors)
                    ),
                ];
            }

            $payload = $envelope[BigFiveResultPageV2Contract::PAYLOAD_KEY] ?? null;
            if (is_array($payload)) {
                $responsePayload[BigFiveResultPageV2Contract::PAYLOAD_KEY] = $payload;

                return [
                    'payload' => $responsePayload,
                    'audit' => $this->audit(
                        BigFiveResultPageV2AuditFields::STATUS_ATTACHED,
                        BigFiveResultPageV2AuditFields::REASON_V2_ATTACHED
                    ),
                ];
            }
        } catch (\Throwable $exception) {
            Log::warning('BIG5_RESULT_PAGE_V2_RUNTIME_WRAPPER_FAILED', [
                'attempt_id' => (string) ($attempt->id ?? ''),
                'result_id' => (string) ($result->id ?? ''),
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return [
                'payload' => $responsePayload,
                'audit' => $this->audit(
                    BigFiveResultPageV2AuditFields::STATUS_FALLBACK,
                    $this->classifyPayloadException($exception)
                ),
            ];
        }

        return [
            'payload' => $responsePayload,
            'audit' => $this->audit(
                BigFiveResultPageV2AuditFields::STATUS_FALLBACK,
                BigFiveResultPageV2AuditFields::REASON_LEGACY_ENGINE_ONLY
            ),
        ];
    }

    private function legacyRuntimeEnabled(): bool
    {
        if ((string) app()->environment() !== 'production') {
            return (bool) config('big5_result_page_v2.enabled', false);
        }

        return $this->productionRuntimeEnabled();
    }

    private function productionRuntimeEnabled(): bool
    {
        if ((string) app()->environment() !== 'production') {
            return false;
        }

        if ((bool) config('big5_result_page_v2.production_emergency_disabled', false)) {
            return false;
        }

        if (! (bool) config('big5_result_page_v2.production_runtime_enabled', false)) {
            return false;
        }

        if (! (bool) config('big5_result_page_v2.production_rollout_configured', false)) {
            return false;
        }

        if (! (bool) config('big5_result_page_v2.production_import_gate_passed', false)) {
            return false;
        }

        $snapshotId = trim((string) config('big5_result_page_v2.production_release_snapshot_id', ''));
        if ($snapshotId === '') {
            return false;
        }

        if (! in_array($snapshotId, $this->configuredStringList('production_approved_release_snapshot_ids'), true)) {
            return false;
        }

        return ! in_array($snapshotId, $this->configuredStringList('production_disabled_release_snapshot_ids'), true);
    }

    /**
     * @return list<string>
     */
    private function configuredStringList(string $key): array
    {
        $configured = config('big5_result_page_v2.'.$key, []);
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $configured,
        )));
    }

    /**
     * @param  array<string,mixed>  $responsePayload
     * @return array{payload:array<string,mixed>,audit:array<string,mixed>}
     */
    private function appendPilotPayload(Attempt $attempt, Result $result, array $responsePayload): array
    {
        if (! $this->responseAllowsPilotPayload($responsePayload)) {
            return [
                'payload' => $responsePayload,
                'audit' => $this->audit(
                    BigFiveResultPageV2AuditFields::STATUS_FALLBACK,
                    BigFiveResultPageV2AuditFields::REASON_LOCKED_OR_FREE_PREVIEW
                ),
            ];
        }

        $accessDecision = $this->pilotAccessGate->decide($attempt);
        $this->pilotObservability->recordAccessDecision($attempt, $result, $accessDecision);
        if (! $accessDecision->allowed) {
            return [
                'payload' => $responsePayload,
                'audit' => $this->audit(
                    BigFiveResultPageV2AuditFields::STATUS_FALLBACK,
                    BigFiveResultPageV2AuditFields::REASON_PRODUCTION_ROLLOUT_DENIED
                ),
            ];
        }

        try {
            $build = $this->buildRouteDrivenEnvelope($attempt, $result, $responsePayload);
            $envelope = $build['envelope'];
            $errors = $this->validator->validateEnvelope($envelope);
            if ($errors !== []) {
                $this->pilotObservability->recordPayloadValidationFailed($attempt, $result, $errors);
                Log::warning('BIG5_RESULT_PAGE_V2_PILOT_PAYLOAD_INVALID', [
                    'attempt_id' => (string) ($attempt->id ?? ''),
                    'result_id' => (string) ($result->id ?? ''),
                    'error_count' => count($errors),
                    'errors' => array_slice($errors, 0, 10),
                ]);

                return [
                    'payload' => $responsePayload,
                    'audit' => $this->audit(
                        BigFiveResultPageV2AuditFields::STATUS_INVALID,
                        BigFiveResultPageV2AuditFields::REASON_PAYLOAD_VALIDATION_FAILED,
                        count($errors)
                    ),
                ];
            }

            $payload = $envelope[BigFiveResultPageV2Contract::PAYLOAD_KEY] ?? null;
            if (is_array($payload)) {
                $responsePayload[BigFiveResultPageV2Contract::PAYLOAD_KEY] = $payload;
                $this->pilotObservability->recordPayloadAttached($attempt, $result, $build['metrics']);

                return [
                    'payload' => $responsePayload,
                    'audit' => $this->audit(
                        BigFiveResultPageV2AuditFields::STATUS_ATTACHED,
                        BigFiveResultPageV2AuditFields::REASON_V2_ATTACHED
                    ),
                ];
            }
        } catch (\Throwable $exception) {
            $this->pilotObservability->recordPayloadGenerationFailed($attempt, $result, $exception);
            Log::warning('BIG5_RESULT_PAGE_V2_PILOT_RUNTIME_WRAPPER_FAILED', [
                'attempt_id' => (string) ($attempt->id ?? ''),
                'result_id' => (string) ($result->id ?? ''),
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return [
                'payload' => $responsePayload,
                'audit' => $this->audit(
                    BigFiveResultPageV2AuditFields::STATUS_FALLBACK,
                    $this->classifyPayloadException($exception)
                ),
            ];
        }

        return [
            'payload' => $responsePayload,
            'audit' => $this->audit(
                BigFiveResultPageV2AuditFields::STATUS_FALLBACK,
                BigFiveResultPageV2AuditFields::REASON_LEGACY_ENGINE_ONLY
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $responsePayload
     * @return array{payload:array<string,mixed>,audit:array<string,mixed>}
     */
    private function appendProductionPayload(Attempt $attempt, Result $result, array $responsePayload): array
    {
        if (! $this->responseAllowsPilotPayload($responsePayload)) {
            return [
                'payload' => $responsePayload,
                'audit' => $this->audit(
                    BigFiveResultPageV2AuditFields::STATUS_FALLBACK,
                    BigFiveResultPageV2AuditFields::REASON_LOCKED_OR_FREE_PREVIEW
                ),
            ];
        }

        try {
            $build = $this->buildRouteDrivenEnvelope($attempt, $result, $responsePayload);
            $this->productionTelemetry->recordSelectorSuppression(
                $attempt,
                $result,
                (int) ($build['metrics']['selector_suppressed_ref_count'] ?? 0),
                (int) ($build['metrics']['unresolved_ref_count'] ?? 0)
            );

            $envelope = $build['envelope'];
            $errors = $this->validator->validateEnvelope($envelope);
            if ($errors !== []) {
                $this->productionTelemetry->recordPayloadValidationFailure($attempt, $result, $errors);
                Log::warning('BIG5_RESULT_PAGE_V2_PRODUCTION_PAYLOAD_INVALID', [
                    'attempt_id' => (string) ($attempt->id ?? ''),
                    'result_id' => (string) ($result->id ?? ''),
                    'error_count' => count($errors),
                    'errors' => array_slice($errors, 0, 10),
                ]);

                return [
                    'payload' => $responsePayload,
                    'audit' => $this->audit(
                        BigFiveResultPageV2AuditFields::STATUS_INVALID,
                        BigFiveResultPageV2AuditFields::REASON_PAYLOAD_VALIDATION_FAILED,
                        count($errors)
                    ),
                ];
            }

            $payload = $envelope[BigFiveResultPageV2Contract::PAYLOAD_KEY] ?? null;
            if (is_array($payload)) {
                $responsePayload[BigFiveResultPageV2Contract::PAYLOAD_KEY] = $payload;

                return [
                    'payload' => $responsePayload,
                    'audit' => $this->audit(
                        BigFiveResultPageV2AuditFields::STATUS_ATTACHED,
                        BigFiveResultPageV2AuditFields::REASON_V2_ATTACHED
                    ),
                ];
            }
        } catch (\Throwable $exception) {
            $reason = $this->classifyPayloadException($exception);
            if ($reason === BigFiveResultPageV2AuditFields::REASON_COMPOSER_FAILED) {
                $this->productionTelemetry->recordComposerFailure($attempt, $result, $exception);
            } else {
                $this->productionTelemetry->recordFailClosed($attempt, $result, $reason);
            }

            Log::warning('BIG5_RESULT_PAGE_V2_PRODUCTION_RUNTIME_WRAPPER_FAILED', [
                'attempt_id' => (string) ($attempt->id ?? ''),
                'result_id' => (string) ($result->id ?? ''),
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
                'fallback_reason' => $reason,
            ]);

            return [
                'payload' => $responsePayload,
                'audit' => $this->audit(
                    BigFiveResultPageV2AuditFields::STATUS_FALLBACK,
                    $reason
                ),
            ];
        }

        return [
            'payload' => $responsePayload,
            'audit' => $this->audit(
                BigFiveResultPageV2AuditFields::STATUS_FALLBACK,
                BigFiveResultPageV2AuditFields::REASON_LEGACY_ENGINE_ONLY
            ),
        ];
    }

    /**
     * @param  array<string,mixed>  $responsePayload
     */
    private function responseAllowsPilotPayload(array $responsePayload): bool
    {
        if ((bool) ($responsePayload['locked'] ?? true)) {
            return false;
        }

        $accessLevel = strtolower(trim((string) ($responsePayload['access_level'] ?? ReportAccess::REPORT_ACCESS_FREE)));
        if ($accessLevel === ReportAccess::REPORT_ACCESS_FREE) {
            return false;
        }

        $modulesAllowed = ReportAccess::normalizeModules(
            is_array($responsePayload['modules_allowed'] ?? null) ? $responsePayload['modules_allowed'] : []
        );

        return in_array(ReportAccess::MODULE_BIG5_FULL, $modulesAllowed, true);
    }

    private function pilotRuntimeEnabled(): bool
    {
        if (! (bool) config('big5_result_page_v2.pilot_runtime_enabled', false)
            && ! (bool) config('big5_result_page_v2.public_pilot_enabled', false)) {
            return false;
        }

        $environment = (string) app()->environment();
        $allowedEnvironments = array_values(array_unique(array_merge(
            $this->pilotAllowedEnvironments(),
            $this->publicPilotAllowedEnvironments(),
        )));
        if (! in_array($environment, $allowedEnvironments, true)) {
            return false;
        }

        if ($environment === 'production'
            && ! (bool) config('big5_result_page_v2.pilot_production_allowlist_enabled', false)
            && ! (bool) config('big5_result_page_v2.public_pilot_production_allowlist_enabled', false)) {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function pilotAllowedEnvironments(): array
    {
        $configured = config('big5_result_page_v2.pilot_allowed_environments', []);
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $environment): string => trim((string) $environment),
            $configured,
        )));
    }

    /**
     * @return list<string>
     */
    private function publicPilotAllowedEnvironments(): array
    {
        $configured = config('big5_result_page_v2.public_pilot_allowed_environments', []);
        if (is_string($configured)) {
            $configured = explode(',');
        }

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $environment): string => trim((string) $environment),
            $configured,
        )));
    }

    /**
     * @return array{envelope: array{big5_result_page_v2: array<string,mixed>}, metrics: array<string,mixed>}
     */
    private function buildRouteDrivenEnvelope(Attempt $attempt, Result $result, array $responsePayload): array
    {
        $routeInput = $this->buildRouteInput($result, $responsePayload);
        $routeRow = (new BigFiveV2RouteMatrixLookup)->lookup($routeInput);
        if ($routeRow === null) {
            throw new RuntimeException(
                'Big Five V2 route lookup failed for route hash: '.$this->combinationKeyHash($routeInput->combinationKey)
            );
        }

        $formSummary = is_array($responsePayload['big5_form_v1'] ?? null) ? $responsePayload['big5_form_v1'] : [];
        $input = (new BigFiveV2RouteDrivenSelectorInputBuilder)->build(
            routeInput: $routeInput,
            routeRow: $routeRow,
            formCode: $this->resolveFormCode($attempt, $formSummary),
        );
        $selection = (new BigFiveV2DeterministicSelector)->select($input);
        try {
            $envelope = (new BigFiveV2PilotPayloadComposer)->compose($input, $selection);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Big Five V2 composer failed.', previous: $exception);
        }

        return [
            'envelope' => $envelope,
            'metrics' => [
                'route_input_created' => true,
                'route_lookup_failed' => false,
                'composer_failed' => false,
                'combination_key_hash' => $this->combinationKeyHash($routeInput->combinationKey),
                'quality_status' => $routeInput->qualityStatus,
                'norm_status' => $routeInput->normStatus,
                'selector_suppressed_refs' => count($selection->suppressedAssetRefs),
                'selector_suppressed_ref_count' => count($selection->suppressedAssetRefs),
                'unresolved_ref_count' => count($selection->unresolvedRefSuppressions),
                'surface_status_summary' => [
                    'pending_surfaces' => $selection->pendingSurfaces,
                    'pending_surface_count' => count($selection->pendingSurfaces),
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $responsePayload
     */
    private function buildRouteInput(Result $result, array $responsePayload): BigFiveV2RouteInput
    {
        $projection = $responsePayload['big5_public_projection_v1'] ?? null;
        if (! is_array($projection)) {
            $projection = data_get($responsePayload, 'report._meta.big5_public_projection_v1');
        }
        if (is_array($projection) && $projection !== []) {
            $meta = (array) ($projection['_meta'] ?? []);
            if (($meta['redacted'] ?? false) === true || ($meta['locked'] ?? false) === true) {
                throw new RuntimeException('Big Five V2 projection is locked or redacted.');
            }
        }

        $scoreResult = $this->scoreResult($result);
        if ($scoreResult !== []) {
            $adapter = new BigFiveV2ProjectionRouteInputAdapter;
            $routeInput = $adapter->fromScoreResult($scoreResult);
            if ($routeInput instanceof BigFiveV2RouteInput) {
                return $routeInput;
            }

            throw new RuntimeException('Big Five V2 route input is invalid: '.implode('; ', $adapter->errors()));
        }

        if (is_array($projection) && $projection !== []) {
            $adapter = new BigFiveV2ProjectionRouteInputAdapter;
            $routeInput = $adapter->fromProjection($projection);
            if ($routeInput instanceof BigFiveV2RouteInput) {
                return $routeInput;
            }

            throw new RuntimeException('Big Five V2 route projection is invalid: '.implode('; ', $adapter->errors()));
        }

        throw new RuntimeException('Big Five V2 score result is missing.');
    }

    private function combinationKeyHash(string $combinationKey): string
    {
        return hash('sha256', 'big5_v2_combination|'.trim($combinationKey));
    }

    /**
     * @return array<string,mixed>
     */
    private function scoreResult(Result $result): array
    {
        $resultJson = is_array($result->result_json) ? $result->result_json : [];
        foreach ([
            data_get($resultJson, 'normed_json'),
            data_get($resultJson, 'breakdown_json.score_result'),
            data_get($resultJson, 'axis_scores_json.score_result'),
        ] as $candidate) {
            if (is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $responsePayload
     * @return array<string,mixed>
     */
    private function buildInput(Attempt $attempt, Result $result, array $responsePayload): array
    {
        $resultJson = is_array($result->result_json) ? $result->result_json : [];
        $scoreResult = is_array(data_get($resultJson, 'normed_json')) ? data_get($resultJson, 'normed_json') : [];
        $formSummary = is_array($responsePayload['big5_form_v1'] ?? null) ? $responsePayload['big5_form_v1'] : [];

        return [
            'attempt_id' => (string) ($attempt->id ?? ''),
            'form_code' => $this->resolveFormCode($attempt, $formSummary),
            'big5_public_projection_v1' => is_array($responsePayload['big5_public_projection_v1'] ?? null)
                ? $responsePayload['big5_public_projection_v1']
                : (is_array(data_get($responsePayload, 'report._meta.big5_public_projection_v1'))
                    ? data_get($responsePayload, 'report._meta.big5_public_projection_v1')
                    : []),
            'big5_report_engine_v2' => is_array($responsePayload[BigFiveLiveRuntimeBridge::RESPONSE_KEY] ?? null)
                ? $responsePayload[BigFiveLiveRuntimeBridge::RESPONSE_KEY]
                : [],
            'scores_json' => is_array($result->scores_json) ? $result->scores_json : [],
            'scores_pct' => is_array($result->scores_pct) ? $result->scores_pct : [],
            'result_json' => $resultJson,
            'quality_status' => $this->firstNonEmptyString([
                data_get($scoreResult, 'quality.status'),
                data_get($scoreResult, 'quality.level'),
                data_get($resultJson, 'quality.status'),
                data_get($resultJson, 'quality.level'),
            ], 'B'),
            'quality_flags' => is_array(data_get($scoreResult, 'quality.flags')) ? data_get($scoreResult, 'quality.flags') : [],
            'norm_status' => $this->firstNonEmptyString([
                data_get($scoreResult, 'norms.norm_status'),
                data_get($scoreResult, 'norms.status'),
                data_get($resultJson, 'norms.norm_status'),
                data_get($resultJson, 'norms.status'),
            ], 'CALIBRATED'),
            'norm_group_id' => $this->firstNonEmptyString([
                data_get($scoreResult, 'norms.norm_group_id'),
                data_get($scoreResult, 'norms.group_id'),
                data_get($resultJson, 'norms.norm_group_id'),
                data_get($resultJson, 'norms.group_id'),
            ], ''),
            'norm_version' => $this->firstNonEmptyString([
                data_get($scoreResult, 'norms.norm_version'),
                data_get($scoreResult, 'norms.norms_version'),
                data_get($resultJson, 'norms.norm_version'),
                data_get($resultJson, 'norms.norms_version'),
            ], ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $formSummary
     */
    private function resolveFormCode(Attempt $attempt, array $formSummary): string
    {
        $formCode = trim((string) ($formSummary['form_code'] ?? data_get($attempt->answers_summary_json, 'meta.form_code', '')));
        if ($formCode !== '') {
            return $formCode;
        }

        return (int) ($attempt->question_count ?? 0) === 90 ? 'big5_90' : 'big5_120';
    }

    /**
     * @param  list<mixed>  $values
     */
    private function firstNonEmptyString(array $values, string $default): string
    {
        foreach ($values as $value) {
            $current = trim((string) $value);
            if ($current !== '') {
                return $current;
            }
        }

        return $default;
    }

    /**
     * @return array{status:string,fallback_reason:?string,validation_error_count:int}
     */
    private function audit(string $status, ?string $reason, int $validationErrorCount = 0): array
    {
        return [
            'status' => $status,
            'fallback_reason' => $reason,
            'validation_error_count' => max(0, min(65535, $validationErrorCount)),
        ];
    }

    private function classifyPayloadException(\Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'locked') || str_contains($message, 'redacted')) {
            return BigFiveResultPageV2AuditFields::REASON_LOCKED_OR_FREE_PREVIEW;
        }

        if (str_contains($message, 'score result is missing')) {
            return BigFiveResultPageV2AuditFields::REASON_MISSING_SCORE_RESULT;
        }

        if (str_contains($message, 'route input') || str_contains($message, 'route projection')) {
            return BigFiveResultPageV2AuditFields::REASON_ROUTE_INPUT_INVALID;
        }

        if (str_contains($message, 'route lookup failed') || str_contains($message, 'route row is missing')) {
            return BigFiveResultPageV2AuditFields::REASON_ROUTE_LOOKUP_FAILED;
        }

        if (str_contains($message, 'composer failed')) {
            return BigFiveResultPageV2AuditFields::REASON_COMPOSER_FAILED;
        }

        return BigFiveResultPageV2AuditFields::REASON_EXCEPTION;
    }
}
