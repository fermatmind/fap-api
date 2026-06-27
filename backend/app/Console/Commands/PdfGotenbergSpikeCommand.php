<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Report\Pdf\GotenbergChromiumPdfClient;
use Illuminate\Console\Command;
use Throwable;

final class PdfGotenbergSpikeCommand extends Command
{
    protected $signature = 'pdf:gotenberg-spike
        {--html-file= : Local HTML file to convert through Gotenberg Chromium}
        {--print-url= : Internal/private result print URL to convert through Gotenberg Chromium}
        {--output= : Output PDF path}
        {--json : Emit sanitized JSON evidence}';

    protected $description = 'Validate the disabled-by-default Gotenberg Chromium PDF engine without changing public report routes.';

    public function __construct(
        private readonly GotenbergChromiumPdfClient $client,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $output = trim((string) $this->option('output'));
        $htmlFile = trim((string) $this->option('html-file'));
        $printUrl = trim((string) $this->option('print-url'));

        if ($output === '' || ($htmlFile === '' && $printUrl === '') || ($htmlFile !== '' && $printUrl !== '')) {
            $this->error('Provide exactly one of --html-file or --print-url, plus --output.');

            return self::FAILURE;
        }

        try {
            $pdf = $htmlFile !== ''
                ? $this->client->convertHtml($this->readHtmlFile($htmlFile))
                : $this->client->convertUrl($printUrl);

            $dir = dirname($output);
            if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
                throw new \RuntimeException('Unable to create output directory.');
            }

            file_put_contents($output, $pdf);

            $payload = [
                'ok' => true,
                'engine' => 'gotenberg_chromium',
                'mode' => $htmlFile !== '' ? 'html' : 'url',
                'bytes' => strlen($pdf),
                'pdf_magic' => str_starts_with($pdf, '%PDF-'),
                'output' => $output,
                'api_route_changed' => false,
                'public_exposure_allowed' => false,
            ];
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'engine' => 'gotenberg_chromium',
                'error' => class_basename($e),
                'message' => $e->getMessage(),
                'api_route_changed' => false,
                'public_exposure_allowed' => false,
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
        } else {
            foreach ($payload as $key => $value) {
                $this->line($key.'='.json_encode($value, JSON_UNESCAPED_SLASHES));
            }
        }

        return ($payload['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    private function readHtmlFile(string $path): string
    {
        if (! is_file($path)) {
            throw new \InvalidArgumentException('HTML file does not exist.');
        }

        $html = file_get_contents($path);
        if (! is_string($html) || trim($html) === '') {
            throw new \InvalidArgumentException('HTML file is empty.');
        }

        return $html;
    }
}
