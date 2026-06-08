<?php

declare(strict_types=1);

namespace App\Services\Cms;

final class ScienceContentPageFrontmatterReader
{
    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    public function read(string $path): array
    {
        $content = (string) file_get_contents($path);
        if (! preg_match('/\A---\R(?P<yaml>.*?)\R---\R(?P<body>.*)\z/s', $content, $matches)) {
            throw new \RuntimeException('Page file is missing YAML frontmatter: '.$path);
        }

        return [$this->parse((string) $matches['yaml']), (string) $matches['body']];
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $yaml): array
    {
        $frontmatter = [];
        $currentListKey = null;

        foreach (preg_split('/\R/', $yaml) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            if (preg_match('/^(?<key>[A-Za-z0-9_]+):(?:\s*(?<value>.*))?$/', $line, $matches) === 1) {
                $key = (string) $matches['key'];
                $value = trim((string) ($matches['value'] ?? ''));

                if ($value === '') {
                    $frontmatter[$key] = [];
                    $currentListKey = $key;

                    continue;
                }

                $frontmatter[$key] = $this->scalar($value);
                $currentListKey = null;

                continue;
            }

            if ($currentListKey !== null && preg_match('/^\s*-\s*(?<value>.*)$/', $line, $matches) === 1) {
                $frontmatter[$currentListKey][] = $this->scalar((string) $matches['value']);
            }
        }

        return $frontmatter;
    }

    private function scalar(string $value): mixed
    {
        $value = trim($value);
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }
        if ($value === 'null' || $value === '~') {
            return null;
        }

        if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
