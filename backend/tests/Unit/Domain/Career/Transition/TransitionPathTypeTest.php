<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Transition;

use App\Domain\Career\Transition\TransitionPathType;
use PHPUnit\Framework\TestCase;

final class TransitionPathTypeTest extends TestCase
{
    public function test_it_accepts_the_current_authoritative_transition_path_type(): void
    {
        $this->assertSame(
            TransitionPathType::StableUpside,
            TransitionPathType::tryNormalize('stable_upside')
        );
        $this->assertSame(
            TransitionPathType::StableUpside,
            TransitionPathType::tryNormalize(' Stable_Upside ')
        );
    }

    public function test_it_rejects_blank_or_unknown_transition_path_types(): void
    {
        $this->assertNull(TransitionPathType::tryNormalize(''));
        $this->assertNull(TransitionPathType::tryNormalize('   '));
        $this->assertNull(TransitionPathType::tryNormalize('bridge_path'));
        $this->assertNull(TransitionPathType::tryNormalize('hedge_path'));
        $this->assertNull(TransitionPathType::tryNormalize(null));
    }
}
