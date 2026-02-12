<?php

declare(strict_types=1);

namespace App\Services\Content\V2\Contracts;

interface CacheAdapterInterface
{
    public function get(string $key): mixed;

    public function put(string $key, mixed $value, int $ttlSeconds): void;
}
