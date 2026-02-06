<?php

declare(strict_types=1);

namespace App\DTO;

final class ContentPack
{
    public function __construct(
        public string $packId,
        public string $dirVersion,
    ) {
    }
}
