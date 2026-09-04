<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Model;

final readonly class SeoCouncilModelRequest
{
    /**
     * @param  array<string, mixed>  $evidenceContext
     * @param  array{namespace:string,version:string,hash:string,instructions:string}  $prompt
     * @param  array<string, mixed>  $outputSchema
     */
    public function __construct(
        public string $model,
        public array $evidenceContext,
        public array $prompt,
        public array $outputSchema,
        public int $maxModelCalls,
        public int $maxOutputTokens,
        public int $deadlineMilliseconds,
        public int $maxResponseBytes,
    ) {}

    /** @return array<string, mixed> */
    public function providerPayload(): array
    {
        return [
            'model' => $this->model,
            'prompt' => $this->prompt,
            'evidence_context' => $this->evidenceContext,
            'output_schema' => $this->outputSchema,
            'max_output_tokens' => $this->maxOutputTokens,
        ];
    }
}
