<?php

declare(strict_types=1);

namespace App\Services\Report\Pdf;

final class BigFiveTaiwanPdfCopy
{
    private const DICTIONARY = 'i18n/big-five-pdf/opencc-twp.json';

    private static ?array $dictionary = null;

    public static function revision(): string
    {
        return hash_file('sha256', resource_path(self::DICTIONARY));
    }

    public function text(string $text): string
    {
        if (! preg_match('/\p{Han}/u', $text)) {
            return $text;
        }

        $dictionary = self::$dictionary ??= $this->loadDictionary();

        if (isset($dictionary['copy'][$text])) {
            return $dictionary['copy'][$text];
        }

        $tokens = [];
        $masked = preg_replace_callback('~https?://[^\s<>）)]+|`[^`]*`|\{\{[^}]*\}\}|\$?\{[^}]*\}|%\d*\$?[sdif]|\d+(?:[.,:/-]\d+)*~u',
            static function (array $match) use (&$tokens): string {
                $key = mb_chr(0xF0000 + count($tokens), 'UTF-8');
                $tokens[$key] = $match[0];

                return $key;
            }, $text);
        $normalized = strtr($masked, $dictionary['normalization']);
        $segments = $this->segments($normalized, $dictionary['segmentation']);
        // A NUL delimiter prevents dictionary matches from spanning OpenCC segments.
        // Use a delimiter absent from valid UTF-8 when the original already contains a NUL.
        $delimiter = str_contains($normalized, "\0") ? "\0\xff\0" : "\0";
        $converted = implode($delimiter, $segments);
        foreach ($dictionary['stages'] as $stage) {
            $converted = strtr($converted, $stage);
        }
        $converted = str_replace($delimiter, '', $converted);

        return strtr(strtr($converted, $dictionary['reviewedTerms']), $tokens);
    }

    private function loadDictionary(): array
    {
        $dictionary = json_decode(file_get_contents(resource_path(self::DICTIONARY)), true, flags: JSON_THROW_ON_ERROR);
        $segmentation = [];
        foreach ($dictionary['segmentation'] as $word) {
            $segmentation[substr($word, 0, 3)][strlen($word)][$word] = true;
        }
        foreach ($segmentation as &$lengths) {
            krsort($lengths, SORT_NUMERIC);
        }
        $dictionary['segmentation'] = $segmentation;

        return $dictionary;
    }

    private function segments(string $text, array $dictionary): array
    {
        preg_match_all('/./us', $text, $characters, PREG_OFFSET_CAPTURE);
        $segments = [];
        $start = 0;
        $next = 0;
        foreach ($characters[0] as [, $offset]) {
            if ($offset < $next) {
                continue;
            }
            foreach ($dictionary[substr($text, $offset, 3)] ?? [] as $length => $words) {
                $word = substr($text, $offset, $length);
                if (isset($words[$word])) {
                    if ($offset > $start) {
                        $segments[] = substr($text, $start, $offset - $start);
                    }
                    $segments[] = $word;
                    $start = $next = $offset + $length;
                    break;
                }
            }
        }
        if ($start < strlen($text)) {
            $segments[] = substr($text, $start);
        }

        return $segments;
    }
}
