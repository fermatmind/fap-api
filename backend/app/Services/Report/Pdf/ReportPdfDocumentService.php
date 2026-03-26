<?php

declare(strict_types=1);

namespace App\Services\Report\Pdf;

use App\Models\Attempt;
use App\Models\Result;

final class ReportPdfDocumentService
{
    public function __construct(
        private readonly ReportPdfArtifactStore $artifactStore,
    ) {}

    public function normalizeVariant(string $variant): string
    {
        $variant = strtolower(trim($variant));

        return in_array($variant, ['free', 'full'], true) ? $variant : 'free';
    }

    public function fileName(string $scaleCode, string $attemptId): string
    {
        $scaleSlug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $scaleCode));

        return trim($scaleSlug, '_').'_report_'.$attemptId.'.pdf';
    }

    public function resolveArtifactPath(Attempt $attempt, string $variant, ?Result $result = null): string
    {
        return $this->artifactStore->path(
            (string) ($attempt->scale_code ?? 'UNKNOWN'),
            (string) $attempt->id,
            $this->resolveManifestHash($attempt, $result),
            $this->normalizeVariant($variant)
        );
    }

    public function readArtifact(Attempt $attempt, string $variant, ?Result $result = null): ?string
    {
        $variant = $this->normalizeVariant($variant);
        $manifestHash = $this->resolveManifestHash($attempt, $result);
        $path = $this->artifactStore->path(
            (string) ($attempt->scale_code ?? 'UNKNOWN'),
            (string) $attempt->id,
            $manifestHash,
            $variant
        );
        $candidates = array_merge([
            $path,
        ], $this->artifactStore->legacyPaths(
            (string) ($attempt->scale_code ?? 'UNKNOWN'),
            (string) $attempt->id,
            $manifestHash,
            $variant
        ));
        $cached = $this->artifactStore->getFirst($candidates);
        if (is_string($cached) && $cached !== '') {
            if (! $this->legacyDrainEnabled() && ! $this->artifactStore->exists($path)) {
                $this->artifactStore->put($path, $cached);
            }

            return $cached;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $gate
     * @return array{binary:string,storage_path:string,variant:string,locked:bool,manifest_hash:string,cached:bool}
     */
    public function getOrGenerate(Attempt $attempt, array $gate, ?Result $result = null): array
    {
        $variant = $this->normalizeVariant((string) ($gate['variant'] ?? 'free'));
        $locked = (bool) ($gate['locked'] ?? true);
        $manifestHash = $this->resolveManifestHash($attempt, $result);
        $path = $this->artifactStore->path(
            (string) ($attempt->scale_code ?? 'UNKNOWN'),
            (string) $attempt->id,
            $manifestHash,
            $variant
        );
        $candidates = array_merge([
            $path,
        ], $this->artifactStore->legacyPaths(
            (string) ($attempt->scale_code ?? 'UNKNOWN'),
            (string) $attempt->id,
            $manifestHash,
            $variant
        ));
        $cached = $this->artifactStore->getFirst($candidates);
        if (is_string($cached) && $cached !== '') {
            if (! $this->legacyDrainEnabled() && ! $this->artifactStore->exists($path)) {
                $this->artifactStore->put($path, $cached);
            }

            return [
                'binary' => $cached,
                'storage_path' => $path,
                'variant' => $variant,
                'locked' => $locked,
                'manifest_hash' => $manifestHash,
                'cached' => true,
            ];
        }

        $report = is_array($gate['report'] ?? null) ? $gate['report'] : [];
        $sections = array_map(
            'strval',
            array_values(
                array_filter(
                    array_column((array) ($report['sections'] ?? []), 'key'),
                    static fn ($value): bool => is_string($value) && trim($value) !== ''
                )
            )
        );
        $normsStatus = strtoupper(trim((string) (
            data_get($gate, 'norms.status')
            ?? data_get($result?->result_json, 'normed_json.norms.status', '')
        )));
        $qualityLevel = strtoupper(trim((string) (
            data_get($gate, 'quality.level')
            ?? data_get($result?->result_json, 'quality.level')
            ?? data_get($result?->result_json, 'normed_json.quality.level', '')
        )));

        $pdfBinary = $this->buildDocument(
            (string) $attempt->id,
            (string) ($attempt->scale_code ?? ''),
            $locked,
            $variant,
            $normsStatus,
            $qualityLevel,
            $sections
        );
        $this->artifactStore->put($path, $pdfBinary);

        return [
            'binary' => $pdfBinary,
            'storage_path' => $path,
            'variant' => $variant,
            'locked' => $locked,
            'manifest_hash' => $manifestHash,
            'cached' => false,
        ];
    }

    public function resolveManifestHash(Attempt $attempt, ?Result $result = null): string
    {
        $summary = is_array($attempt->answers_summary_json ?? null) ? $attempt->answers_summary_json : [];
        $meta = is_array($summary['meta'] ?? null) ? $summary['meta'] : [];
        $hash = trim((string) ($meta['pack_release_manifest_hash'] ?? ''));
        if ($hash !== '') {
            return $hash;
        }

        $resultPayload = is_array($result?->result_json ?? null) ? $result?->result_json : [];
        $hash = trim((string) (
            data_get($resultPayload, 'version_snapshot.content_manifest_hash')
            ?? data_get($resultPayload, 'normed_json.version_snapshot.content_manifest_hash')
            ?? data_get($resultPayload, 'content_manifest_hash')
            ?? ''
        ));

        return $hash !== '' ? $hash : 'nohash';
    }

    /**
     * @param  list<string>  $sections
     */
    private function buildDocument(
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

    private function legacyDrainEnabled(): bool
    {
        return (bool) config('storage_rollout.legacy_drain_enabled', false);
    }
}
