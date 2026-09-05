<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

final readonly class PromotionExecutionContext
{
    /** @param array<string, string> $runtimeValues */
    public function __construct(private array $runtimeValues) {}

    public function value(string $configKey, string $default = ''): string
    {
        if (array_key_exists($configKey, $this->runtimeValues)) {
            return $this->runtimeValues[$configKey];
        }

        $value = config($configKey);
        if ((is_string($value) && $value !== '') || is_int($value)) {
            return (string) $value;
        }

        return $default;
    }
}
