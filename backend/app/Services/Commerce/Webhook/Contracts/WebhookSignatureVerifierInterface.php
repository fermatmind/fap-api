<?php

declare(strict_types=1);

namespace App\Services\Commerce\Webhook\Contracts;

use Illuminate\Http\Request;

interface WebhookSignatureVerifierInterface
{
    public function provider(): string;

    public function verify(Request $request): bool;
}
