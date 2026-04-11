<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

final class CareerIndexLifecycleState
{
    public const NOINDEX = 'noindex';

    public const PROMOTION_CANDIDATE = 'promotion_candidate';

    public const INDEXED = 'indexed';

    public const DEMOTED = 'demoted';
}
