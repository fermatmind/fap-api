<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerCanonicalEligibilityAuditRow
{
    /**
     * @param  list<string>  $reasons
     * @param  list<mixed>  $evidence
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $locale,
        public readonly string $sourceScope,
        public readonly CareerCanonicalEligibilityLayerStatus $entityStatus,
        public readonly CareerCanonicalEligibilityLayerStatus $baselineStatus,
        public readonly CareerCanonicalEligibilityLayerStatus $indexStatus,
        public readonly CareerCanonicalEligibilityLayerStatus $runtimeStatus,
        public readonly CareerCanonicalEligibilityLayerStatus $seoGeoStatus,
        public readonly CareerCanonicalEligibilityLayerStatus $surfaceStatus,
        public readonly CareerCanonicalEligibilityLayerStatus $safetyStatus,
        public readonly string $overallStatus,
        public readonly string $severity,
        public readonly array $reasons = [],
        public readonly array $evidence = [],
        public readonly array $sidecars = [],
    ) {
        self::assertNonEmptyString($this->slug, 'slug');
        self::assertNonEmptyString($this->locale, 'locale');
        CareerCanonicalEligibilityScope::assertValid($this->sourceScope);
        CareerCanonicalEligibilityStatus::assertValid($this->overallStatus);
        CareerCanonicalEligibilitySeverity::assertValid($this->severity);
        self::assertList('reasons', $this->reasons);
        self::assertList('evidence', $this->evidence);
        self::assertSidecars($this->sidecars);

        self::assertLayer($this->entityStatus, CareerCanonicalEligibilityLayer::ENTITY, 'entity_status');
        self::assertLayer($this->baselineStatus, CareerCanonicalEligibilityLayer::BASELINE, 'baseline_status');
        self::assertLayer($this->indexStatus, CareerCanonicalEligibilityLayer::INDEX, 'index_status');
        self::assertLayer($this->runtimeStatus, CareerCanonicalEligibilityLayer::RUNTIME, 'runtime_status');
        self::assertLayer($this->seoGeoStatus, CareerCanonicalEligibilityLayer::SEO_GEO, 'seo_geo_status');
        self::assertLayer($this->surfaceStatus, CareerCanonicalEligibilityLayer::SURFACE, 'surface_status');
        self::assertLayer($this->safetyStatus, CareerCanonicalEligibilityLayer::SAFETY, 'safety_status');
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public static function fromArray(array $value): self
    {
        return new self(
            slug: self::requiredString($value, 'slug'),
            locale: self::requiredString($value, 'locale'),
            sourceScope: self::requiredString($value, 'source_scope'),
            entityStatus: self::requiredLayerStatus($value, 'entity_status', CareerCanonicalEligibilityLayer::ENTITY),
            baselineStatus: self::requiredLayerStatus($value, 'baseline_status', CareerCanonicalEligibilityLayer::BASELINE),
            indexStatus: self::requiredLayerStatus($value, 'index_status', CareerCanonicalEligibilityLayer::INDEX),
            runtimeStatus: self::requiredLayerStatus($value, 'runtime_status', CareerCanonicalEligibilityLayer::RUNTIME),
            seoGeoStatus: self::requiredLayerStatus($value, 'seo_geo_status', CareerCanonicalEligibilityLayer::SEO_GEO),
            surfaceStatus: self::requiredLayerStatus($value, 'surface_status', CareerCanonicalEligibilityLayer::SURFACE),
            safetyStatus: self::requiredLayerStatus($value, 'safety_status', CareerCanonicalEligibilityLayer::SAFETY),
            overallStatus: self::requiredString($value, 'overall_status'),
            severity: self::requiredString($value, 'severity'),
            reasons: self::optionalList($value, 'reasons'),
            evidence: self::optionalList($value, 'evidence'),
            sidecars: self::optionalSidecars($value, 'sidecars'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'locale' => $this->locale,
            'source_scope' => $this->sourceScope,
            'entity_status' => $this->entityStatus->toArray(),
            'baseline_status' => $this->baselineStatus->toArray(),
            'index_status' => $this->indexStatus->toArray(),
            'runtime_status' => $this->runtimeStatus->toArray(),
            'seo_geo_status' => $this->seoGeoStatus->toArray(),
            'surface_status' => $this->surfaceStatus->toArray(),
            'safety_status' => $this->safetyStatus->toArray(),
            'overall_status' => $this->overallStatus,
            'severity' => $this->severity,
            'reasons' => $this->reasons,
            'evidence' => $this->evidence,
            'sidecars' => array_map(
                static fn (CareerCanonicalEligibilitySidecar $sidecar): array => $sidecar->toArray(),
                $this->sidecars
            ),
        ];
    }

    private static function assertLayer(CareerCanonicalEligibilityLayerStatus $status, string $expectedLayer, string $field): void
    {
        if ($status->layer !== $expectedLayer) {
            throw new InvalidArgumentException(sprintf('Career canonical eligibility row [%s] must use layer [%s].', $field, $expectedLayer));
        }
    }

    private static function assertNonEmptyString(string $value, string $key): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career canonical eligibility row requires non-empty [%s].', $key));
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function requiredString(array $value, string $key): string
    {
        if (! array_key_exists($key, $value) || ! is_string($value[$key]) || trim($value[$key]) === '') {
            throw new InvalidArgumentException(sprintf('Career canonical eligibility row requires non-empty [%s].', $key));
        }

        return $value[$key];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function requiredLayerStatus(array $value, string $key, string $layer): CareerCanonicalEligibilityLayerStatus
    {
        if (! array_key_exists($key, $value) || ! is_array($value[$key])) {
            throw new InvalidArgumentException(sprintf('Career canonical eligibility row requires layer status [%s].', $key));
        }

        $status = CareerCanonicalEligibilityLayerStatus::fromArray($value[$key]);
        self::assertLayer($status, $layer, $key);

        return $status;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return list<mixed>
     */
    private static function optionalList(array $value, string $key): array
    {
        if (! array_key_exists($key, $value)) {
            return [];
        }

        if (! is_array($value[$key])) {
            throw new InvalidArgumentException(sprintf('Career canonical eligibility row [%s] must be a list.', $key));
        }

        self::assertList($key, $value[$key]);

        return $value[$key];
    }

    /**
     * @param  array<string, mixed>  $value
     * @return list<CareerCanonicalEligibilitySidecar>
     */
    private static function optionalSidecars(array $value, string $key): array
    {
        if (! array_key_exists($key, $value)) {
            return [];
        }

        if (! is_array($value[$key]) || ! array_is_list($value[$key])) {
            throw new InvalidArgumentException('Career canonical eligibility row sidecars must be a list.');
        }

        return array_map(static function (mixed $sidecar): CareerCanonicalEligibilitySidecar {
            if ($sidecar instanceof CareerCanonicalEligibilitySidecar) {
                return $sidecar;
            }

            if (! is_array($sidecar)) {
                throw new InvalidArgumentException('Career canonical eligibility row sidecar item must be an object.');
            }

            return CareerCanonicalEligibilitySidecar::fromArray($sidecar);
        }, $value[$key]);
    }

    private static function assertList(string $key, array $value): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career canonical eligibility row [%s] must be a list.', $key));
        }
    }

    /**
     * @param  list<CareerCanonicalEligibilitySidecar>  $sidecars
     */
    private static function assertSidecars(array $sidecars): void
    {
        if (! array_is_list($sidecars)) {
            throw new InvalidArgumentException('Career canonical eligibility row sidecars must be a list.');
        }

        foreach ($sidecars as $sidecar) {
            if (! $sidecar instanceof CareerCanonicalEligibilitySidecar) {
                throw new InvalidArgumentException('Career canonical eligibility row sidecars must contain sidecar DTOs.');
            }
        }
    }
}
