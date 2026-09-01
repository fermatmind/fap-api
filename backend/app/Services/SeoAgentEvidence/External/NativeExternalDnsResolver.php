<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\External;

use RuntimeException;

final class NativeExternalDnsResolver implements ExternalDnsResolver
{
    public function resolveAll(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records)) {
            throw new RuntimeException('DNS_LOOKUP_FAILED');
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false) {
                $addresses[] = strtolower($address);
            }
        }
        $addresses = array_values(array_unique($addresses));
        sort($addresses, SORT_STRING);
        if ($addresses === []) {
            throw new RuntimeException('DNS_EMPTY');
        }

        return $addresses;
    }
}
