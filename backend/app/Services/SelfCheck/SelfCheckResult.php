<?php

declare(strict_types=1);

namespace App\Services\SelfCheck;

final class SelfCheckResult
{
    public bool $ok = true;

    /** @var array<int, string> */
    public array $errors = [];

    /** @var array<int, string> */
    public array $warnings = [];

    /** @var array<string, mixed> */
    public array $meta = [];

    public function __construct(public string $section)
    {
    }

    public function addError(string $message): self
    {
        $this->ok = false;
        $this->errors[] = $message;
        return $this;
    }

    public function addWarning(string $message): self
    {
        $this->warnings[] = $message;
        return $this;
    }

    public function addNote(string $message): self
    {
        $notes = $this->meta['notes'] ?? [];
        if (!is_array($notes)) {
            $notes = [];
        }
        $notes[] = $message;
        $this->meta['notes'] = $notes;
        return $this;
    }

    public function merge(SelfCheckResult $other): self
    {
        if (!$other->ok) {
            $this->ok = false;
        }

        foreach ($other->errors as $error) {
            $this->errors[] = $error;
        }
        foreach ($other->warnings as $warning) {
            $this->warnings[] = $warning;
        }

        $this->meta = array_replace_recursive($this->meta, $other->meta);

        return $this;
    }

    public function isOk(): bool
    {
        return $this->ok && $this->errors === [];
    }

    /** @return array<int, string> */
    public function notes(): array
    {
        $notes = $this->meta['notes'] ?? [];
        return is_array($notes) ? array_values(array_filter($notes, fn ($n) => is_string($n))) : [];
    }
}
