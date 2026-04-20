<?php

declare(strict_types=1);

namespace App\Services\Enneagram;

use App\Exceptions\Api\ApiProblemException;
use App\Services\Content\EnneagramPackLoader;

final class EnneagramFormCatalog
{
    /**
     * @var array<string,array<string,mixed>>
     */
    private array $resolved = [];

    public function __construct(
        private readonly EnneagramPackLoader $packLoader,
    ) {}

    /**
     * @return array{
     *   form_code:string,
     *   pack_id:string,
     *   dir_version:string,
     *   content_package_version:string,
     *   norm_version:string,
     *   scoring_spec_version:string,
     *   quality_version:string,
     *   question_count:int,
     *   form_kind:string
     * }
     */
    public function resolve(?string $requestedFormCode, ?string $packId = null): array
    {
        $canonical = $this->canonicalize($requestedFormCode);
        $effectivePackId = trim((string) ($packId ?? EnneagramPackLoader::PACK_ID));
        if (strtoupper($effectivePackId) !== EnneagramPackLoader::PACK_ID) {
            throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', "ENNEAGRAM form pack mismatch: {$effectivePackId}");
        }

        $cacheKey = $effectivePackId.'|'.$canonical;
        if (isset($this->resolved[$cacheKey])) {
            return $this->resolved[$cacheKey];
        }

        $forms = $this->formsConfig();
        $formConfig = $forms[$canonical] ?? null;
        if (! is_array($formConfig)) {
            throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', 'ENNEAGRAM form mapping is not configured.');
        }

        $dirVersion = trim((string) ($formConfig['dir_version'] ?? ''));
        if ($dirVersion === '') {
            throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', 'ENNEAGRAM form dir_version is not configured.');
        }

        $expectedCount = (int) ($formConfig['question_count'] ?? 0);
        if ($expectedCount <= 0) {
            throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', 'ENNEAGRAM form question_count is not configured.');
        }

        $manifest = $this->packLoader->readCompiledJson('manifest.json', $dirVersion);
        $policyCompiled = $this->packLoader->readCompiledJson('policy.compiled.json', $dirVersion);
        if (! is_array($manifest) || ! is_array($policyCompiled)) {
            throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', "ENNEAGRAM form compiled metadata missing: {$canonical}");
        }

        $questionCount = $this->packLoader->getQuestionCount($dirVersion);
        if ($questionCount !== $expectedCount) {
            throw new ApiProblemException(
                500,
                'CONTENT_PACK_ERROR',
                "ENNEAGRAM form question_count mismatch: {$canonical} expected={$expectedCount} actual={$questionCount}"
            );
        }

        $policy = is_array($policyCompiled['policy'] ?? null) ? $policyCompiled['policy'] : [];
        $resolved = [
            'form_code' => $canonical,
            'pack_id' => EnneagramPackLoader::PACK_ID,
            'dir_version' => $dirVersion,
            'content_package_version' => trim((string) ($manifest['pack_version'] ?? $dirVersion)),
            'norm_version' => '',
            'scoring_spec_version' => trim((string) ($policy['scoring_spec_version'] ?? ($manifest['scoring_spec_version'] ?? ''))),
            'quality_version' => trim((string) ($policy['quality_version'] ?? ($manifest['quality_version'] ?? ''))),
            'question_count' => $questionCount,
            'form_kind' => trim((string) ($formConfig['form_kind'] ?? ($manifest['form_kind'] ?? ''))),
        ];

        $this->resolved[$cacheKey] = $resolved;

        return $resolved;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function formsConfig(): array
    {
        $forms = config('content_packs.enneagram_forms.forms', []);

        return is_array($forms) ? $forms : [];
    }

    private function defaultFormCode(): string
    {
        $default = trim((string) config('content_packs.enneagram_forms.default_form_code', 'enneagram_likert_105'));

        return $default !== '' ? $default : 'enneagram_likert_105';
    }

    private function canonicalize(?string $requestedFormCode): string
    {
        $normalized = strtolower(trim((string) $requestedFormCode));
        if ($normalized === '') {
            return $this->defaultFormCode();
        }

        foreach ($this->formsConfig() as $canonical => $config) {
            if ($normalized === strtolower((string) $canonical)) {
                return (string) $canonical;
            }

            $aliases = is_array($config['aliases'] ?? null) ? $config['aliases'] : [];
            foreach ($aliases as $alias) {
                if ($normalized === strtolower(trim((string) $alias))) {
                    return (string) $canonical;
                }
            }
        }

        throw new ApiProblemException(422, 'INVALID_FORM_CODE', "unsupported ENNEAGRAM form_code: {$requestedFormCode}");
    }
}
