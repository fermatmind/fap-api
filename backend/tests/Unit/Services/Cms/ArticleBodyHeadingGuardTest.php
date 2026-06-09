<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cms;

use App\Services\Cms\ArticleBodyHeadingGuard;
use PHPUnit\Framework\TestCase;

final class ArticleBodyHeadingGuardTest extends TestCase
{
    public function test_detects_markdown_and_html_body_h1(): void
    {
        $guard = new ArticleBodyHeadingGuard;

        $this->assertSame(['content_md'], $guard->violations("# Duplicate title\n\n## Section"));
        $this->assertSame(['content_html'], $guard->violations('## Section', '<article><h1>Duplicate title</h1></article>'));
        $this->assertSame([], $guard->violations("## Section\n\nBody", '<article><h2>Section</h2></article>'));
    }

    public function test_downgrades_legacy_body_h1_without_touching_fenced_markdown(): void
    {
        $guard = new ArticleBodyHeadingGuard;

        $markdown = "# Duplicate title\n\n```md\n# Example stays literal\n```\n\n## Existing section";

        $this->assertSame(
            "## Duplicate title\n\n```md\n# Example stays literal\n```\n\n## Existing section",
            $guard->downgradeMarkdownH1ToH2($markdown)
        );
        $this->assertSame(
            '<article><h2 class="hero">Duplicate title</h2><h2>Section</h2></article>',
            $guard->downgradeHtmlH1ToH2('<article><h1 class="hero">Duplicate title</h1><h2>Section</h2></article>')
        );
    }
}
