<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait ResolvesOrgId
{
    protected function resolveOrgId(Request $request): int
    {
        $raw = trim((string) $request->header('X-Org-Id', ''));
        if ($raw === '') {
            $raw = trim((string) $request->query('org_id', ''));
        }

        if ($raw !== '' && preg_match('/^\d+$/', $raw)) {
            return (int) $raw;
        }

        return 0;
    }
}
