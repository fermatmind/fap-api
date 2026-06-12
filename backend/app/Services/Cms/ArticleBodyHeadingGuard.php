<?php

declare(strict_types=1);

namespace App\Services\Cms;

use InvalidArgumentException;

final class ArticleBodyHeadingGuard
{
    public function assertNoBodyH1(?string $contentMd = null, ?string $contentHtml = null): void
    {
        $violations = $this->violations($contentMd, $contentHtml);
        if ($violations === []) {
            return;
        }

        throw new InvalidArgumentException(
            'Article body must not contain h1 headings; use h2 or lower in CMS body content. Violations: '.implode(', ', $violations).'.'
        );
    }

    /**
     * @return list<string>
     */
    public function violations(?string $contentMd = null, ?string $contentHtml = null): array
    {
        $violations = [];

        if ($this->containsMarkdownH1((string) ($contentMd ?? ''))) {
            $violations[] = 'content_md';
        }

        if ($this->containsHtmlH1((string) ($contentHtml ?? ''))) {
            $violations[] = 'content_html';
        }

        return $violations;
    }

    public function containsMarkdownH1(string $markdown): bool
    {
        return preg_match('/^(?!\s{4,})\s{0,3}#(?!#)(?:\s+|$)/m', $markdown) === 1
            || preg_match('/^(?!\s{4,}).+\R\s{0,3}=+\s*$/m', $markdown) === 1;
    }

    public function containsHtmlH1(string $html): bool
    {
        return preg_match('/<\s*h1(?:\s|>|\/)/i', $html) === 1
            || preg_match('/<\s*\/\s*h1\s*>/i', $html) === 1;
    }

    public function downgradeMarkdownH1ToH2(string $markdown): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        if ($lines === []) {
            return $markdown;
        }

        $output = [];
        $inFence = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s{0,3}(```|~~~)/', $line) === 1) {
                $inFence = ! $inFence;
                $output[] = $line;

                continue;
            }

            if (! $inFence) {
                if (preg_match('/^(?<indent>\s{0,3})#(?!#)(?<rest>\s+.*|)$/', $line, $matches) === 1) {
                    $line = (string) $matches['indent'].'##'.(string) $matches['rest'];
                } elseif (preg_match('/^\s{0,3}=+\s*$/', $line) === 1) {
                    $line = preg_replace('/=/', '-', $line) ?? $line;
                }
            }

            $output[] = $line;
        }

        return implode("\n", $output);
    }

    public function downgradeHtmlH1ToH2(string $html): string
    {
        return preg_replace('/(<\s*\/?\s*)h1(\b[^>]*>)/i', '$1h2$2', $html) ?? $html;
    }
}
