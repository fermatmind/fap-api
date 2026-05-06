<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Xlsx\XlsxCellReference;
use Tests\TestCase;

final class XlsxCellReferenceTest extends TestCase
{
    public function test_accepts_excel_max_cell_reference_without_expanding_unbounded_arrays(): void
    {
        $this->assertSame(0, XlsxCellReference::columnIndex('A1'));
        $this->assertSame(16383, XlsxCellReference::columnIndex('XFD1048576'));
        $this->assertSame(1048576, XlsxCellReference::rowNumber('XFD1048576'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCellReferenceProvider')]
    public function test_rejects_malicious_or_out_of_range_cell_references(string $cellRef): void
    {
        $this->expectException(\RuntimeException::class);

        XlsxCellReference::columnIndex($cellRef);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function invalidCellReferenceProvider(): array
    {
        return [
            'missing row' => ['AAAA'],
            'zero row' => ['A0'],
            'too many column letters' => ['AAAA1'],
            'column past excel max' => ['XFE1'],
            'row past excel max' => ['A1048577'],
            'formula-like payload' => ['A1:ZZ999999999'],
            'traversal marker' => ['../A1'],
        ];
    }
}
