<?php

namespace Aesis\Tenancy\Database\Eloquent;

use Aesis\Tenancy\Attributes\CommonResource;
use Aesis\Tenancy\Contracts\CurrentTenant;
use Aesis\Tenancy\Enums\TenantStatus;
use Aesis\Tenancy\Exceptions\TenantContextRequired;
use Aesis\Tenancy\Exceptions\TenantResourceAccessDenied;
use Aesis\Tenancy\Models\Tenant;
use Aesis\Tenancy\Support\TenantResourceMetadata;
use Illuminate\Database\Eloquent\Builder;

class TenantResourceBuilder extends Builder
{
    public function insert(array $values)
    {
        return parent::insert($this->prepareInsertValues($values));
    }

    public function insertOrIgnore(array $values)
    {
        return parent::insertOrIgnore($this->prepareInsertValues($values));
    }

    public function insertOrIgnoreReturning(array $values, array $returning = ['*'], array|string|null $uniqueBy = null)
    {
        return parent::insertOrIgnoreReturning($this->prepareInsertValues($values), $returning, $uniqueBy);
    }

    public function insertGetId(array $values, $sequence = null)
    {
        return parent::insertGetId($this->prepareInsertValues($values), $sequence);
    }

    public function insertUsing(array $columns, $query)
    {
        $this->ensureInsertUsingIsAllowed();

        return parent::insertUsing($columns, $query);
    }

    public function insertOrIgnoreUsing(array $columns, $query)
    {
        $this->ensureInsertUsingIsAllowed();

        return parent::insertOrIgnoreUsing($columns, $query);
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        if ($values === []) {
            return 0;
        }

        $attribute = TenantResourceMetadata::for($this->getModel()::class);
        if ($attribute instanceof CommonResource || app(CurrentTenant::class)->isPlatform()) {
            return parent::upsert($values, $uniqueBy, $update);
        }

        $values = $this->prepareInsertValues($values);
        $uniqueColumns = is_array($uniqueBy) ? $uniqueBy : [$uniqueBy];

        if (! in_array($attribute->column, array_map($this->unqualifyColumn(...), $uniqueColumns), true)) {
            throw TenantResourceAccessDenied::forModel($this->getModel()::class);
        }

        if ($update === null) {
            $update = array_values(array_diff(array_keys($values[0] ?? $values), [$attribute->column]));
        } else {
            $updateColumns = array_map($this->unqualifyColumn(...), $update);
            if (in_array($attribute->column, $updateColumns, true)) {
                throw TenantResourceAccessDenied::forModel($this->getModel()::class);
            }
        }

        return parent::upsert($values, $uniqueBy, $update);
    }

    public function update(array $values): int
    {
        $attribute = TenantResourceMetadata::for($this->getModel()::class);
        if ($attribute instanceof CommonResource) {
            return parent::update($values);
        }

        $context = app(CurrentTenant::class);
        if ($context->isPlatform()) {
            $this->ensureTenantColumnIsNotUpdated($attribute->column, $values);

            return parent::update($values);
        }

        $tenant = $context->get();
        if ($tenant === null || $tenant->status !== TenantStatus::Active) {
            throw TenantContextRequired::forModel($this->getModel()::class);
        }

        $this->ensureTenantColumnIsNotUpdated($attribute->column, $values, $tenant->getKey());

        $this->where($this->getModel()->qualifyColumn($attribute->column), $tenant->getKey());

        return parent::update($values);
    }

    public function delete(): ?int
    {
        $this->scopeMutationToTenant();

        return parent::delete();
    }

    public function forceDelete(): int
    {
        $this->scopeMutationToTenant();

        return parent::forceDelete();
    }

    private function scopeMutationToTenant(): void
    {
        $attribute = TenantResourceMetadata::for($this->getModel()::class);
        if ($attribute instanceof CommonResource) {
            return;
        }

        $context = app(CurrentTenant::class);
        if ($context->isPlatform()) {
            return;
        }

        $tenant = $context->get();
        if ($tenant === null || $tenant->status !== TenantStatus::Active) {
            throw TenantContextRequired::forModel($this->getModel()::class);
        }

        $this->where($this->getModel()->qualifyColumn($attribute->column), $tenant->getKey());
    }

    /** @param array<string, mixed>|list<array<string, mixed>> $values */
    private function prepareInsertValues(array $values): array
    {
        if ($values === []) {
            return [];
        }

        $attribute = TenantResourceMetadata::for($this->getModel()::class);
        if ($attribute instanceof CommonResource || app(CurrentTenant::class)->isPlatform()) {
            return $values;
        }

        $tenant = $this->activeTenant();
        $isSingleRow = ! is_array(reset($values));
        $rows = $isSingleRow ? [$values] : $values;

        foreach ($rows as &$row) {
            if (! array_key_exists($attribute->column, $row) || $row[$attribute->column] === null) {
                $row[$attribute->column] = $tenant->getKey();

                continue;
            }

            if ((string) $row[$attribute->column] !== (string) $tenant->getKey()) {
                throw TenantResourceAccessDenied::forModel($this->getModel()::class);
            }
        }
        unset($row);

        return $isSingleRow ? $rows[0] : $rows;
    }

    private function ensureInsertUsingIsAllowed(): void
    {
        $attribute = TenantResourceMetadata::for($this->getModel()::class);
        if ($attribute instanceof CommonResource || app(CurrentTenant::class)->isPlatform()) {
            return;
        }

        $this->activeTenant();

        throw TenantResourceAccessDenied::forModel($this->getModel()::class);
    }

    private function activeTenant(): Tenant
    {
        $tenant = app(CurrentTenant::class)->get();
        if ($tenant === null || $tenant->status !== TenantStatus::Active) {
            throw TenantContextRequired::forModel($this->getModel()::class);
        }

        return $tenant;
    }

    private function unqualifyColumn(string $column): string
    {
        return str($column)->afterLast('.')->toString();
    }

    /** @param array<string, mixed> $values */
    private function ensureTenantColumnIsNotUpdated(string $column, array $values, int|string|null $tenantId = null): void
    {
        if (! array_key_exists($column, $values)) {
            return;
        }

        if ($tenantId !== null && (string) $values[$column] === (string) $tenantId) {
            return;
        }

        throw TenantResourceAccessDenied::forModel($this->getModel()::class);
    }
}
