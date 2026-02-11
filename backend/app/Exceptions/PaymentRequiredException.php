<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class PaymentRequiredException extends HttpException
{
    public function __construct(string $message = 'PAYMENT_REQUIRED')
    {
        parent::__construct(402, $message);
    }
}
