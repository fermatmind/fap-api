<?php

declare(strict_types=1);

namespace App\Services\Template;

final class TemplateLintService
{
    public function __construct(private readonly TemplateEngine $engine)
    {
    }

    /**
     * @return list<array{block_id:string,unknown:list<string>,missing:list<string>}>
     */
    public function lintTemplateString(string $template, string $blockId, ?TemplateContext $context = null): array
    {
        $lint = $this->engine->lintString($template, $context);
        if (($lint['unknown'] ?? []) === [] && ($lint['missing'] ?? []) === []) {
            return [];
        }

        return [[
            'block_id' => $blockId,
            'unknown' => is_array($lint['unknown'] ?? null) ? $lint['unknown'] : [],
            'missing' => is_array($lint['missing'] ?? null) ? $lint['missing'] : [],
        ]];
    }

    /**
     * @return list<array{block_id:string,unknown:list<string>,missing:list<string>}>
     */
    public function lintPayloadTemplates(mixed $payload, string $blockIdPrefix = 'payload', ?TemplateContext $context = null): array
    {
        $out = [];

        $walk = function (mixed $node, string $path) use (&$walk, &$out, $context): void {
            if (is_string($node) && str_contains($node, '{{')) {
                $lint = $this->engine->lintString($node, $context);
                $unknown = is_array($lint['unknown'] ?? null) ? $lint['unknown'] : [];
                $missing = is_array($lint['missing'] ?? null) ? $lint['missing'] : [];
                if ($unknown !== [] || $missing !== []) {
                    $out[] = [
                        'block_id' => $path,
                        'unknown' => array_values(array_unique($unknown)),
                        'missing' => array_values(array_unique($missing)),
                    ];
                }
                return;
            }

            if (!is_array($node)) {
                return;
            }

            foreach ($node as $key => $value) {
                $nextPath = $path . '.' . (is_int($key) ? (string) $key : $key);
                $walk($value, $nextPath);
            }
        };

        $walk($payload, $blockIdPrefix);

        return $out;
    }

    /**
     * @return list<string>
     */
    public function extractVariablesFromPayload(mixed $payload): array
    {
        $vars = [];

        $walk = function (mixed $node) use (&$walk, &$vars): void {
            if (is_string($node) && str_contains($node, '{{')) {
                foreach ($this->engine->extractVariables($node) as $varName) {
                    $vars[$varName] = true;
                }
                return;
            }

            if (!is_array($node)) {
                return;
            }

            foreach ($node as $value) {
                $walk($value);
            }
        };

        $walk($payload);

        return array_keys($vars);
    }
}
