<?php

declare(strict_types=1);

namespace App\Services\Template;

final class TemplateEngine
{
    public function __construct(private readonly TemplateVariableRegistry $registry)
    {
    }

    /**
     * @return list<string>
     */
    public function extractVariables(string $template): array
    {
        if ($template === '') {
            return [];
        }

        preg_match_all('/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}/', $template, $matches);
        $vars = is_array($matches[1] ?? null) ? $matches[1] : [];

        $out = [];
        foreach ($vars as $varName) {
            $key = trim((string) $varName);
            if ($key === '') {
                continue;
            }
            $out[$key] = true;
        }

        return array_keys($out);
    }

    /**
     * @return array{unknown:list<string>,missing:list<string>}
     */
    public function lintString(string $template, ?TemplateContext $context = null): array
    {
        $vars = $this->extractVariables($template);

        $unknown = [];
        foreach ($vars as $varName) {
            if (!$this->registry->isAllowed($varName)) {
                $unknown[] = $varName;
            }
        }

        $missing = [];
        if ($context !== null) {
            $missing = $this->registry->missingRequired($vars, $context);
        }

        return [
            'unknown' => array_values(array_unique($unknown)),
            'missing' => array_values(array_unique($missing)),
        ];
    }

    public function renderString(string $template, TemplateContext $context, string $mode = 'text'): string
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['text', 'htmlsafe'], true)) {
            $mode = 'text';
        }

        $vars = $this->extractVariables($template);
        foreach ($vars as $varName) {
            $this->registry->assertAllowed($varName);
        }

        $missing = $this->registry->missingRequired($vars, $context);
        if ($missing !== []) {
            throw new \InvalidArgumentException('Missing template variables: ' . implode(', ', $missing));
        }

        $rendered = preg_replace_callback('/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}/', function (array $match) use ($context, $mode): string {
            $varName = trim((string) ($match[1] ?? ''));
            if ($varName === '') {
                return (string) ($match[0] ?? '');
            }

            $value = null;
            if (str_starts_with($varName, 'ctx.')) {
                $value = $context->getCtx(substr($varName, 4));
            } else {
                $value = $context->get($varName);
            }

            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $value = is_scalar($value) ? (string) $value : '';

            if ($mode === 'htmlsafe') {
                return $this->sanitizeHtmlSafe($value);
            }

            return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }, $template);

        return is_string($rendered) ? $rendered : $template;
    }

    public function renderReportPayload(mixed $payload, TemplateContext $context, string $mode = 'text'): mixed
    {
        if (is_string($payload)) {
            if (!str_contains($payload, '{{')) {
                return $payload;
            }

            return $this->renderString($payload, $context, $mode);
        }

        if (is_array($payload)) {
            $out = [];
            foreach ($payload as $key => $value) {
                $out[$key] = $this->renderReportPayload($value, $context, $mode);
            }

            return $out;
        }

        return $payload;
    }

    private function sanitizeHtmlSafe(string $value): string
    {
        return strip_tags($value, '<b><strong><i><em><u><br><p><ul><ol><li>');
    }
}
