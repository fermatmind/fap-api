<?php

declare(strict_types=1);

namespace Tests\Unit\Psychometrics\Big5;

use App\Services\Psychometrics\Big5\Big5Standardizer;
use Tests\TestCase;

final class Big5StandardizerTest extends TestCase
{
    public function test_z_zero_maps_to_pct50_t50(): void
    {
        $svc = app(Big5Standardizer::class);

        $out = $svc->standardize(3.0, 3.0, 0.6);

        $this->assertSame(50, $out['pct']);
        $this->assertSame(50, $out['t']);
        $this->assertSame(0.0, $out['z']);
    }

    public function test_z_one_maps_to_pct84(): void
    {
        $svc = app(Big5Standardizer::class);

        $out = $svc->standardize(4.0, 3.0, 1.0);

        $this->assertSame(84, $out['pct']);
        $this->assertSame(60, $out['t']);
        $this->assertSame(1.0, $out['z']);
    }

    public function test_clamp_is_applied_for_extreme_z(): void
    {
        $svc = app(Big5Standardizer::class);

        $out = $svc->standardize(10.0, 0.0, 1.0);

        $this->assertSame(85, $out['t']);
        $this->assertSame(100, $out['pct']);
        $this->assertSame(3.5, $out['z']);
    }
}
