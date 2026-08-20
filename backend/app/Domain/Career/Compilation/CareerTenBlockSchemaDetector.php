<?php

declare(strict_types=1);

namespace App\Domain\Career\Compilation;

use JsonException;

final class CareerTenBlockSchemaDetector
{
    public function __construct(
        private readonly CareerTenBlockInputSchema $legacySchema,
        private readonly CareerTenBlockVariantSchema $variantSchema,
    ) {}

    /** @return array{profile:string,schema_version:string,blocks:array<string,array<string,mixed>>,input_digest:string} */
    public function detect(string $sourceRoot, string $slug): array
    {
        $slugRoot = realpath(rtrim($sourceRoot, '/').'/'.$slug);
        if ($slugRoot === false || ! is_dir($slugRoot) || is_link(rtrim($sourceRoot, '/').'/'.$slug)) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_SOURCE_MISSING');
        }
        $actualFiles = array_values(array_filter(
            scandir($slugRoot) ?: [],
            static fn (string $file): bool => ! str_starts_with($file, '.')
                && str_ends_with($file, '.json')
                && $file !== 'content.json',
        ));
        sort($actualFiles, SORT_STRING);
        if ($actualFiles !== CareerTenBlockInputSchema::FILES) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_FILE_SET_MISMATCH');
        }

        $blocks = [];
        $digestContext = hash_init('sha256');
        foreach (CareerTenBlockInputSchema::FILES as $file) {
            $path = $slugRoot.'/'.$file;
            if (! is_file($path) || is_link($path)) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_FILE_SET_MISMATCH');
            }
            $raw = file_get_contents($path);
            if (! is_string($raw)) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_FILE_UNREADABLE');
            }
            try {
                $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_INVALID_JSON');
            }
            if (! is_array($data) || array_is_list($data)) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_TYPE_MISMATCH');
            }
            $blocks[$file] = $data;
            hash_update($digestContext, $file."\0".$raw."\0");
        }

        $profile = $this->variantSchema->detectAndValidate($blocks);
        if ($profile === CareerTenBlockVariantSchema::PROFILE_STANDARD && ! $this->hasStandardVariant($blocks)) {
            foreach ($blocks as $file => $data) {
                $this->legacySchema->assertFile($file, $data);
            }
        }

        return [
            'profile' => $profile,
            'schema_version' => CareerTenBlockVariantSchema::VERSION,
            'blocks' => $blocks,
            'input_digest' => hash_final($digestContext),
        ];
    }

    /** @param array<string,array<string,mixed>> $blocks */
    private function hasStandardVariant(array $blocks): bool
    {
        foreach ($blocks['definition.json']['onet_struct'] as $item) {
            if (is_array($item) && array_key_exists('value2', $item)) {
                return true;
            }
        }

        return ($blocks['page-meta.json']['oc_salary_min'] ?? null) === null
            || ($blocks['page-meta.json']['oc_salary_max'] ?? null) === null
            || count($blocks['identity.json']) !== 8
            || count($blocks['salary.json']) !== 14;
    }
}
