<?php

declare(strict_types=1);

namespace App\Services\Assessment\Norms;

use App\Services\Psychometrics\Sds\NormGroupResolver as PsychometricNormGroupResolver;

final class SdsNormGroupResolver
{
    public function __construct(
        private readonly PsychometricNormGroupResolver $resolver,
    ) {}

    /**
     * @param  array<string,mixed>  $ctx
     * @return array<string,mixed>
     */
    public function resolve(array $ctx): array
    {
        return $this->resolver->resolve('SDS_20', [
            'locale' => (string) ($ctx['locale'] ?? ''),
            'region' => (string) ($ctx['region'] ?? ($ctx['country'] ?? '')),
            'country' => (string) ($ctx['country'] ?? ($ctx['region'] ?? '')),
            'gender' => (string) ($ctx['gender'] ?? 'ALL'),
            'age_band' => (string) ($ctx['age_band'] ?? ''),
            'age' => isset($ctx['age']) ? (int) $ctx['age'] : 0,
        ]);
    }
}
