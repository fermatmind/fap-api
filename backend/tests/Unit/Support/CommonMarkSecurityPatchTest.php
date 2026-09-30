<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommonMarkSecurityPatchTest extends TestCase
{
    public static function disallowedTags(): array
    {
        return array_map(fn (string $tag): array => [$tag], [
            'script', 'iframe', 'style', 'textarea', 'xmp', 'noembed', 'noframes', 'plaintext', 'title',
        ]);
    }

    #[DataProvider('disallowedTags')]
    public function test_laravel_markdown_escapes_disallowed_tag_at_end_of_raw_html_block(string $tag): void
    {
        $html = Str::markdown("<div>\n<{$tag}\n\n<span src=\"/security-regression-fixture.js\">\n");

        $this->assertDoesNotMatchRegularExpression('/<'.preg_quote($tag, '/').'(?:\s|>|$)/i', $html);
        $this->assertStringContainsString('&lt;'.$tag, $html);
    }

    public function test_gfm_tables_preserve_headers_and_content(): void
    {
        $html = Str::markdown("| Dimension | Interest |\n| --- | --- |\n| R | Realistic |\n");

        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<th>Dimension</th>', $html);
        $this->assertStringContainsString('<td>Realistic</td>', $html);
    }

    public function test_numeric_multiline_paragraph_is_not_misclassified_as_table(): void
    {
        $html = Str::markdown(str_repeat("12345678\n", 5000));

        $this->assertStringNotContainsString('<table>', $html);
        $this->assertSame(5000, substr_count($html, '12345678'));
    }
}
