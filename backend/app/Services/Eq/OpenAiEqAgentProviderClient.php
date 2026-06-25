<?php

declare(strict_types=1);

namespace App\Services\Eq;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class OpenAiEqAgentProviderClient implements EqAgentProviderClient
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '' && $this->model() !== '';
    }

    public function unavailableReason(): ?string
    {
        if ($this->apiKey() === '') {
            return 'EQ Agent OpenAI provider is not configured. Set EQ_AGENT_OPENAI_API_KEY or OPENAI_API_KEY.';
        }

        if ($this->model() === '') {
            return 'EQ Agent OpenAI provider is not configured. Set EQ_AGENT_OPENAI_MODEL or EQ_AGENT_LLM_MODEL.';
        }

        return null;
    }

    public function generate(EqAgentProviderRequest $request): EqAgentProviderResponse
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException((string) $this->unavailableReason());
        }

        $payload = [
            'model' => $this->model(),
            'max_output_tokens' => $this->maxOutputTokens(),
            'input' => [
                [
                    'role' => 'system',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => $this->systemPrompt($request->locale),
                    ]],
                ],
                [
                    'role' => 'user',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => $this->userPrompt($request),
                    ]],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'eq_agent_provider_response',
                    'strict' => true,
                    'schema' => $this->responseSchema(),
                ],
            ],
            'metadata' => [
                'surface' => 'eq_agent_runtime',
                'locale' => $request->locale,
                'intent' => trim((string) $request->intent),
            ],
        ];

        try {
            $response = Http::acceptJson()
                ->withToken($this->apiKey())
                ->connectTimeout($this->connectTimeoutSeconds())
                ->timeout($this->requestTimeoutSeconds())
                ->retry($this->maxRetries(), $this->retrySleepMilliseconds())
                ->post($this->endpointUrl(), $payload)
                ->throw();
        } catch (ConnectionException $e) {
            throw new RuntimeException('OpenAI EQ Agent provider request failed: connection error.', previous: $e);
        } catch (RequestException $e) {
            $status = $e->response?->status();
            $body = $e->response?->json();
            $message = is_array($body)
                ? (string) data_get($body, 'error.message', 'OpenAI EQ Agent provider request failed.')
                : 'OpenAI EQ Agent provider request failed.';

            throw new RuntimeException(
                sprintf('OpenAI EQ Agent provider request failed%s: %s', $status ? " with status {$status}" : '', trim($message))
            );
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI EQ Agent provider returned a non-JSON payload.');
        }

        $structured = $this->extractStructuredOutput($decoded);

        return new EqAgentProviderResponse(
            trim((string) ($structured['text'] ?? '')),
            $this->stringList($structured['summary_points'] ?? null),
            trim((string) ($structured['follow_up_question'] ?? '')),
            $this->stringList($structured['source_asset_ids'] ?? null),
            $this->stringList($structured['boundary_claim_ids'] ?? null),
            [
                'provider' => 'openai',
                'model' => $this->model(),
                'response_id' => (string) ($decoded['id'] ?? ''),
            ],
        );
    }

    private function apiKey(): string
    {
        return trim((string) config('ai.eq_agent.openai.api_key', ''));
    }

    private function model(): string
    {
        return trim((string) config('ai.eq_agent.openai.model', config('ai.eq_agent.model', '')));
    }

    private function endpointUrl(): string
    {
        return rtrim((string) config('ai.eq_agent.openai.base_url', self::DEFAULT_BASE_URL), '/').'/responses';
    }

    private function connectTimeoutSeconds(): int
    {
        return max(1, (int) config('ai.eq_agent.openai.connect_timeout_seconds', 5));
    }

    private function requestTimeoutSeconds(): int
    {
        return max(5, (int) config('ai.eq_agent.openai.request_timeout_seconds', 30));
    }

    private function maxRetries(): int
    {
        return max(0, (int) config('ai.eq_agent.openai.max_retries', 0));
    }

    private function retrySleepMilliseconds(): int
    {
        return max(0, (int) config('ai.eq_agent.openai.retry_sleep_milliseconds', 250));
    }

    private function maxOutputTokens(): int
    {
        return max(256, (int) config('ai.eq_agent.openai.max_output_tokens', 900));
    }

    private function systemPrompt(string $locale): string
    {
        return implode("\n", [
            'You are the FermatMind EQ Agent response layer.',
            'Explain only the provided EQ self-report context and resolved content assets.',
            'Do not rescore, reclassify, override the formulation, enable SJT, create paid unlock language, or expose raw technical tags.',
            'Do not claim true emotional ability, MSCEIT equivalence, certification, hiring suitability, clinical diagnosis, guaranteed outcomes, or job-performance prediction.',
            'Return JSON only and match the schema exactly.',
            'Response locale: '.$locale.'.',
        ]);
    }

    private function userPrompt(EqAgentProviderRequest $request): string
    {
        return json_encode([
            'user_message' => $request->message,
            'locale' => $request->locale,
            'requested_intent' => $request->intent,
            'safe_context' => $request->safeContext(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @return array<string,mixed>
     */
    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['text', 'summary_points', 'follow_up_question', 'source_asset_ids', 'boundary_claim_ids'],
            'properties' => [
                'text' => ['type' => 'string'],
                'summary_points' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'maxItems' => 4,
                ],
                'follow_up_question' => ['type' => 'string'],
                'source_asset_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'maxItems' => 12,
                ],
                'boundary_claim_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'maxItems' => 12,
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $decoded
     * @return array<string,mixed>
     */
    private function extractStructuredOutput(array $decoded): array
    {
        foreach ((array) ($decoded['output'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach ((array) ($item['content'] ?? []) as $content) {
                if (! is_array($content)) {
                    continue;
                }
                $text = trim((string) ($content['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $payload = json_decode($text, true);
                if (is_array($payload)) {
                    return $payload;
                }
            }
        }

        throw new RuntimeException('OpenAI EQ Agent provider returned no structured output text.');
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value
        ), static fn (string $item): bool => $item !== ''));
    }
}
