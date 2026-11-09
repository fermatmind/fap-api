<?php

declare(strict_types=1);

namespace App\Services\Cms;

final class StructuredContentPagePolicy
{
    public const TYPES = ['methodology' => 'methodology', 'source-review-policy' => 'trust'];

    public static function headingKeys(mixed $headings): ?array
    {
        if (! is_array($headings) || ! array_is_list($headings) || $headings === []) {
            return null;
        }
        $keys = [];
        foreach ($headings as $heading) {
            if (! is_array($heading) || count($heading) !== 2 || ! is_string($heading['key'] ?? null)
                || ! preg_match('/^[a-z][a-z0-9_]*$/D', $heading['key'])
                || ! is_string($heading['title'] ?? null) || trim($heading['title']) === ''
                || isset($keys[$heading['key']])) {
                return null;
            }
            $keys[$heading['key']] = true;
        }

        return array_keys($keys);
    }
}
