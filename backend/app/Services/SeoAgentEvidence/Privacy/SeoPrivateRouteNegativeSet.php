<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Privacy;

final class SeoPrivateRouteNegativeSet
{
    /** @var list<string> */
    private const ROOTS = ['attempt', 'attempts', 'result', 'results', 'report', 'reports', 'history', 'order', 'orders', 'payment', 'payments', 'token', 'tokens', 'user', 'users', 'account', 'accounts', 'auth', 'authorization', 'invite', 'invites', 'recovery', 'recover', 'checkout', 'pay', 'share', 'private_report'];

    /** @var list<string> */
    private const ENTITIES = ['attempt', 'result', 'report', 'history', 'order', 'payment', 'token', 'user_identity', 'account', 'auth', 'invite', 'recovery'];

    /** @return array{private:bool,code:string} */
    public function classify(?string $path = null, ?string $routeName = null, ?string $entityType = null): array
    {
        if ($entityType !== null && in_array(strtolower(trim($entityType)), self::ENTITIES, true)) {
            return ['private' => true, 'code' => 'PRIVATE_ENTITY_TYPE'];
        }
        if ($routeName !== null) {
            $tokens = preg_split('/[.\/_-]+/', strtolower($routeName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (array_intersect($tokens, self::ROOTS) !== []) {
                return ['private' => true, 'code' => 'PRIVATE_ROUTE_NAME'];
            }
        }
        if ($path !== null) {
            $decoded = strtolower($path);
            for ($i = 0; $i < 2; $i++) {
                $next = rawurldecode($decoded);
                if ($next === $decoded) {
                    break;
                }
                $decoded = $next;
            }
            $pathOnly = parse_url($decoded, PHP_URL_PATH);
            $segments = array_values(array_filter(explode('/', trim((string) $pathOnly, '/')), 'strlen'));
            if (isset($segments[0]) && in_array($segments[0], ['en', 'zh', 'zh-cn'], true)) {
                array_shift($segments);
            }
            if (($segments[0] ?? null) === 'api') {
                array_shift($segments);
                if (isset($segments[0]) && preg_match('/^v?\d+(?:[._-]\d+)*$/', $segments[0]) === 1) {
                    array_shift($segments);
                }
            }
            if (isset($segments[0]) && in_array($segments[0], self::ROOTS, true)) {
                return ['private' => true, 'code' => 'PRIVATE_ROUTE_ROOT'];
            }
        }

        return ['private' => false, 'code' => 'PUBLIC_OR_UNKNOWN'];
    }
}
