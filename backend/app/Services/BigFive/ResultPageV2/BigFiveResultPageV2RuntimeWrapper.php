<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\BigFive\ReportEngine\Bridge\BigFiveLiveRuntimeBridge;
use App\Services\BigFive\ResultPageV2\Access\BigFiveV2PilotAccessGate;
use App\Services\BigFive\ResultPageV2\Composer\BigFiveV2PilotPayloadComposer;
use App\Services\BigFive\ResultPageV2\RouteMatrix\BigFiveV2RouteMatrixParser;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2DeterministicSelector;
use App\Services\BigFive\ResultPageV2\Selector\BigFiveV2SelectorInput;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class BigFiveResultPageV2RuntimeWrapper
{
    public function __construct(
        private readonly BigFiveResultPageV2TransformerContract $transformer,
        private readonly BigFiveResultPageV2Validator $validator,
        private readonly BigFiveV2PilotAccessGate $pilotAccessGate,
    ) {}

    /**
     * @param  array<string,mixed>  $responsePayload
     * @return array<string,mixed>
     */
    public function appendIfEnabled(Attempt $attempt, Result $result, array $responsePayload): array
    {
        $legacyRuntimeEnabled = (bool) config('big5_result_page_v2.enabled', false);
        $pilotRuntimeEnabled = $this->pilotRuntimeEnabled();

        if (! $legacyRuntimeEnabled && ! $pilotRuntimeEnabled) {
            return $responsePayload;
        }

        if (strtoupper(trim((string) ($attempt->scale_code ?? ''))) !== BigFiveResultPageV2Contract::SCALE_CODE) {
            return $responsePayload;
        }

        if ($pilotRuntimeEnabled) {
            return $this->appendPilotPayload($attempt, $result, $responsePayload);
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

                return $responsePayload;
            }

            $payload = $envelope[BigFiveResultPageV2Contract::PAYLOAD_KEY] ?? null;
            if (is_array($payload)) {
                $responsePayload[BigFiveResultPageV2Contract::PAYLOAD_KEY] = $payload;
            }
        } catch (\Throwable $exception) {
            Log::warning('BIG5_RESULT_PAGE_V2_RUNTIME_WRAPPER_FAILED', [
                'attempt_id' => (string) ($attempt->id ?? ''),
                'result_id' => (string) ($result->id ?? ''),
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        return $responsePayload;
    }

    /**
     * @param  array<string,mixed>  $responsePayload
     * @return array<string,mixed>
     */
    private function appendPilotPayload(Attempt $attempt, Result $result, array $responsePayload): array
    {
        $accessDecision = $this->pilotAccessGate->decide($attempt);
        if (! $accessDecision->allowed) {
            return $responsePayload;
        }

        try {
            $envelope = $this->buildPilotEnvelope();
            $errors = $this->validator->validateEnvelope($envelope);
            if ($errors !== []) {
                Log::warning('BIG5_RESULT_PAGE_V2_PILOT_PAYLOAD_INVALID', [
                    'attempt_id' => (string) ($attempt->id ?? ''),
                    'result_id' => (string) ($result->id ?? ''),
                    'error_count' => count($errors),
                    'errors' => array_slice($errors, 0, 10),
                ]);

                return $responsePayload;
            }

            $payload = $envelope[BigFiveResultPageV2Contract::PAYLOAD_KEY] ?? null;
            if (is_array($payload)) {
                $responsePayload[BigFiveResultPageV2Contract::PAYLOAD_KEY] = $payload;
            }
        } catch (\Throwable $exception) {
            Log::warning('BIG5_RESULT_PAGE_V2_PILOT_RUNTIME_WRAPPER_FAILED', [
                'attempt_id' => (string) ($attempt->id ?? ''),
                'result_id' => (string) ($result->id ?? ''),
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        return $responsePayload;
    }

    private function pilotRuntimeEnabled(): bool
    {
        if (! (bool) config('big5_result_page_v2.pilot_runtime_enabled', false)) {
            return false;
        }

        $environment = (string) app()->environment();
        $allowedEnvironments = $this->pilotAllowedEnvironments();
        if (! in_array($environment, $allowedEnvironments, true)) {
            return false;
        }

        if ($environment === 'production' && ! (bool) config('big5_result_page_v2.pilot_production_allowlist_enabled', false)) {
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
     * @return array{big5_result_page_v2: array<string,mixed>}
     */
    private function buildPilotEnvelope(): array
    {
        $routeMatrix = (new BigFiveV2RouteMatrixParser())->parse();
        if ($routeMatrix->errors !== []) {
            throw new RuntimeException('Big Five V2 pilot route matrix is not validator-clean.');
        }

        $routeRow = $routeMatrix->row(BigFiveV2RouteMatrixParser::O59_COMBINATION_KEY);
        if ($routeRow === null) {
            throw new RuntimeException('Big Five V2 pilot O59 route row is missing.');
        }

        $input = BigFiveV2SelectorInput::fromGoldenCase($this->o59GoldenCase(), $routeRow);
        $selection = (new BigFiveV2DeterministicSelector())->select($input);

        return (new BigFiveV2PilotPayloadComposer())->compose($input, $selection);
    }

    /**
     * @return array<string,mixed>
     */
    private function o59GoldenCase(): array
    {
        $path = base_path('content_assets/big5/result_page_v2/selector_qa_policy/v0_1/big5_result_page_v2_selector_qa_policy_v0_1_golden_cases.json');
        $json = file_get_contents($path);
        if (! is_string($json)) {
            throw new RuntimeException('Big Five V2 O59 golden cases are unreadable.');
        }

        $cases = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($cases)) {
            throw new RuntimeException('Big Five V2 O59 golden cases must be a JSON list.');
        }

        foreach ($cases as $case) {
            if (is_array($case) && ($case['case_key'] ?? null) === 'golden_case_31_o59_canonical_preview') {
                return $case;
            }
        }

        throw new RuntimeException('Big Five V2 O59 canonical golden case is missing.');
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
                : [],
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
}
