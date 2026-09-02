<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\External;

final class PinnedTlsExternalContentTransport implements ExternalContentTransport
{
    public function request(string $method, string $url, string $approvedIp, int $connectTimeoutSeconds, int $requestTimeoutSeconds, int $maxBytes): array
    {
        if (! extension_loaded('curl')) {
            throw new ExternalContentGatewayException('TRANSPORT_UNAVAILABLE', 'transport');
        }
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || $host === '' || (int) ($parts['port'] ?? 443) !== 443) {
            throw new ExternalContentGatewayException('TRANSPORT_URL_BLOCKED', 'request');
        }
        if (! in_array($method, ['GET', 'HEAD'], true) || filter_var($approvedIp, FILTER_VALIDATE_IP) === false) {
            throw new ExternalContentGatewayException('TRANSPORT_REQUEST_BLOCKED', 'request');
        }

        $headers = [];
        $body = '';
        $oversized = false;
        $handle = curl_init($url);
        if ($handle === false) {
            throw new ExternalContentGatewayException('TRANSPORT_INIT_FAILED', 'transport', true);
        }
        $resolveAddress = filter_var($approvedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? '['.$approvedIp.']'
            : $approvedIp;
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => max(1, min(3, $connectTimeoutSeconds)),
            CURLOPT_TIMEOUT => max(1, min(8, $requestTimeoutSeconds)),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => [sprintf('%s:443:%s', $host, $resolveAddress)],
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,text/plain,application/json;q=0.8',
                'Accept-Encoding: identity',
                'User-Agent: FermatMindCompetitiveEvidence/1.0',
            ],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $length = strlen($line);
                $separator = strpos($line, ':');
                if ($separator !== false) {
                    $name = strtolower(trim(substr($line, 0, $separator)));
                    $value = trim(substr($line, $separator + 1));
                    if ($name !== '') {
                        $headers[$name] = isset($headers[$name]) ? $headers[$name].', '.$value : $value;
                    }
                }

                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$oversized, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $oversized = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);

        try {
            $ok = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $connectedIp = strtolower((string) curl_getinfo($handle, CURLINFO_PRIMARY_IP));
            if ($ok === false || $oversized) {
                if ($oversized) {
                    throw new ExternalContentGatewayException('CONTENT_RESPONSE_TOO_LARGE', 'size');
                }
                $error = curl_errno($handle);
                $reason = match ($error) {
                    CURLE_OPERATION_TIMEDOUT => 'TRANSPORT_TIMEOUT',
                    CURLE_COULDNT_RESOLVE_HOST => 'DNS_RESOLUTION_FAILED',
                    CURLE_COULDNT_CONNECT => 'TRANSPORT_CONNECT_FAILED',
                    CURLE_SSL_CONNECT_ERROR, CURLE_PEER_FAILED_VERIFICATION, CURLE_SSL_CERTPROBLEM => 'TRANSPORT_TLS_FAILED',
                    CURLE_RECV_ERROR, CURLE_SEND_ERROR, CURLE_GOT_NOTHING => 'TRANSPORT_CONNECTION_RESET',
                    default => 'TRANSPORT_REQUEST_FAILED',
                };
                $retryable = in_array($reason, ['TRANSPORT_CONNECT_FAILED', 'TRANSPORT_TLS_FAILED', 'TRANSPORT_CONNECTION_RESET'], true)
                    && $status === 0;
                throw new ExternalContentGatewayException($reason, 'transport', $retryable);
            }

            return ['status' => $status, 'headers' => $headers, 'body' => $body, 'connected_ip' => $connectedIp];
        } finally {
            curl_close($handle);
        }
    }
}
