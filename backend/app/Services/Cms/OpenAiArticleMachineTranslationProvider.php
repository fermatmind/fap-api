<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Contracts\Cms\ArticleMachineTranslationProvider;
use App\Models\Article;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class OpenAiArticleMachineTranslationProvider implements ArticleMachineTranslationProvider
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    public function isConfigured(): bool
    {
        return $this->configuredProvider() === 'openai'
            && $this->apiKey() !== ''
            && $this->model() !== '';
    }

    public function unavailableReason(): ?string
    {
        if ($this->configuredProvider() !== 'openai') {
            return sprintf(
                'Article machine translation provider [%s] is not supported. Set ARTICLE_TRANSLATION_PROVIDER=openai or bind a custom ArticleMachineTranslationProvider.',
                $this->configuredProvider() !== '' ? $this->configuredProvider() : 'disabled'
            );
        }

        if ($this->apiKey() === '') {
            return 'Article machine translation provider is not configured. Set ARTICLE_TRANSLATION_OPENAI_API_KEY (or OPENAI_API_KEY).';
        }

        if ($this->model() === '') {
            return 'Article machine translation provider is not configured. Set ARTICLE_TRANSLATION_OPENAI_MODEL.';
        }

        return null;
    }

    public function translate(Article $source, string $targetLocale): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException((string) $this->unavailableReason());
        }

        $source->loadMissing('seoMeta');
        $payload = [
            'model' => $this->model(),
            'max_output_tokens' => $this->maxOutputTokens(),
            'input' => [
                [
                    'role' => 'system',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->systemPrompt($targetLocale),
                        ],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'input_text',
                            'text' => $this->userPrompt($source, $targetLocale),
                        ],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'article_translation_payload',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => [
                            'title',
                            'excerpt',
                            'content_md',
                            'seo_title',
                            'seo_description',
                        ],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'excerpt' => ['type' => ['string', 'null']],
                            'content_md' => ['type' => 'string'],
                            'seo_title' => ['type' => ['string', 'null']],
                            'seo_description' => ['type' => ['string', 'null']],
                        ],
                    ],
                ],
            ],
            'metadata' => [
                'surface' => 'article_translation',
                'source_article_id' => (string) $source->id,
                'source_slug' => (string) $source->slug,
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
        } catch (RequestException $e) {
            $status = $e->response?->status();
            $body = $e->response?->json();
            $message = is_array($body)
                ? (string) data_get($body, 'error.message', 'OpenAI article translation request failed.')
                : 'OpenAI article translation request failed.';

            throw new RuntimeException(
                sprintf('OpenAI article translation request failed%s: %s', $status ? " with status {$status}" : '', trim($message))
            );
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            throw new RuntimeException('OpenAI article translation returned a non-JSON payload.');
        }

        return $this->normalizeStructuredTranslation($decoded);
    }

    private function configuredProvider(): string
    {
        return strtolower(trim((string) config('services.article_translation.provider', 'disabled')));
    }

    private function apiKey(): string
    {
        return trim((string) config('services.article_translation.openai.api_key', ''));
    }

    private function model(): string
    {
        return trim((string) config('services.article_translation.openai.model', ''));
    }

    private function endpointUrl(): string
    {
        return rtrim((string) config('services.article_translation.openai.base_url', self::DEFAULT_BASE_URL), '/').'/responses';
    }

    private function connectTimeoutSeconds(): int
    {
        return max(1, (int) config('services.article_translation.openai.connect_timeout_seconds', 5));
    }

    private function requestTimeoutSeconds(): int
    {
        return max(5, (int) config('services.article_translation.openai.request_timeout_seconds', 60));
    }

    private function maxRetries(): int
    {
        return max(0, (int) config('services.article_translation.openai.max_retries', 1));
    }

    private function retrySleepMilliseconds(): int
    {
        return max(0, (int) config('services.article_translation.openai.retry_sleep_milliseconds', 250));
    }

    private function maxOutputTokens(): int
    {
        return max(512, (int) config('services.article_translation.openai.max_output_tokens', 6000));
    }

    private function systemPrompt(string $targetLocale): string
    {
        return implode("\n", [
            'You are a professional editorial translation engine for FermatMind long-form articles.',
            'Translate the provided zh-CN source article into '.$this->targetLanguageLabel($targetLocale).'.',
            'Return JSON only and match the required schema exactly.',
            'Preserve markdown structure, heading hierarchy, links, URLs, DOI strings, PMIDs, and inline citation markers.',
            'Keep bibliographic entries and raw reference URLs in their original language when already English or source-authored.',
            'Do not invent facts, references, citations, or SEO claims.',
            'Do not translate the slug.',
            'Do not add preambles, notes, or markdown fences.',
        ]);
    }

    private function userPrompt(Article $source, string $targetLocale): string
    {
        $seoTitle = trim((string) ($source->seoMeta?->seo_title ?? ''));
        $seoDescription = trim((string) ($source->seoMeta?->seo_description ?? ''));

        return implode("\n\n", [
            'Target locale: '.trim($targetLocale),
            'Target language: '.$this->targetLanguageLabel($targetLocale),
            'Source slug (must remain unchanged outside output text semantics): '.$source->slug,
            'Source title:',
            trim((string) $source->title),
            'Source excerpt:',
            trim((string) ($source->excerpt ?? '')),
            'Source SEO title:',
            $seoTitle,
            'Source SEO description:',
            $seoDescription,
            'Source markdown body:',
            trim((string) ($source->content_md ?? '')),
            'Output rules:',
            implode("\n", [
                '- Translate only the editorial fields requested by the schema.',
                '- Keep markdown valid and readable.',
                '- Preserve source references, citations, DOI strings, URLs, and markdown links.',
                '- Keep any English bibliographic entries intact unless a tiny grammatical connector must change.',
                '- Keep the article faithful to the source; do not summarize or omit sections.',
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{title:string,excerpt:string|null,content_md:string,seo_title:string|null,seo_description:string|null}
     */
    private function normalizeStructuredTranslation(array $decoded): array
    {
        $jsonPayload = $this->extractStructuredText($decoded);

        $parsed = json_decode($jsonPayload, true);
        if (! is_array($parsed)) {
            throw new RuntimeException('OpenAI article translation returned invalid structured JSON.');
        }

        $title = trim((string) ($parsed['title'] ?? ''));
        $contentMd = trim((string) ($parsed['content_md'] ?? ''));

        if ($title === '' || $contentMd === '') {
            throw new RuntimeException('OpenAI article translation response is missing required translated fields.');
        }

        return [
            'title' => $title,
            'excerpt' => $this->normalizeNullableText($parsed['excerpt'] ?? null),
            'content_md' => $contentMd,
            'seo_title' => $this->normalizeNullableText($parsed['seo_title'] ?? null),
            'seo_description' => $this->normalizeNullableText($parsed['seo_description'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function extractStructuredText(array $decoded): string
    {
        $outputText = trim((string) ($decoded['output_text'] ?? ''));
        if ($outputText !== '') {
            return $outputText;
        }

        $fragments = [];
        foreach (($decoded['output'] ?? []) as $outputItem) {
            if (! is_array($outputItem)) {
                continue;
            }

            foreach (($outputItem['content'] ?? []) as $contentItem) {
                if (! is_array($contentItem)) {
                    continue;
                }

                $text = $contentItem['text'] ?? null;
                if (is_string($text) && trim($text) !== '') {
                    $fragments[] = $text;
                }
            }
        }

        $combined = trim(implode("\n", $fragments));
        if ($combined === '') {
            throw new RuntimeException('OpenAI article translation response did not include structured text output.');
        }

        return $combined;
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    private function targetLanguageLabel(string $targetLocale): string
    {
        return match (strtolower(trim($targetLocale))) {
            'en', 'en-us', 'en-gb' => 'English',
            default => trim($targetLocale) !== '' ? trim($targetLocale) : 'English',
        };
    }
}
