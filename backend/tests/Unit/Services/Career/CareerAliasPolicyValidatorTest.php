<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Services\Career\Authority\CareerAliasPolicyValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerAliasPolicyValidatorTest extends TestCase
{
    #[Test]
    public function it_accepts_unambiguous_alias_catalogs(): void
    {
        $result = app(CareerAliasPolicyValidator::class)->validateCatalog([
            [
                'canonical_slug' => 'data-scientists',
                'aliases' => ['data scientist', ['alias' => 'data science specialist']],
                'blocked_aliases' => ['software engineer'],
            ],
            [
                'canonical_slug' => 'registered-nurses',
                'aliases' => ['registered nurse'],
            ],
        ]);

        $this->assertTrue($result['policy_ok']);
        $this->assertSame([], $result['ambiguous_aliases']);
        $this->assertSame([], $result['blocked_alias_materialized']);
    }

    #[Test]
    public function it_reports_aliases_that_would_silently_map_to_multiple_leaf_occupations(): void
    {
        $result = app(CareerAliasPolicyValidator::class)->validateCatalog([
            ['canonical_slug' => 'software-developers', 'aliases' => ['engineer']],
            ['canonical_slug' => 'civil-engineers', 'aliases' => ['Engineer']],
        ]);

        $this->assertFalse($result['policy_ok']);
        $this->assertSame('engineer', $result['ambiguous_aliases'][0]['normalized_alias']);
        $this->assertSame(['civil-engineers', 'software-developers'], $result['ambiguous_aliases'][0]['slugs']);
    }

    #[Test]
    public function it_reports_blocked_aliases_that_are_materialized_for_the_same_slug(): void
    {
        $result = app(CareerAliasPolicyValidator::class)->validateCatalog([
            [
                'canonical_slug' => 'product-managers',
                'aliases' => ['product owner'],
                'blocked_aliases' => ['Product Owner'],
            ],
        ]);

        $this->assertFalse($result['policy_ok']);
        $this->assertSame([
            ['slug' => 'product-managers', 'alias' => 'product-owner'],
        ], $result['blocked_alias_materialized']);
    }
}
