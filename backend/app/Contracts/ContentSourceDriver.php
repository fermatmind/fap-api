<?php

declare(strict_types=1);

namespace App\Contracts;

interface ContentSourceDriver
{
    public function get(string $key): string;

    public function exists(string $key): bool;

    /**
     * @return array<int, string>
     */
    public function list(string $prefix): array;

    public function etag(string $key): ?string;
}
