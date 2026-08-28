<?php

declare(strict_types=1);

namespace App\Services\SeoAgentGovernance;

use RuntimeException;

final class SeoPromptRegistry
{
    private const PROMPTS = [
        ['id' => 'seo.orchestrator.prompt', 'version' => '1.0.0', 'file' => 'seo-orchestrator.v1.md'],
        ['id' => 'seo.expert.review.prompt', 'version' => '1.0.0', 'file' => 'seo-expert-review.v1.md'],
        ['id' => 'seo.independent_reviewer.prompt', 'version' => '1.0.0', 'file' => 'seo-independent-reviewer.v1.md'],
        ['id' => 'career.content_agent.prompt', 'version' => '1.0.0', 'file' => 'career-content-agent.v1.md'],
    ];

    public function __construct(private readonly SeoRegistryHasher $hasher) {}

    /**
     * @return array<string, array{id:string,version:string,hash:string,path:string}>
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach (self::PROMPTS as $prompt) {
            $path = resource_path('seo-agent/prompts/'.$prompt['file']);
            $bytes = file_get_contents($path);
            if (! is_string($bytes)) {
                throw new RuntimeException('SEO prompt is unreadable: '.$prompt['file']);
            }

            $definitions[$prompt['id']] = [
                'id' => $prompt['id'],
                'version' => $prompt['version'],
                'hash' => $this->hasher->promptHash($bytes),
                'path' => 'backend/resources/seo-agent/prompts/'.$prompt['file'],
            ];
        }

        return $definitions;
    }
}
