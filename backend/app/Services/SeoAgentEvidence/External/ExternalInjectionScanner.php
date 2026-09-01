<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\External;

final class ExternalInjectionScanner
{
    /** @return array{result:string,signals:int} */
    public function scan(mixed $content): array
    {
        $encoded = is_string($content)
            ? $content
            : json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $normalized = class_exists(\Normalizer::class) ? \Normalizer::normalize((string) $encoded, \Normalizer::FORM_KC) : (string) $encoded;
        $text = mb_strtolower((string) $normalized, 'UTF-8');
        $decoded = base64_decode(preg_replace('/\s+/', '', (string) $encoded), true);
        if (is_string($decoded) && mb_check_encoding($decoded, 'UTF-8')) {
            $text .= ' '.mb_strtolower($decoded, 'UTF-8');
        }
        $patterns = [
            '/ignore\s+(?:all\s+)?previous\s+instructions?/',
            '/(?:system|developer|assistant|tool)\s*(?:prompt|message|role)\s*[:=]/',
            '/<\/?(?:system|developer|assistant|tool|prompt|function_call)[^>]*>/',
            '/```(?:system|prompt|tool|shell|sql)/',
            '/"(?:tool_call|function_call|tool_allowlist|egress_allowlist|authority_ceiling|write_permissions|execution_allowed|policy_hash|prompt_hash)"\s*:/',
            '/(?:tool_allowlist|egress_allowlist|authority_ceiling|write_permissions|execution_allowed|policy_hash|prompt_hash)\s*[:=]/',
            '/(?:\b(?:curl|wget|bash|powershell)\b\s+(?:-|https?:\/\/)|\bsh\b\s+-[a-z]|\bdrop\s+table\b|\bunion\s+select\b)[^\n]{0,120}/',
            '/(?:display\s*:\s*none|visibility\s*:\s*hidden)[^\n]{0,120}(?:instruction|prompt|tool|execute)/',
        ];
        $signals = 0;
        foreach ($patterns as $pattern) {
            $signals += preg_match_all($pattern, $text) ?: 0;
        }

        return ['result' => $signals > 0 ? 'blocked' : 'pass', 'signals' => $signals];
    }
}
