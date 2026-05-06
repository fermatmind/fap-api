<?php

declare(strict_types=1);

namespace App\Support\Xlsx;

final class XlsxCellReference
{
    public const MAX_COLUMN_INDEX = 16383;

    public const MAX_ROW_NUMBER = 1048576;

    public static function columnIndex(string $cellRef): int
    {
        [$column] = self::parse($cellRef);

        return $column;
    }

    public static function rowNumber(string $cellRef): int
    {
        [, $row] = self::parse($cellRef);

        return $row;
    }

    /**
     * @return array{0:int,1:int}
     */
    private static function parse(string $cellRef): array
    {
        $normalized = strtoupper(trim($cellRef));
        if (preg_match('/^\$?([A-Z]{1,3})\$?([1-9][0-9]{0,6})$/', $normalized, $matches) !== 1) {
            throw new \RuntimeException('Invalid XLSX cell reference: '.$cellRef);
        }

        $index = 0;
        foreach (str_split($matches[1]) as $char) {
            $index = ($index * 26) + (ord($char) - ord('A') + 1);
        }
        $index--;
        if ($index < 0 || $index > self::MAX_COLUMN_INDEX) {
            throw new \RuntimeException('XLSX cell reference exceeds max column: '.$cellRef);
        }

        $row = (int) $matches[2];
        if ($row < 1 || $row > self::MAX_ROW_NUMBER) {
            throw new \RuntimeException('XLSX cell reference exceeds max row: '.$cellRef);
        }

        return [$index, $row];
    }
}
