<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Exceptions\OrgContextMissingException;
use App\Support\OrgContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use ReflectionMethod;

final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($this->boolModelMethod($model, 'bypassTenantScope')) {
            return;
        }

        $orgId = (int) app(OrgContext::class)->orgId();
        if ($orgId > 0) {
            $builder->where($model->qualifyColumn('org_id'), $orgId);
            return;
        }

        if (!$this->isHttpRequest()) {
            return;
        }

        if ($this->boolModelMethod($model, 'allowOrgZeroContext')) {
            $builder->where($model->qualifyColumn('org_id'), 0);
            return;
        }

        if ($this->boolModelMethod($model, 'failClosedWhenOrgMissing', true)) {
            throw new OrgContextMissingException($model::class);
        }
    }

    private function isHttpRequest(): bool
    {
        if (!app()->bound('request')) {
            return false;
        }

        $request = request();

        return $request->is('api/*') || $request->is('ops*') || $request->is('tenant*');
    }

    private function boolModelMethod(Model $model, string $method, bool $default = false): bool
    {
        if (!method_exists($model, $method)) {
            return $default;
        }

        $reflection = new ReflectionMethod($model, $method);
        if ($reflection->isStatic()) {
            return (bool) $model::{$method}();
        }

        return (bool) $model->{$method}();
    }
}
