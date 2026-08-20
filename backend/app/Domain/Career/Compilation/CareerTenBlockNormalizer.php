<?php

declare(strict_types=1);

namespace App\Domain\Career\Compilation;

final class CareerTenBlockNormalizer
{
    /** @param array<string,array<string,mixed>> $blocks @return array<string,mixed> */
    public function normalize(array $blocks, string $profile): array
    {
        $ir = [];
        foreach ($blocks as $file => $value) {
            $ir[$file] = $this->withProvenance($value, $file, '$', $profile);
        }
        ksort($ir, SORT_STRING);

        return [
            'contract_version' => 'career.ten_block.ir.v1',
            'input_profile' => $profile,
            'files' => $ir,
        ];
    }

    private function withProvenance(mixed $value, string $file, string $path, string $profile): mixed
    {
        if (! is_array($value)) {
            return [
                'value' => $value,
                'source' => ['file' => $file, 'json_path' => $path, 'input_profile' => $profile],
            ];
        }
        $normalized = [];
        foreach ($value as $key => $child) {
            $childPath = array_is_list($value) ? $path.'['.$key.']' : $path.'.'.$key;
            $normalized[$key] = $this->withProvenance($child, $file, $childPath, $profile);
        }
        if (! array_is_list($normalized)) {
            ksort($normalized, SORT_STRING);
        }

        return $normalized;
    }
}
