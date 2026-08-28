<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\MaterialFingerprint;

use InvalidArgumentException;
use JsonException;
use stdClass;

final class MaterialFingerprintV1
{
    public const SCHEMA_VERSION = 'seo.material_fingerprint.v1';

    /** @var list<string> */
    public const MATERIAL_FIELDS = [
        'family',
        'locale',
        'public_identity',
        'authority_revision_kind',
        'visible_content',
        'claims_and_sources',
        'search_surface',
        'locale_linkage',
        'public_structure',
    ];

    /** @var list<string> */
    private const ACCEPTED_INPUT_FIELDS = [
        'schema_version',
        ...self::MATERIAL_FIELDS,
        'non_material_context',
    ];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function canonicalPayload(array $input): array
    {
        $unknownFields = array_values(array_diff(array_keys($input), self::ACCEPTED_INPUT_FIELDS));
        if ($unknownFields !== []) {
            throw new InvalidArgumentException('Unknown material fingerprint fields: '.implode(', ', $unknownFields));
        }

        if (array_key_exists('schema_version', $input)
            && $input['schema_version'] !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('Unsupported material fingerprint schema version.');
        }

        foreach (self::MATERIAL_FIELDS as $field) {
            if (! array_key_exists($field, $input)) {
                throw new InvalidArgumentException("Missing material fingerprint field: {$field}");
            }
        }

        foreach (['family', 'locale', 'public_identity', 'authority_revision_kind'] as $identityField) {
            if (! is_string($input[$identityField]) || trim($input[$identityField]) === '') {
                throw new InvalidArgumentException("Material fingerprint field must be a non-empty string: {$identityField}");
            }
        }

        $payload = ['schema_version' => self::SCHEMA_VERSION];
        foreach (self::MATERIAL_FIELDS as $field) {
            $payload[$field] = $input[$field];
        }

        /** @var array<string, mixed> $normalized */
        $normalized = $this->normalize($payload);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws JsonException
     */
    public function canonicalJson(array $input): string
    {
        return json_encode(
            $this->canonicalPayload($input),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws JsonException
     */
    public function fingerprint(array $input): string
    {
        return hash('sha256', $this->canonicalJson($input));
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);

            $normalized = new stdClass;
            foreach ($properties as $key => $item) {
                $normalized->{$key} = $this->normalize($item);
            }

            return $normalized;
        }

        if (is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException('Material fingerprint values must be JSON-compatible.');
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
