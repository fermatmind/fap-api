<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SemanticJsonAssertionTest extends TestCase
{
    public function test_object_key_order_is_the_only_ignored_difference(): void
    {
        self::assertJsonValueSame(
            ['rows' => [['id' => 7, 'locale' => 'en']], 'ok' => true],
            ['ok' => true, 'rows' => [['locale' => 'en', 'id' => 7]]],
        );
    }

    #[DataProvider('nonEquivalentValues')]
    public function test_list_order_value_types_and_digest_bytes_remain_strict(mixed $expected, mixed $actual): void
    {
        $this->expectException(AssertionFailedError::class);
        self::assertJsonValueSame($expected, $actual);
    }

    public static function nonEquivalentValues(): array
    {
        return [
            'list order' => [['rows' => [1, 2]], ['rows' => [2, 1]]],
            'integer string' => [['id' => 7], ['id' => '7']],
            'boolean integer' => [['ok' => true], ['ok' => 1]],
            'digest bytes' => [['hash' => str_repeat('a', 64)], ['hash' => str_repeat('b', 64)]],
        ];
    }
}
