<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait ResolvesOrgId
{
    protected function resolveOrgId(Request $request): int
    {
        $attrOrgId = $request->attributes->get('org_id');
        if (is_numeric($attrOrgId)) {
            return max(0, (int) $attrOrgId);
        }

        $attrFmOrgId = $request->attributes->get('fm_org_id');
        if (is_numeric($attrFmOrgId)) {
            return max(0, (int) $attrFmOrgId);
        }

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
