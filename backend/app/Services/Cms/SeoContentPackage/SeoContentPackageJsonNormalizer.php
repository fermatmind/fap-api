<?php

declare(strict_types=1);

namespace App\Services\Cms\SeoContentPackage;

use JsonException;

final class SeoContentPackageJsonNormalizer
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_THROW_ON_ERROR;

    /**
     * @return array{value:mixed,warnings:list<array{field:string,code:string,message:string}>,errors:list<array{field:string,code:string,message:string}>}
     */
    public function normalizeField(string $field, mixed $value): array
    {
        $warnings = [];
        $errors = [];
        $this->inspect($value, $field, $warnings, $errors);

        if ($errors !== []) {
            return [
                'value' => $value,
                'warnings' => $warnings,
                'errors' => $errors,
            ];
        }

        try {
            $encoded = json_encode($value, self::JSON_FLAGS);
            $normalized = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $errors[] = $this->issue(
                $field,
                'json_serialization_failed',
                'JSON field serialization failed: '.$exception->getMessage()
            );

            return [
                'value' => $value,
                'warnings' => $warnings,
                'errors' => $errors,
            ];
        }

        return [
            'value' => $normalized,
            'warnings' => $warnings,
            'errors' => [],
        ];
    }

    /**
     * @param  list<array{field:string,code:string,message:string}>  $warnings
     * @param  list<array{field:string,code:string,message:string}>  $errors
     */
    private function inspect(mixed $value, string $path, array &$warnings, array &$errors): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $this->inspect($child, $this->childPath($path, $key), $warnings, $errors);
            }

            return;
        }

        if (is_string($value)) {
            if (str_contains($value, "\0")) {
                $errors[] = $this->issue($path, 'json_binary_string_found', 'Binary string content is not allowed in JSON audit fields.');

                return;
            }

            if (! mb_check_encoding($value, 'UTF-8')) {
                $warnings[] = $this->issue($path, 'json_string_utf8_normalized', 'Invalid UTF-8 was normalized for this JSON audit field; content omitted from warning.');
            }

            return;
        }

        if (is_float($value) && (! is_finite($value))) {
            $errors[] = $this->issue($path, 'json_non_serializable_value', 'Non-finite float is not serializable as JSON.');

            return;
        }

        if (is_object($value) || is_resource($value)) {
            $errors[] = $this->issue($path, 'json_non_serializable_value', 'Non-serializable value is not allowed in JSON audit fields.');
        }
    }

    private function childPath(string $path, int|string $key): string
    {
        if (is_int($key)) {
            return $path.'['.$key.']';
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1) {
            return $path.'.'.$key;
        }

        return $path.'['.json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE).']';
    }

    /**
     * @return array{field:string,code:string,message:string}
     */
    private function issue(string $field, string $code, string $message): array
    {
        return [
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ];
    }
}
