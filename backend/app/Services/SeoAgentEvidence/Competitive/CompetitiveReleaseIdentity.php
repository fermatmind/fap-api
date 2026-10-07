<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Competitive;

use InvalidArgumentException;

final class CompetitiveReleaseIdentity
{
    public function reference(string $environment, string $releaseSha, ?string $cycle = null): string
    {
        if (! in_array($environment, ['staging', 'production'], true)
            || preg_match('/^[a-f0-9]{40}$/D', $releaseSha) !== 1
            || $cycle !== null && (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $cycle) !== 1
                || \DateTimeImmutable::createFromFormat('!Y-m-d', $cycle)->format('Y-m-d') !== $cycle)) {
            throw new InvalidArgumentException('COMPETITIVE_RELEASE_IDENTITY_INVALID');
        }

        return 'release_'.strtr(hash('sha256', $environment.'|'.$releaseSha.($cycle === null ? '' : '|'.$cycle)), '0123456789', 'ghijklmnop');
    }

    /** Actual business dependencies, independent from release and A08 test scope. */
    public function dependencyHash(): string
    {
        $inputs = [];
        foreach (['app/Services/SeoAgentEvidence', 'app/Services/SeoCouncil/Competitive',
            'app/Services/SeoCouncil/Measurement', 'app/Services/SeoAgentPolicyGateway',
            'app/Services/SeoAgentGovernance', 'resources/seo-agent/evidence', 'resources/seo-agent/policy-gateway',
            'resources/seo-agent/policies', 'resources/seo-agent/schemas', 'resources/seo-agent/prompts'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($directory), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && ! $file->isLink()) {
                    $inputs[substr($file->getPathname(), strlen(base_path()) + 1)] = hash_file('sha256', $file->getPathname());
                }
            }
        }
        foreach (['composer.json', 'composer.lock', 'config/seo_agent_evidence.php',
            'app/Providers/SeoAgentEvidenceServiceProvider.php'] as $file) {
            $inputs[$file] = hash_file('sha256', base_path($file));
        }
        $inputs['query_key_version'] = config('seo_agent_evidence.query_hmac_key_version');
        $inputs['content_authority'] = hash_file('sha256', base_path('content_assets/personality_public/current/manifest.json'));
        ksort($inputs, SORT_STRING);

        return hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR));
    }
}
