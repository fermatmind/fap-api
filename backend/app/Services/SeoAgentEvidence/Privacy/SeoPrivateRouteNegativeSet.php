<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Privacy;

use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;

final class SeoPrivateRouteNegativeSet
{
    /** @var list<string> */
    private const PERMANENT_ROUTE_ROOTS = ['user', 'users', 'auth', 'authorization', 'invite', 'invites', 'private_report', 'report_private'];

    /** @var list<string> */
    private const PERMANENT_ENTITY_TYPES = ['user_identity', 'auth', 'invite'];

    public function __construct(private readonly PageFamilyPolicyRegistry $pageFamilies) {}

    /** @return array{private:bool,code:string} */
    public function classify(?string $path = null, ?string $routeName = null, ?string $entityType = null): array
    {
        $privateEntities = array_values(array_unique([
            ...$this->pageFamilies->privatePageEntityTypes(),
            ...self::PERMANENT_ENTITY_TYPES,
        ]));
        $privateSegments = array_values(array_unique([
            ...$this->pageFamilies->privatePathSegments(),
            ...self::PERMANENT_ROUTE_ROOTS,
        ]));
        if ($entityType !== null && in_array($this->normalizeToken($entityType), $privateEntities, true)) {
            return ['private' => true, 'code' => 'PRIVATE_ENTITY_TYPE'];
        }
        if ($routeName !== null) {
            $tokens = preg_split('/[.\/_-]+/', $this->normalizeToken($routeName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (array_intersect($tokens, $privateSegments) !== []) {
                return ['private' => true, 'code' => 'PRIVATE_ROUTE_NAME'];
            }
        }
        if ($path !== null) {
            $decoded = strtolower($path);
            for ($i = 0; $i < 5; $i++) {
                $next = rawurldecode($decoded);
                if ($next === $decoded) {
                    break;
                }
                $decoded = $next;
            }
            $pathOnly = parse_url($decoded, PHP_URL_PATH);
            $segments = array_values(array_filter(explode('/', trim((string) $pathOnly, '/')), 'strlen'));
            if (array_intersect($segments, $privateSegments) !== []) {
                return ['private' => true, 'code' => 'PRIVATE_ROUTE_ROOT'];
            }
        }

        return ['private' => false, 'code' => 'PUBLIC_OR_UNKNOWN'];
    }

    private function normalizeToken(string $value): string
    {
        $value = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', trim($value)) ?? $value;

        return strtolower($value);
    }
}
