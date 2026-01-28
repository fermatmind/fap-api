<?php

namespace App\Services\Memory;

final class MemoryRedactor
{
    private array $blockedPatterns = [
        '/\bssn\b/i',
        '/\bpassport\b/i',
        '/\bdriver\s*license\b/i',
        '/\bcredit\s*card\b/i',
        '/\bbank\s*account\b/i',
    ];

    public function redact(string $content): array
    {
        $redacted = $content;
        $flags = [];

        foreach ($this->blockedPatterns as $pattern) {
            if (preg_match($pattern, $redacted)) {
                $flags[] = $pattern;
                $redacted = preg_replace($pattern, '[redacted]', $redacted) ?? $redacted;
            }
        }

        return [
            'content' => $redacted,
            'flags' => $flags,
        ];
    }
}
