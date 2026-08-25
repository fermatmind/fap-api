<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class PublicAuthorityChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $pageEntityType,
        public string $entityIdentity,
        public string $locale,
        public string $revision,
        public string $change,
    ) {}
}
