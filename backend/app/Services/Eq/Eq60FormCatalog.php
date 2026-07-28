<?php

declare(strict_types=1);

namespace App\Services\Eq;

use App\Exceptions\Api\ApiProblemException;
use App\Services\Content\Eq60PackLoader;

final class Eq60FormCatalog
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $resolved = [];

    public function __construct(
        private readonly Eq60PackLoader $packLoader,
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
        $effectivePackId = trim((string) ($packId ?? Eq60PackLoader::PACK_ID));
        if (strtoupper($effectivePackId) !== Eq60PackLoader::PACK_ID) {
            throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', "EQ_60 form pack mismatch: {$effectivePackId}");
        }

        $cacheKey = $effectivePackId.'|'.$canonical;
        if (isset($this->resolved[$cacheKey])) {
            return $this->resolved[$cacheKey];
        }

        $formConfig = $this->formsConfig()[$canonical] ?? null;
        if (! is_array($formConfig)) {
            throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', 'EQ_60 form mapping is not configured.');
        }

        $dirVersion = trim((string) ($formConfig['dir_version'] ?? ''));
        $expectedCount = (int) ($formConfig['question_count'] ?? 0);
        if ($dirVersion === '' || $expectedCount <= 0) {
            throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', 'EQ_60 default form metadata is incomplete.');
        }

        $policy = $this->packLoader->loadPolicy($dirVersion);
        $questionCount = $this->packLoader->getQuestionCount($dirVersion);
        if ($questionCount !== $expectedCount) {
            throw new ApiProblemException(
                500,
                'CONTENT_PACK_ERROR',
                "EQ_60 form question_count mismatch: {$canonical} expected={$expectedCount} actual={$questionCount}"
            );
        }

        $scoringSpecVersion = trim((string) ($policy['scoring_spec_version'] ?? ''));

        return $this->resolved[$cacheKey] = [
            'form_code' => $canonical,
            'pack_id' => Eq60PackLoader::PACK_ID,
            'dir_version' => $dirVersion,
            'content_package_version' => $dirVersion,
            'norm_version' => trim((string) ($policy['norms_version'] ?? '')),
            'scoring_spec_version' => $scoringSpecVersion,
            'quality_version' => trim((string) ($policy['quality_version'] ?? $scoringSpecVersion)),
            'question_count' => $questionCount,
            'form_kind' => trim((string) ($formConfig['form_kind'] ?? '')),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function formsConfig(): array
    {
        $forms = config('content_packs.eq60_forms.forms', []);

        return is_array($forms) ? $forms : [];
    }

    private function canonicalize(?string $requestedFormCode): string
    {
        $normalized = strtolower(trim((string) $requestedFormCode));
        if ($normalized === '') {
            $default = trim((string) config('content_packs.eq60_forms.default_form_code', ''));
            if ($default === '') {
                throw new ApiProblemException(422, 'FORM_UNAVAILABLE', 'EQ_60 default form is unavailable.');
            }

            return $default;
        }

        foreach ($this->formsConfig() as $canonical => $config) {
            if ($normalized === strtolower((string) $canonical)) {
                return (string) $canonical;
            }

            foreach ((array) ($config['aliases'] ?? []) as $alias) {
                if ($normalized === strtolower(trim((string) $alias))) {
                    return (string) $canonical;
                }
            }
        }

        throw new ApiProblemException(422, 'INVALID_FORM_CODE', "unsupported EQ_60 form_code: {$requestedFormCode}");
    }
}
