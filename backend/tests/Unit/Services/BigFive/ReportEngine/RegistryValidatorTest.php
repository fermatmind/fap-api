<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ReportEngine;

use App\Services\BigFive\ReportEngine\Registry\RegistryLoader;
use App\Services\BigFive\ReportEngine\Registry\RegistryValidator;
use Tests\TestCase;

final class RegistryValidatorTest extends TestCase
{
    public function test_it_loads_and_validates_the_n_slice_registry(): void
    {
        $registry = app(RegistryLoader::class)->load();
        $errors = app(RegistryValidator::class)->validate($registry);

        $this->assertSame([], $errors);
        $this->assertSame('N', data_get($registry, 'atomic.N.trait_code'));
        $this->assertArrayHasKey('n_g4', (array) data_get($registry, 'modifiers.N.gradients', []));
    }

    public function test_it_rejects_word_level_replace_maps_in_modifiers(): void
    {
        $registry = app(RegistryLoader::class)->load();
        $registry['modifiers']['N']['gradients']['n_g4']['replace_map'] = ['敏感' => '非常敏感'];

        $errors = app(RegistryValidator::class)->validate($registry);

        $this->assertContains('Modifier gradient n_g4 uses forbidden replace_map', $errors);
    }
}
