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

        /** @var OrgContext $orgContext */
        $orgContext = app(OrgContext::class);
        if ($orgContext->isTenantContext()) {
            $builder->where($model->qualifyColumn('org_id'), $orgContext->requirePositiveOrgId());
            return;
        }

        if (! $this->isHttpRequest()) {
            return;
        }

        $publicOrgId = $this->publicContextOrgId($model);
        if ($orgContext->isPublicContext() && $publicOrgId !== null) {
            $builder->where($model->qualifyColumn('org_id'), $publicOrgId);
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

    private function publicContextOrgId(Model $model): ?int
    {
        if (! method_exists($model, 'publicContextOrgId')) {
            return $this->boolModelMethod($model, 'allowOrgZeroContext') ? 0 : null;
        }

        $reflection = new ReflectionMethod($model, 'publicContextOrgId');
        $value = $reflection->isStatic()
            ? $model::publicContextOrgId()
            : $model->publicContextOrgId();

        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
