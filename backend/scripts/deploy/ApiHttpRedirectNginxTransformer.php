<?php

declare(strict_types=1);

namespace FermatMind\Deploy;

use RuntimeException;

final class ApiHttpRedirectNginxTransformer
{
    /**
     * @return list<array{start: int, end: int, content: string}>
     */
    private static function serverBlocks(string $content): array
    {
        $blocks = [];
        $length = strlen($content);
        $offset = 0;

        while (preg_match('/\bserver\s*\{/A', $content, $match, PREG_OFFSET_CAPTURE, $offset) === 1
            || preg_match('/\bserver\s*\{/', $content, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = (int) $match[0][1];
            $open = strpos($content, '{', $start);

            if ($open === false) {
                throw new RuntimeException('Malformed Nginx server block.');
            }

            $depth = 0;
            $quote = null;
            $comment = false;
            $end = null;

            for ($index = $open; $index < $length; $index++) {
                $character = $content[$index];

                if ($comment) {
                    if ($character === "\n") {
                        $comment = false;
                    }

                    continue;
                }

                if ($quote !== null) {
                    if ($character === '\\') {
                        $index++;
                    } elseif ($character === $quote) {
                        $quote = null;
                    }

                    continue;
                }

                if ($character === '#') {
                    $comment = true;

                    continue;
                }

                if ($character === '"' || $character === "'") {
                    $quote = $character;

                    continue;
                }

                if ($character === '{') {
                    $depth++;
                } elseif ($character === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $end = $index + 1;

                        break;
                    }
                }
            }

            if ($end === null) {
                throw new RuntimeException('Unclosed Nginx server block.');
            }

            $blocks[] = [
                'start' => $start,
                'end' => $end,
                'content' => substr($content, $start, $end - $start),
            ];
            $offset = $end;
        }

        return $blocks;
    }

    private static function isHttpServer(string $block): bool
    {
        $count = preg_match_all('/^\s*listen\s+([^;]+);/m', $block, $matches);

        if ($count === false || $count < 1) {
            return false;
        }

        $hasPort80 = false;

        foreach ($matches[1] as $listen) {
            $tokens = preg_split('/\s+/', trim((string) $listen));
            $address = (string) ($tokens[0] ?? '');

            if (in_array('ssl', $tokens ?: [], true) || preg_match('/(?:^|:)443$/', $address) === 1) {
                return false;
            }

            if ($address === '80' || str_ends_with($address, ':80')) {
                $hasPort80 = true;
            }
        }

        return $hasPort80;
    }

    /**
     * @return list<string>
     */
    private static function serverNames(string $block): array
    {
        $count = preg_match_all('/^\s*server_name\s+([^;]+);/m', $block, $matches);

        if ($count === false || $count !== 1) {
            throw new RuntimeException('Each candidate Nginx server block must contain exactly one server_name directive.');
        }

        $names = preg_split('/\s+/', trim((string) $matches[1][0]));

        return array_values(array_filter($names ?: [], static fn (string $name): bool => $name !== ''));
    }

    private static function managedBlock(string $host, string $webroot): string
    {
        return <<<NGINX
server {
    listen 80;
    listen [::]:80;

    server_name {$host};

    location ^~ /.well-known/acme-challenge/ {
        root {$webroot};
        try_files \$uri =404;
    }

    location / {
        return 308 https://{$host}\$request_uri;
    }
}
NGINX;
    }

    public static function transform(string $content, string $host, string $webroot): string
    {
        if (preg_match('/\A[a-z0-9.-]+\z/', $host) !== 1 || preg_match('/\A\/[A-Za-z0-9._\/-]+\z/', $webroot) !== 1) {
            throw new RuntimeException('Unsafe API hostname or Certbot webroot.');
        }

        $matches = [];

        foreach (self::serverBlocks($content) as $block) {
            if (! self::isHttpServer($block['content'])) {
                continue;
            }

            $names = self::serverNames($block['content']);

            if (in_array($host, $names, true)) {
                $matches[] = $block + ['names' => $names];
            }
        }

        if (count($matches) !== 1) {
            throw new RuntimeException(sprintf('Expected exactly one HTTP vhost for %s; found %d.', $host, count($matches)));
        }

        $target = $matches[0];
        $remainingNames = array_values(array_filter(
            $target['names'],
            static fn (string $name): bool => $name !== $host,
        ));
        $managed = self::managedBlock($host, rtrim($webroot, '/'));

        if ($remainingNames === []) {
            $replacement = $managed;
        } else {
            $shared = preg_replace(
                '/(^\s*server_name\s+)[^;]+(;)/m',
                '$1'.implode(' ', $remainingNames).'$2',
                $target['content'],
                1,
                $replaceCount,
            );

            if (! is_string($shared) || $replaceCount !== 1) {
                throw new RuntimeException('Unable to split the shared API HTTP vhost.');
            }

            $replacement = $shared."\n\n".$managed;
        }

        return substr($content, 0, $target['start'])
            .$replacement
            .substr($content, $target['end']);
    }
}
