<?php

declare(strict_types=1);

namespace App\Services\Report\Pdf;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

final class GotenbergChromiumPdfClient
{
    public function enabled(): bool
    {
        return (bool) config('gotenberg.enabled', false);
    }

    public function baseUrl(): string
    {
        return rtrim(trim((string) config('gotenberg.base_url', '')), '/');
    }

    /**
     * @param  array<string,string|int|float|bool|null>  $options
     *
     * @throws RequestException
     */
    public function convertHtml(string $html, array $options = []): string
    {
        $baseUrl = $this->validatedBaseUrl();
        $payloadOptions = $this->normalizeOptions($options);

        $response = Http::connectTimeout((int) config('gotenberg.connect_timeout_seconds', 5))
            ->timeout((int) config('gotenberg.timeout_seconds', 30))
            ->accept('application/pdf')
            ->attach('files', $html, 'index.html', ['Content-Type' => 'text/html; charset=UTF-8'])
            ->post($baseUrl.'/forms/chromium/convert/html', $payloadOptions);

        $response->throw();

        $body = $response->body();
        if (! str_starts_with($body, '%PDF-')) {
            throw new RuntimeException('Gotenberg did not return a PDF document.');
        }

        return $body;
    }

    /**
     * @param  array<string,string|int|float|bool|null>  $options
     *
     * @throws RequestException
     */
    public function convertUrl(string $printUrl, array $options = [], ?string $gotenbergTrace = null): string
    {
        $baseUrl = $this->validatedBaseUrl();
        $payloadOptions = $this->normalizedUrlFormFieldsForDiagnostics($printUrl, $options);

        $request = Http::connectTimeout((int) config('gotenberg.connect_timeout_seconds', 5))
            ->timeout((int) config('gotenberg.timeout_seconds', 60))
            ->accept('application/pdf')
            ->asMultipart();

        $gotenbergTrace = trim((string) $gotenbergTrace);
        if ($gotenbergTrace !== '') {
            $request = $request->withHeaders([
                'Gotenberg-Trace' => $gotenbergTrace,
            ]);
        }

        $response = $request->post($baseUrl.'/forms/chromium/convert/url', $payloadOptions);

        $response->throw();

        $body = $response->body();
        if (! str_starts_with($body, '%PDF-')) {
            throw new RuntimeException('Gotenberg did not return a PDF document.');
        }

        return $body;
    }

    /**
     * @param  array<string,string|int|float|bool|null>  $options
     * @return array<string,string>
     */
    public function normalizedUrlFormFieldsForDiagnostics(string $printUrl, array $options = []): array
    {
        $this->assertPrivateHttpUrl($printUrl, 'print URL');

        return $this->normalizeOptions(['url' => $printUrl, ...$options]);
    }

    public function assertPrivateHttpUrl(string $url, string $label = 'URL'): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? '')));

        if ($scheme !== 'http') {
            throw new InvalidArgumentException($label.' must use http on a private network.');
        }

        if ($host === '' || ! $this->isPrivateHost($host)) {
            throw new InvalidArgumentException($label.' must resolve to a private/internal host.');
        }
    }

    private function validatedBaseUrl(): string
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Gotenberg PDF engine is disabled.');
        }

        $baseUrl = $this->baseUrl();
        $this->assertPrivateHttpUrl($baseUrl, 'Gotenberg base URL');

        return $baseUrl;
    }

    private function isPrivateHost(string $host): bool
    {
        $host = trim($host, '[]');
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        if ((bool) config('gotenberg.allow_single_label_hosts', true) && ! str_contains($host, '.')) {
            return true;
        }

        foreach ((array) config('gotenberg.allowed_private_suffixes', []) as $suffix) {
            $suffix = strtolower(trim((string) $suffix));
            if ($suffix !== '' && str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,string|int|float|bool|null>  $options
     * @return array<string,string>
     */
    private function normalizeOptions(array $options): array
    {
        $merged = [
            ...((array) config('gotenberg.default_pdf_options', [])),
            ...$options,
        ];

        $normalized = [];
        foreach ($merged as $key => $value) {
            if ($value === null) {
                continue;
            }

            $normalized[(string) $key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $normalized;
    }
}
