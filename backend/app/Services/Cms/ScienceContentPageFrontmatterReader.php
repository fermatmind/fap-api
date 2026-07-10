<?php

declare(strict_types=1);

namespace App\Services\Cms;

final class ScienceContentPageFrontmatterReader
{
    public function resolvePackagePagePath(string $root, string $relativePath): ?string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath));
        if ($relativePath === ''
            || str_contains($relativePath, "\0")
            || str_starts_with($relativePath, '/')
            || preg_match('/\A[A-Za-z]:\//', $relativePath) === 1) {
            return null;
        }

        $segments = explode('/', $relativePath);
        if (($segments[0] ?? '') !== 'pages'
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
            || strtolower((string) pathinfo($relativePath, PATHINFO_EXTENSION)) !== 'md') {
            return null;
        }

        $canonicalRoot = realpath($root);
        $canonicalPath = realpath($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        if (! is_string($canonicalRoot)
            || ! is_string($canonicalPath)
            || ! is_file($canonicalPath)
            || ! is_readable($canonicalPath)
            || ! str_starts_with($canonicalPath, $canonicalRoot.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $canonicalPath;
    }

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
        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            $inline = trim(substr($value, 1, -1));
            if ($inline === '') {
                return [];
            }

            return array_values(array_map(
                fn (string $item): mixed => $this->scalar($item),
                str_getcsv($inline, ',', '"', '\\'),
            ));
        }

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
