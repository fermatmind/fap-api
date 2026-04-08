<?php

declare(strict_types=1);

namespace App\Domain\Career;

final class IndexStateValue
{
    public const UNAVAILABLE = 'unavailable';

    public const TRUST_LIMITED = 'trust_limited';

    public const NOINDEX = 'noindex';

    public const INDEXABLE = 'indexable';
}
