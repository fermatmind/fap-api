<?php

declare(strict_types=1);

namespace App\Services\Report;

use Illuminate\Support\Facades\Storage;

final class BigFivePdfDocumentService
{
    private const STORAGE_PREFIX = 'private/reports/big5';

    /**
     * @param  list<string>  $sections
     */
    public function buildDocument(
        string $attemptId,
        string $scaleCode,
        bool $locked,
        string $variant,
        string $normsStatus,
        string $qualityLevel,
        array $sections
    ): string {
        $lines = [
            'Attempt ID: '.$attemptId,
            'Scale: '.strtoupper($scaleCode),
            'Variant: '.strtolower($variant),
            'Locked: '.($locked ? 'true' : 'false'),
        ];

        if ($normsStatus !== '') {
            $lines[] = 'Norms Status: '.strtoupper($normsStatus);
        }
        if ($qualityLevel !== '') {
            $lines[] = 'Quality Level: '.strtoupper($qualityLevel);
        }
        if ($sections !== []) {
            $lines[] = 'Sections: '.implode(', ', $sections);
        }

        return $this->buildSimplePdfDocument('FermatMind Report', $lines);
    }

    public function fileName(string $scaleCode, string $attemptId): string
    {
        $scaleSlug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $scaleCode));

        return trim($scaleSlug, '_').'_report_'.$attemptId.'.pdf';
    }

    public function normalizeVariant(string $variant): string
    {
        $variant = strtolower(trim($variant));

        return in_array($variant, ['free', 'full'], true) ? $variant : 'free';
    }

    public function artifactPath(string $attemptId, string $variant): string
    {
        $attemptId = trim($attemptId);
        $variant = $this->normalizeVariant($variant);

        return self::STORAGE_PREFIX.'/'.$attemptId.'/report_'.$variant.'.pdf';
    }

    public function storeArtifact(string $attemptId, string $variant, string $pdfBinary): string
    {
        $path = $this->artifactPath($attemptId, $variant);
        Storage::disk('local')->put($path, $pdfBinary);

        return $path;
    }

    public function readArtifact(string $attemptId, string $variant): ?string
    {
        $path = $this->artifactPath($attemptId, $variant);
        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            return null;
        }

        $contents = $disk->get($path);

        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    /**
     * @param  list<string>  $lines
     */
    private function buildSimplePdfDocument(string $title, array $lines): string
    {
        $title = $this->sanitizePdfText($title);
        $stream = "BT\n/F1 14 Tf\n1 0 0 1 40 800 Tm\n(".$title.") Tj\n/F1 10 Tf\n";

        $y = 780;
        foreach ($lines as $line) {
            $line = $this->sanitizePdfText($line);
            if ($line === '') {
                continue;
            }
            $stream .= "1 0 0 1 40 {$y} Tm\n(".$line.") Tj\n";
            $y -= 14;
            if ($y < 48) {
                break;
            }
        }
        $stream .= "ET\n";

        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n",
            "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
            "5 0 obj\n<< /Length ".strlen($stream)." >>\nstream\n".$stream."endstream\nendobj\n",
        ];

        $offsets = [0];
        $pdf = "%PDF-1.4\n";
        foreach ($objects as $index => $object) {
            $offsets[$index + 1] = strlen($pdf);
            $pdf .= $object;
        }

        $startXref = strlen($pdf);
        $pdf .= "xref\n0 6\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$i])."\n";
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\n";
        $pdf .= "startxref\n".$startXref."\n%%EOF\n";

        return $pdf;
    }

    private function sanitizePdfText(string $value): string
    {
        $value = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
    }
}
