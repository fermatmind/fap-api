<?php

namespace App\Services\Content\Publisher;

use App\Support\Http\ResilientClient;
use Illuminate\Support\Facades\Log;

class ContentProbeService
{
    public function probe(?string $baseUrl, string $region, string $locale, string $expectedPackId = ''): array
    {
        $probes = [
            'health' => false,
            'questions' => false,
            'content_packs' => false,
        ];

        $baseUrl = $this->normalizeBaseUrl($baseUrl);
        if ($baseUrl === '') {
            return [
                'ok' => false,
                'probes' => $probes,
                'message' => 'missing_base_url',
            ];
        }

        $errors = [];

        $health = $this->fetchJson($baseUrl . '/api/healthz');
        if ($health['ok'] ?? false) {
            $probes['health'] = (bool) (($health['json']['ok'] ?? false) === true);
        }
        if (!$probes['health']) {
            $errors[] = 'health_failed';
        }

        $questionsUrl = $baseUrl . '/api/v0.3/scales/MBTI/questions?region=' . urlencode($region) . '&locale=' . urlencode($locale);
        $questions = $this->fetchJson($questionsUrl);
        if ($questions['ok'] ?? false) {
            $probes['questions'] = (bool) (($questions['json']['ok'] ?? false) === true);
        }
        if (!$probes['questions']) {
            $errors[] = 'questions_failed';
        }

        $packs = $this->fetchJson($baseUrl . '/api/v0.3/scales/lookup?slug=mbti');
        if ($packs['ok'] ?? false) {
            $ok = (bool) (($packs['json']['ok'] ?? false) === true);
            $defaultPackId = (string) (($packs['json']['pack_id'] ?? ''));
            $hasPackId = $defaultPackId !== '';
            if ($expectedPackId !== '') {
                $hasPackId = $hasPackId && $defaultPackId === $expectedPackId;
            }
            $probes['content_packs'] = $ok && $hasPackId;
        }
        if (!$probes['content_packs']) {
            $errors[] = 'content_packs_failed';
        }

        return [
            'ok' => empty($errors),
            'probes' => $probes,
            'message' => empty($errors) ? '' : implode(';', $errors),
        ];
    }

    private function fetchJson(string $url): array
    {
        $local = $this->tryLocalRequest($url);
        if ($local !== null) {
            return $local;
        }

        try {
            $resp = ResilientClient::get($url);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => 'HTTP_REQUEST_FAILED',
                'message' => $e->getMessage(),
            ];
        }

        $status = $resp->status();
        $json = null;
        try {
            $json = $resp->json();
        } catch (\Throwable $e) {
            Log::warning('CONTENT_PROBE_JSON_PARSE_FAILED', [
                'url' => $url,
                'status' => $status,
                'exception' => $e,
            ]);
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'json' => $json,
        ];
    }

    private function tryLocalRequest(string $url): ?array
    {
        if (!str_starts_with($url, 'http://localhost') && !str_starts_with($url, 'http://127.0.0.1')) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $host = $parts['host'] ?? '';
        $port = $parts['port'] ?? 80;
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? ('?' . $parts['query']) : '');
        if ($host === '') {
            return null;
        }

        $fp = @fsockopen($host, (int) $port, $errno, $errstr, 3);
        if (!$fp) {
            return [
                'ok' => false,
                'error' => 'LOCAL_CONNECT_FAILED',
                'message' => $errstr,
            ];
        }

        $req = "GET {$path} HTTP/1.1\r\nHost: {$host}\r\nConnection: Close\r\n\r\n";
        fwrite($fp, $req);
        $resp = stream_get_contents($fp);
        fclose($fp);

        if (!is_string($resp) || $resp === '') {
            return [
                'ok' => false,
                'error' => 'LOCAL_EMPTY_RESPONSE',
            ];
        }

        $parts = explode("\r\n\r\n", $resp, 2);
        $header = $parts[0] ?? '';
        $body = $parts[1] ?? '';

        $status = 0;
        if (preg_match('/HTTP\\/\\d+\\.\\d+\\s+(\\d+)/', $header, $m)) {
            $status = (int) $m[1];
        }

        $json = null;
        if ($body !== '') {
            $json = json_decode($body, true);
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'json' => $json,
        ];
    }

    private function normalizeBaseUrl(?string $baseUrl): string
    {
        $baseUrl = trim((string) $baseUrl);
        if ($baseUrl === '') {
            return '';
        }

        return rtrim($baseUrl, '/');
    }
}
