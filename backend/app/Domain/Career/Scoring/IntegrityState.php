<?php

declare(strict_types=1);

namespace App\Domain\Career\Scoring;

final class IntegrityState
{
    public const FULL = 'full';

    public const PROVISIONAL = 'provisional';

    public const RESTRICTED = 'restricted';

    public const BLOCKED = 'blocked';
}
