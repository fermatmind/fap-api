<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\BigFive\ReportEngine\Bridge\BigFiveLiveRuntimeBridge;
use Illuminate\Support\Facades\Log;

final class BigFiveResultPageV2RuntimeWrapper
{
    public function __construct(
        private readonly BigFiveResultPageV2TransformerContract $transformer,
        private readonly BigFiveResultPageV2Validator $validator,
    ) {}

    /**
     * @param  array<string,mixed>  $responsePayload
     * @return array<string,mixed>
     */
    public function appendIfEnabled(Attempt $attempt, Result $result, array $responsePayload): array
    {
        if (! (bool) config('big5_result_page_v2.enabled', false)) {
            return $responsePayload;
        }

        if (strtoupper(trim((string) ($attempt->scale_code ?? ''))) !== BigFiveResultPageV2Contract::SCALE_CODE) {
            return $responsePayload;
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
