<?php

namespace Aesis\Tenancy\Models\Concerns;

use Aesis\Tenancy\Attributes\CommonResource;
use Aesis\Tenancy\Attributes\HybridResource;
use Aesis\Tenancy\Contracts\CurrentTenant;
use Aesis\Tenancy\Database\Eloquent\TenantResourceBuilder;
use Aesis\Tenancy\Enums\TenantStatus;
use Aesis\Tenancy\Exceptions\TenantContextRequired;
use Aesis\Tenancy\Exceptions\TenantResourceAccessDenied;
use Aesis\Tenancy\Scopes\TenantResourceScope;
use Aesis\Tenancy\Support\TenantResourceMetadata;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

trait HasResourceTenancy
{
    /** @param \Illuminate\Database\Query\Builder $query */
    public function newEloquentBuilder($query): TenantResourceBuilder
    {
        return new TenantResourceBuilder($query);
    }

    public function scopeOwnedByCurrentTenant(Builder $query): Builder
    {
        $attribute = TenantResourceMetadata::for(static::class);
        if ($attribute instanceof CommonResource) {
            throw new LogicException('Common resources are not owned by a tenant.');
        }

        $context = app(CurrentTenant::class);
        $tenant = $context->get();
        if ($context->isPlatform() || $tenant === null || $tenant->status !== TenantStatus::Active) {
            throw TenantContextRequired::forModel(static::class);
        }

        return $query->where($query->getModel()->qualifyColumn($attribute->column), $tenant->getKey());
    }

    protected static function bootHasResourceTenancy(): void
    {
        $attribute = TenantResourceMetadata::for(static::class);

        if ($attribute instanceof CommonResource) {
            return;
        }

        $tenantColumn = $attribute->column;
        $includesCommonRecords = $attribute instanceof HybridResource;

        static::addGlobalScope(new TenantResourceScope($tenantColumn, $includesCommonRecords));

        static::saving(function (Model $model) use ($tenantColumn, $includesCommonRecords): void {
            static::ensureWriteIsScoped($model, $tenantColumn, $includesCommonRecords);
        });

        static::deleting(function (Model $model) use ($tenantColumn, $includesCommonRecords): void {
            static::ensureExistingRecordIsScoped($model, $tenantColumn, $includesCommonRecords);
        });
    }

    protected static function ensureWriteIsScoped(Model $model, string $tenantColumn, bool $includesCommonRecords): void
    {
        $context = app(CurrentTenant::class);
        $tenant = $context->get();
        $isPlatform = $context->isPlatform();
        $currentTenantId = $tenant?->getKey();
        $recordTenantId = $model->getAttribute($tenantColumn);

        if (! $model->exists) {
            if ($currentTenantId !== null) {
                if ($tenant->status !== TenantStatus::Active) {
                    throw TenantContextRequired::forModel($model::class);
                }

                if ($recordTenantId !== null && (string) $recordTenantId !== (string) $currentTenantId) {
                    throw TenantResourceAccessDenied::forModel($model::class);
                }

                $model->setAttribute($tenantColumn, $currentTenantId);

                return;
            }

            if (! $isPlatform || (! $includesCommonRecords && $recordTenantId === null)) {
                throw TenantContextRequired::forModel($model::class);
            }

            return;
        }

        static::ensureExistingRecordIsScoped($model, $tenantColumn, $includesCommonRecords);

        $originalTenantId = $model->getRawOriginal($tenantColumn);
        if ((string) $recordTenantId !== (string) $originalTenantId) {
            throw TenantResourceAccessDenied::forModel($model::class);
        }
    }

    protected static function ensureExistingRecordIsScoped(Model $model, string $tenantColumn, bool $includesCommonRecords): void
    {
        $context = app(CurrentTenant::class);
        if ($context->isPlatform()) {
            return;
        }

        $tenant = $context->get();
        if ($tenant === null || $tenant->status !== TenantStatus::Active) {
            throw TenantContextRequired::forModel($model::class);
        }

        $recordTenantId = $model->getAttribute($tenantColumn);
        if (($includesCommonRecords && $recordTenantId === null)
            || (string) $recordTenantId !== (string) $tenant->getKey()) {
            throw TenantResourceAccessDenied::forModel($model::class);
        }
    }
}
