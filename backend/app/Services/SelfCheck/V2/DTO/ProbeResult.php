<?php

declare(strict_types=1);

namespace App\Services\SelfCheck\V2\DTO;

final class ProbeResult
{
    /**
     * @param array<string,mixed> $details
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $errorCode = '',
        public readonly string $message = '',
        public readonly array $details = [],
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(bool $verbose = false): array
    {
        $out = [
            'ok' => $this->ok,
            'error_code' => $this->errorCode,
        ];

        if ($verbose) {
            $out['message'] = $this->message;
            $out['details'] = $this->details;
        }

        return $out;
    }
}
