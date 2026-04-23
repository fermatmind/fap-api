<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Contracts\Cms\CmsMachineTranslationProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class OpenAiCmsMachineTranslationProvider implements CmsMachineTranslationProvider
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    /**
     * @var list<string>
     */
    private const SUPPORTED_TYPES = [
        'support_article',
        'interpretation_guide',
        'content_page',
    ];

    public function supports(string $contentType): bool
    {
        return in_array($contentType, self::SUPPORTED_TYPES, true);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== ''
            && $this->model() !== '';
    }

    public function unavailableReason(string $contentType): ?string
    {
        if (! $this->supports($contentType)) {
            return sprintf('Provider does not support %s.', $contentType);
        }

        if ($this->apiKey() === '') {
            return 'CMS machine translation provider is not configured. Set CMS_TRANSLATION_OPENAI_API_KEY (or OPENAI_API_KEY).';
        }

        if ($this->model() === '') {
            return 'CMS machine translation provider is not configured. Set CMS_TRANSLATION_OPENAI_MODEL.';
        }

        return null;
    }

    public function translate(string $contentType, object $sourceRecord, array $normalizedSource, string $targetLocale): array
    {
        if (! $this->supports($contentType)) {
            throw new RuntimeException(sprintf('OpenAI CMS translation provider does not support %s.', $contentType));
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException((string) $this->unavailableReason($contentType));
        }

        $payload = [
            'model' => $this->model(),
            'max_output_tokens' => $this->maxOutputTokens(),
            'input' => [
                [
                    'role' => 'system',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => $this->systemPrompt($contentType, $targetLocale),
                    ]],
                ],
                [
                    'role' => 'user',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => $this->userPrompt($contentType, $sourceRecord, $normalizedSource, $targetLocale),
                    ]],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'cms_translation_payload',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => [
                            'title',
                            'summary',
                            'body_md',
                            'seo_title',
                            'seo_description',
                        ],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'summary' => ['type' => ['string', 'null']],
                            'body_md' => ['type' => 'string'],
                            'seo_title' => ['type' => ['string', 'null']],
                            'seo_description' => ['type' => ['string', 'null']],
                        ],
                    ],
                ],
            ],
            'metadata' => [
                'surface' => 'cms_translation',
                'content_type' => $contentType,
                'source_record_id' => (string) data_get($sourceRecord, 'id', ''),
                'source_slug' => (string) data_get($sourceRecord, 'slug', ''),
                'target_locale' => trim($targetLocale),
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
            throw new RuntimeException('OpenAI CMS translation request failed: connection error.', previous: $e);
        } catch (RequestException $e) {
            $status = $e->response?->status();
            $body = $e->response?->json();
            $message = is_array($body)
                ? (string) data_get($body, 'error.message', 'OpenAI CMS translation request failed.')
                : 'OpenAI CMS translation request failed.';

            throw new RuntimeException(
                sprintf('OpenAI CMS translation request failed%s: %s', $status ? " with status {$status}" : '', trim($message))
            );
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI CMS translation returned a non-JSON payload.');
        }

        return $this->normalizeStructuredTranslation($decoded);
    }

    private function apiKey(): string
    {
        return trim((string) config('services.cms_translation.openai.api_key', ''));
    }

    private function model(): string
    {
        return trim((string) config('services.cms_translation.openai.model', ''));
    }

    private function endpointUrl(): string
    {
        return rtrim((string) config('services.cms_translation.openai.base_url', self::DEFAULT_BASE_URL), '/').'/responses';
    }

    private function connectTimeoutSeconds(): int
    {
        return max(1, (int) config('services.cms_translation.openai.connect_timeout_seconds', 5));
    }

    private function requestTimeoutSeconds(): int
    {
        return max(5, (int) config('services.cms_translation.openai.request_timeout_seconds', 60));
    }

    private function maxRetries(): int
    {
        return max(0, (int) config('services.cms_translation.openai.max_retries', 1));
    }

    private function retrySleepMilliseconds(): int
    {
        return max(0, (int) config('services.cms_translation.openai.retry_sleep_milliseconds', 250));
    }

    private function maxOutputTokens(): int
    {
        return max(512, (int) config('services.cms_translation.openai.max_output_tokens', 5000));
    }

    private function systemPrompt(string $contentType, string $targetLocale): string
    {
        return implode("\n", [
            'You are a professional CMS translation engine for FermatMind operational editorial content.',
            'Translate the provided zh-CN source content into '.$this->targetLanguageLabel($targetLocale).'.',
            sprintf('Content type: %s.', $contentType),
            'Return JSON only and match the required schema exactly.',
            'Preserve markdown structure, heading hierarchy, links, URLs, and inline citation markers when present.',
            'Do not invent policy claims, medical claims, legal claims, or operational instructions that are not in the source.',
            'Do not translate slugs, canonical paths, route segments, identifiers, or metadata outside the requested text fields.',
            'Do not add preambles, notes, or markdown fences.',
        ]);
    }

    /**
     * @param  array{title:string,summary:string|null,body_md:string,seo_title:string|null,seo_description:string|null}  $normalizedSource
     */
    private function userPrompt(string $contentType, object $sourceRecord, array $normalizedSource, string $targetLocale): string
    {
        return implode("\n\n", [
            'Target locale: '.trim($targetLocale),
            'Target language: '.$this->targetLanguageLabel($targetLocale),
            'Content type: '.$contentType,
            'Source slug: '.trim((string) data_get($sourceRecord, 'slug', '')),
            'Source title:',
            trim((string) ($normalizedSource['title'] ?? '')),
            'Source summary:',
            trim((string) ($normalizedSource['summary'] ?? '')),
            'Source SEO title:',
            trim((string) ($normalizedSource['seo_title'] ?? '')),
            'Source SEO description:',
            trim((string) ($normalizedSource['seo_description'] ?? '')),
            'Source markdown body:',
            trim((string) ($normalizedSource['body_md'] ?? '')),
            'Output rules:',
            implode("\n", [
                '- Translate only the fields requested by the schema.',
                '- Keep markdown valid and readable.',
                '- Preserve links, URLs, support route references, and stable identifiers already present in the text.',
                '- Keep the translation faithful to the source; do not summarize or omit sections.',
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{title:string,summary:string|null,body_md:string,seo_title:string|null,seo_description:string|null}
     */
    private function normalizeStructuredTranslation(array $decoded): array
    {
        $jsonPayload = $this->extractStructuredText($decoded);

        $parsed = json_decode($jsonPayload, true);
        if (! is_array($parsed)) {
            throw new RuntimeException('OpenAI CMS translation returned invalid structured JSON.');
        }

        $title = trim((string) ($parsed['title'] ?? ''));
        $bodyMd = trim((string) ($parsed['body_md'] ?? ''));

        if ($title === '' || $bodyMd === '') {
            throw new RuntimeException('OpenAI CMS translation response is missing required translated fields.');
        }

        return [
            'title' => $title,
            'summary' => $this->normalizeNullableText($parsed['summary'] ?? null),
            'body_md' => $bodyMd,
            'seo_title' => $this->normalizeNullableText($parsed['seo_title'] ?? null),
            'seo_description' => $this->normalizeNullableText($parsed['seo_description'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function extractStructuredText(array $decoded): string
    {
        $output = $decoded['output'] ?? null;
        if (! is_array($output)) {
            throw new RuntimeException('OpenAI CMS translation response is missing output content.');
        }

        foreach ($output as $message) {
            if (! is_array($message)) {
                continue;
            }

            $content = $message['content'] ?? null;
            if (! is_array($content)) {
                continue;
            }

            foreach ($content as $item) {
                if (! is_array($item)) {
                    continue;
                }

                if (($item['type'] ?? null) !== 'output_text') {
                    continue;
                }

                $text = trim((string) ($item['text'] ?? ''));
                if ($text !== '') {
                    return $text;
                }
            }
        }

        throw new RuntimeException('OpenAI CMS translation response did not include structured output_text content.');
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }

    private function targetLanguageLabel(string $targetLocale): string
    {
        return match (strtolower(trim($targetLocale))) {
            'en', 'en-us', 'en-gb' => 'English',
            default => strtoupper(trim($targetLocale)) !== '' ? strtoupper(trim($targetLocale)) : 'the requested target language',
        };
    }
}
