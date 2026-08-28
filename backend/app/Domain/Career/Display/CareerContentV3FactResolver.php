<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

final class CareerContentV3FactResolver
{
    /** @param array<string,mixed> $content @return array<string,mixed> */
    public function resolve(array $content): array
    {
        CareerContentV3Contract::assert($content);
        $facts = [];
        foreach ((array) data_get($content, 'fact_register.facts', []) as $fact) {
            if (is_array($fact) && is_string($fact['fact_id'] ?? null)) {
                $facts[$fact['fact_id']] = $fact;
            }
        }
        $this->assertDerivedFacts($facts);
        $resolved = $this->replace($content, $facts);
        if (! is_array($resolved) || array_is_list($resolved)
            || str_contains(CareerCurrentAuthorityPackage::encodeCanonical($resolved), '{{fact:')) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_FACT_REFERENCE_INVALID');
        }
        CareerContentV3Contract::assert($resolved);

        return $resolved;
    }

    /** @param array<string,array<string,mixed>> $facts */
    private function replace(mixed $value, array $facts): mixed
    {
        if (is_string($value)) {
            return preg_replace_callback(
                '/\{\{fact:([a-z0-9]+(?:[._-][a-z0-9]+)*)\}\}/',
                static function (array $match) use ($facts): string {
                    $display = $facts[$match[1]]['display_value'] ?? null;
                    if (! is_string($display) || trim($display) === '') {
                        throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_FACT_REFERENCE_INVALID');
                    }

                    return $display;
                },
                $value,
            );
        }
        if (! is_array($value)) {
            return $value;
        }
        $resolved = [];
        foreach ($value as $key => $child) {
            $resolved[$key] = $this->replace($child, $facts);
        }

        return $resolved;
    }

    /** @param array<string,array<string,mixed>> $facts */
    private function assertDerivedFacts(array $facts): void
    {
        foreach ($facts as $fact) {
            $derivation = $fact['derivation'] ?? null;
            if (! is_string($derivation) || ! str_contains($derivation, '{{fact:')) {
                continue;
            }
            if (preg_match(
                '/\A\{\{fact:([a-z0-9]+(?:[._-][a-z0-9]+)*)\}\}\s*÷\s*12，(按十元)?四舍五入\z/u',
                $derivation,
                $match,
            ) !== 1) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_FACT_DERIVATION_INVALID');
            }
            $source = $facts[$match[1]]['display_value'] ?? null;
            $derived = $fact['display_value'] ?? null;
            $sourceNumber = $this->number($source);
            $derivedNumber = $this->number($derived);
            $roundingUnit = ($match[2] ?? '') === '按十元' ? 10 : 1;
            $expected = $sourceNumber === null ? null : round(($sourceNumber / 12) / $roundingUnit) * $roundingUnit;
            if ($sourceNumber === null || $derivedNumber === null || $expected !== $derivedNumber) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_FACT_DERIVATION_INVALID');
            }
        }
    }

    private function number(mixed $value): ?float
    {
        if (! is_string($value)) {
            return null;
        }
        $normalized = preg_replace('/[^0-9.\-]/', '', $value);

        return is_string($normalized) && $normalized !== '' && is_numeric($normalized)
            ? (float) $normalized : null;
    }
}
