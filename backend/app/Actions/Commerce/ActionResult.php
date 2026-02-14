<?php

declare(strict_types=1);

namespace App\Actions\Commerce;

final class ActionResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
        public readonly array $data = [],
    ) {}

    public static function success(array $data = []): self
    {
        return new self(true, null, null, $data);
    }

    public static function failure(string $code, string $message, array $data = []): self
    {
        return new self(false, $code, $message, $data);
    }

    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'code' => $this->code,
            'message' => $this->message,
            'data' => $this->data,
        ];
    }
}
