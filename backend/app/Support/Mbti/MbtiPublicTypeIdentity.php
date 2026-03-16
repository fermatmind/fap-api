<?php

declare(strict_types=1);

namespace App\Support\Mbti;

use InvalidArgumentException;

final readonly class MbtiPublicTypeIdentity
{
    private const TYPE_CODE_PATTERN = '/^(?<base>[EI][SN][TF][JP])-(?<variant>[AT])$/';

    public function __construct(
        public string $typeCode,
        public string $baseTypeCode,
        public string $variant,
    ) {}

    public static function fromTypeCode(string $typeCode): self
    {
        $normalized = strtoupper(trim($typeCode));
        if ($normalized === '') {
            throw new InvalidArgumentException('MBTI public type_code is required.');
        }

        if (preg_match(self::TYPE_CODE_PATTERN, $normalized, $matches) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'MBTI public type_code must be a 5-character runtime identity like ENFJ-T, got [%s].',
                $typeCode,
            ));
        }

        $baseTypeCode = (string) $matches['base'];
        $variant = (string) $matches['variant'];

        return new self($normalized, $baseTypeCode, $variant);
    }

    public static function tryFromTypeCode(?string $typeCode): ?self
    {
        $normalized = strtoupper(trim((string) $typeCode));

        if ($normalized === '') {
            return null;
        }

        return self::fromTypeCode($normalized);
    }

    /**
     * @return array{type_code:string,base_type_code:string,variant:string}
     */
    public function toArray(): array
    {
        return [
            'type_code' => $this->typeCode,
            'base_type_code' => $this->baseTypeCode,
            'variant' => $this->variant,
        ];
    }

    public function equals(self $other): bool
    {
        return $this->typeCode === $other->typeCode;
    }
}
