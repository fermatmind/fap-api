<?php

declare(strict_types=1);

namespace App\Domain\GreenfieldBaseline;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use RuntimeException;

final class GreenfieldBaselineMediaExporter
{
    private const MAX_OBJECT_BYTES = 52_428_800;

    private const MAX_TOTAL_BYTES = 2_147_483_648;

    private const MAX_OBJECT_COUNT = 2_000;

    /** @return array<string, mixed> */
    public function export(
        string $manifestPath,
        string $mediaDirectory,
        bool $download,
        ?string $expectedHostSetSha256,
    ): array {
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $entries = is_array($manifest['entries'] ?? null) ? $manifest['entries'] : [];
        if (count($entries) > self::MAX_OBJECT_COUNT) {
            throw new RuntimeException('Greenfield public media object count exceeds the safety limit.');
        }

        $hosts = [];
        $expectedTotal = 0;
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw new RuntimeException('Greenfield media manifest entry is invalid.');
            }
            $url = trim((string) ($entry['url'] ?? ''));
            $parts = parse_url($url);
            $host = strtolower((string) ($parts['host'] ?? ''));
            if (($parts['scheme'] ?? null) !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
                throw new RuntimeException('Greenfield public media URL must be credential-free HTTPS.');
            }
            $this->assertPublicHostname($host);
            $hosts[$host] = true;
            $bytes = max(0, (int) ($entry['expected_bytes'] ?? 0));
            if ($bytes > self::MAX_OBJECT_BYTES) {
                throw new RuntimeException('Greenfield public media object exceeds the per-object safety limit.');
            }
            $expectedTotal += $bytes;
        }
        if ($expectedTotal > self::MAX_TOTAL_BYTES) {
            throw new RuntimeException('Greenfield public media exceeds the package safety limit.');
        }

        $hostList = array_keys($hosts);
        sort($hostList, SORT_STRING);
        $hostSetSha256 = hash('sha256', implode("\n", $hostList)."\n");
        if ($expectedHostSetSha256 !== null && $expectedHostSetSha256 !== '') {
            if (preg_match('/^[0-9a-f]{64}$/', $expectedHostSetSha256) !== 1
                || ! hash_equals($expectedHostSetSha256, $hostSetSha256)) {
                throw new RuntimeException('Greenfield public media host-set SHA256 mismatch.');
            }
        }

        if (! $download) {
            return [
                'downloaded' => false,
                'entry_count' => count($entries),
                'expected_bytes' => $expectedTotal,
                'host_count' => count($hostList),
                'host_set_sha256' => $hostSetSha256,
            ];
        }
        if ($expectedHostSetSha256 === null || $expectedHostSetSha256 === '') {
            throw new RuntimeException('Greenfield media download requires the preflight host-set SHA256.');
        }

        $objectDirectory = $mediaDirectory.'/objects';
        if (! mkdir($objectDirectory, 0700, true) && ! is_dir($objectDirectory)) {
            throw new RuntimeException('Unable to create Greenfield media object directory.');
        }
        $client = new Client([
            'allow_redirects' => false,
            'connect_timeout' => 10,
            'timeout' => 60,
            'http_errors' => false,
            'headers' => ['User-Agent' => 'FermatMind-Greenfield-Baseline/1.0'],
        ]);
        $downloadedTotal = 0;
        foreach ($entries as $index => &$entry) {
            $url = (string) $entry['url'];
            $extension = $this->safeExtension((string) (parse_url($url, PHP_URL_PATH) ?? ''));
            $identity = GreenfieldBaselineJson::encode([
                'dataset' => $entry['dataset'] ?? null,
                'id' => $entry['id'] ?? null,
                'url' => $url,
            ]);
            $relative = 'objects/'.hash('sha256', $identity).$extension;
            $destination = $mediaDirectory.'/'.$relative;
            $response = $client->request('GET', $url, [RequestOptions::SINK => $destination]);
            if ($response->getStatusCode() !== 200) {
                throw new RuntimeException('Greenfield public media download did not return HTTP 200.');
            }
            clearstatcache(true, $destination);
            $bytes = filesize($destination);
            if (! is_int($bytes) || $bytes < 1 || $bytes > self::MAX_OBJECT_BYTES) {
                throw new RuntimeException('Greenfield public media object size is invalid.');
            }
            $expectedBytes = max(0, (int) ($entry['expected_bytes'] ?? 0));
            if ($expectedBytes > 0 && $bytes !== $expectedBytes) {
                throw new RuntimeException('Greenfield public media object size does not match CMS authority.');
            }
            $downloadedTotal += $bytes;
            if ($downloadedTotal > self::MAX_TOTAL_BYTES) {
                throw new RuntimeException('Greenfield downloaded media exceeds the package safety limit.');
            }
            $entry['object_path'] = $relative;
            $entry['sha256'] = hash_file('sha256', $destination);
            $entry['bytes'] = $bytes;
            $entries[$index] = $entry;
        }
        unset($entry);

        $finalManifest = [
            'schema_version' => 'fermatmind.greenfield.public-media.v1',
            'host_set_sha256' => $hostSetSha256,
            'entries' => $entries,
        ];
        file_put_contents(
            $manifestPath,
            GreenfieldBaselineJson::encode($finalManifest, true)."\n",
            LOCK_EX,
        );

        return [
            'downloaded' => true,
            'entry_count' => count($entries),
            'expected_bytes' => $expectedTotal,
            'downloaded_bytes' => $downloadedTotal,
            'host_count' => count($hostList),
            'host_set_sha256' => $hostSetSha256,
        ];
    }

    private function assertPublicHostname(string $host): void
    {
        if ($host === 'localhost' || str_ends_with($host, '.local') || filter_var($host, FILTER_VALIDATE_IP)) {
            throw new RuntimeException('Greenfield public media hostname is not allowed.');
        }
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records) || $records === []) {
            throw new RuntimeException('Greenfield public media hostname did not resolve.');
        }
        foreach ($records as $record) {
            $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException('Greenfield public media hostname resolved outside public address space.');
            }
        }
    }

    private function safeExtension(string $path): string
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1 ? '.'.$extension : '';
    }
}
