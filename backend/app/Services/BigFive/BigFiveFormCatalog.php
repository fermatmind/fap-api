<?php

declare(strict_types=1);

namespace App\Services\BigFive;

use App\Exceptions\Api\ApiProblemException;
use App\Services\Content\BigFivePackLoader;

final class BigFiveFormCatalog
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $resolved = [];

    public function __construct(
        private readonly BigFivePackLoader $packLoader,
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
     *   question_count:int
     * }
     */
    public function resolve(?string $requestedFormCode, ?string $packId = null): array
    {
        $canonical = $this->canonicalize($requestedFormCode);
        $effectivePackId = trim((string) ($packId ?? BigFivePackLoader::PACK_ID));
        if ($effectivePackId === '') {
            throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', 'BIG5 pack_id is not configured.');
        }
        if (strtoupper($effectivePackId) !== BigFivePackLoader::PACK_ID) {
            throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', "BIG5 form pack mismatch: {$effectivePackId}");
        }

        $cacheKey = $effectivePackId.'|'.$canonical;
        if (isset($this->resolved[$cacheKey])) {
            return $this->resolved[$cacheKey];
        }

        $forms = $this->formsConfig();
        $formConfig = $forms[$canonical] ?? null;
        if (! is_array($formConfig)) {
            throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', 'BIG5 form mapping is not configured.');
        }

        $dirVersion = trim((string) ($formConfig['dir_version'] ?? ''));
        if ($dirVersion === '') {
            throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', 'BIG5 form dir_version is not configured.');
        }

        $expectedCount = (int) ($formConfig['question_count'] ?? 0);
        if ($expectedCount <= 0) {
            $expectedCount = $canonical === 'big5_90' ? 90 : 120;
        }

        $manifest = $this->packLoader->readCompiledJson('manifest.json', $dirVersion);
        if (! is_array($manifest)) {
            throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', "BIG5 form manifest missing: {$canonical}");
        }
        $policyCompiled = $this->packLoader->readCompiledJson('policy.compiled.json', $dirVersion);
        if (! is_array($policyCompiled)) {
            throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', "BIG5 form policy missing: {$canonical}");
        }
        $normsCompiled = $this->packLoader->readCompiledJson('norms.compiled.json', $dirVersion);
        if (! is_array($normsCompiled)) {
            throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', "BIG5 form norms missing: {$canonical}");
        }

        $questionIndex = $this->packLoader->readQuestionIndexPreferred($dirVersion, 0);
        if (! is_array($questionIndex) || $questionIndex === []) {
            $compiled = $this->packLoader->readCompiledJson('questions.compiled.json', $dirVersion);
            if (! is_array($compiled)) {
                throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', "BIG5 form questions missing: {$canonical}");
            }
            $questionIndex = is_array($compiled['question_index'] ?? null) ? $compiled['question_index'] : [];
        }

        $questionCount = count($questionIndex);
        if ($questionCount !== $expectedCount) {
            throw new ApiProblemException(
                500,
                'CONTENT_PACK_ERROR',
                "BIG5 form question_count mismatch: {$canonical} expected={$expectedCount} actual={$questionCount}"
            );
        }

        $policy = is_array($policyCompiled['policy'] ?? null) ? $policyCompiled['policy'] : [];
        $groups = is_array($normsCompiled['groups'] ?? null) ? $normsCompiled['groups'] : [];
        $firstGroup = is_array(reset($groups)) ? (array) reset($groups) : [];

        $contentPackageVersion = trim((string) ($manifest['pack_version'] ?? $dirVersion));
        $scoringSpecVersion = trim((string) ($policy['scoring_spec_version'] ?? ($policy['spec_version'] ?? '')));
        $qualityVersion = trim((string) ($policy['quality_version'] ?? $scoringSpecVersion));
        $normVersion = trim((string) ($policy['norms_version'] ?? ($manifest['norms_version'] ?? ($firstGroup['norms_version'] ?? ''))));

        $resolved = [
            'form_code' => $canonical,
            'pack_id' => $effectivePackId,
            'dir_version' => $dirVersion,
            'content_package_version' => $contentPackageVersion,
            'norm_version' => $normVersion,
            'scoring_spec_version' => $scoringSpecVersion,
            'quality_version' => $qualityVersion,
            'question_count' => $questionCount,
        ];

        $this->resolved[$cacheKey] = $resolved;

        return $resolved;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function formsConfig(): array
    {
        $forms = config('content_packs.big5_forms.forms', []);

        return is_array($forms) ? $forms : [];
    }

    private function defaultFormCode(): string
    {
        $default = trim((string) config('content_packs.big5_forms.default_form_code', 'big5_120'));

        return $default !== '' ? $default : 'big5_120';
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

        throw new ApiProblemException(422, 'INVALID_FORM_CODE', "unsupported BIG5 form_code: {$requestedFormCode}");
    }
}
