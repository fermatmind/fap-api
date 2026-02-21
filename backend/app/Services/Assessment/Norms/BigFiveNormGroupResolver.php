<?php

declare(strict_types=1);

namespace App\Services\Assessment\Norms;

use App\Services\Psychometrics\Big5\NormGroupResolver as PsychometricNormGroupResolver;

final class BigFiveNormGroupResolver
{
    public function __construct(
        private readonly PsychometricNormGroupResolver $resolver,
    ) {
    }

    /**
     * @param array<string,mixed> $normsCompiled
     * @param array<string,mixed> $ctx
     * @return array{
     *   group_id:string,
     *   status:string,
     *   domain_group_id:string,
     *   facet_group_id:string,
     *   domains:array<string,array<string,mixed>>,
     *   facets:array<string,array<string,mixed>>,
     *   norms_version?:string,
     *   source_id?:string,
     *   source_type?:string,
     *   origin?:string
     * }
     */
    public function resolve(array $normsCompiled, array $ctx): array
    {
        return $this->resolver->resolve('BIG5_OCEAN', [
            'locale' => (string) ($ctx['locale'] ?? ''),
            'country' => (string) ($ctx['country'] ?? ($ctx['region'] ?? '')),
            'region' => (string) ($ctx['country'] ?? ($ctx['region'] ?? '')),
            'age_band' => (string) ($ctx['age_band'] ?? ''),
            'age' => isset($ctx['age']) ? (int) $ctx['age'] : 0,
            'gender' => (string) ($ctx['gender'] ?? 'ALL'),
        ], $normsCompiled);
    }
}
