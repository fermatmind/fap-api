<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class InvalidSkuException extends RuntimeException
{
    public function __construct(string $message = 'invalid sku.')
    {
        parent::__construct($message);
    }
}
