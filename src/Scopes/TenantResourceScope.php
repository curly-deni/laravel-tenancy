<?php

namespace Aesis\Tenancy\Scopes;

use Aesis\Tenancy\Contracts\CurrentTenant;
use Aesis\Tenancy\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final readonly class TenantResourceScope implements Scope
{
    public function __construct(
        private string $tenantColumn,
        private bool $includesCommonRecords,
    ) {}

    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CurrentTenant::class);

        if ($context->isPlatform()) {
            return;
        }

        $tenant = $context->get();

        if ($tenant !== null && $tenant->status !== TenantStatus::Active) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $column = $model->qualifyColumn($this->tenantColumn);

        if ($tenant === null) {
            if ($this->includesCommonRecords) {
                $builder->whereNull($column);
            } else {
                $builder->whereRaw('1 = 0');
            }

            return;
        }

        if ($this->includesCommonRecords) {
            $builder->where(fn (Builder $query) => $query
                ->where($column, $tenant->getKey())
                ->orWhereNull($column));

            return;
        }

        $builder->where($column, $tenant->getKey());
    }
}
