<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use App\Exceptions\OrgContextMissingException;
use App\Models\Concerns\HasOrgScope;
use App\Support\OrgContext;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;

abstract class BaseTenantResource extends Resource
{
    public static function getEloquentQuery(): Builder
    {
        $orgId = (int) app(OrgContext::class)->orgId();
        if ($orgId <= 0) {
            throw new OrgContextMissingException(static::getModel());
        }

        $model = static::getModel();
        $uses = class_uses_recursive($model);
        if (!in_array(HasOrgScope::class, $uses, true)) {
            throw new \LogicException($model . ' must use HasOrgScope');
        }

        return parent::getEloquentQuery()->where('org_id', $orgId);
    }
}
