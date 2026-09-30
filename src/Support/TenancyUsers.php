<?php

namespace Aesis\Tenancy\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Spatie\Permission\Guard;

final class TenancyUsers
{
    /** @return class-string<Model> */
    public static function model(): string
    {
        $model = config('tenancy.models.user') ?? config('auth.providers.users.model');

        if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
            throw new InvalidArgumentException('The tenancy user model must be an Eloquent model.');
        }

        return $model;
    }

    public static function guardName(): string
    {
        return Guard::getNames(self::model())->first()
            ?? config('auth.defaults.guard', 'web');
    }

    public static function morphType(): string
    {
        $model = self::model();

        return (new $model)->getMorphClass();
    }

    /** @return Builder<Model> */
    public static function query(): Builder
    {
        $model = self::model();

        return $model::query();
    }

    public static function queryByEmail(string $email): Builder
    {
        $query = self::query();
        $column = $query->getModel()->qualifyColumn(config('tenancy.user.email_column', 'email'));
        $wrappedColumn = $query->getQuery()->getGrammar()->wrap($column);

        return $query->whereRaw('LOWER('.$wrappedColumn.') = ?', [mb_strtolower(trim($email))]);
    }

    public static function email(Model $user): string
    {
        return (string) $user->getAttribute(config('tenancy.user.email_column', 'email'));
    }

    public static function name(Model $user): string
    {
        $column = config('tenancy.user.name_column', 'name');

        return $column === null ? '' : (string) $user->getAttribute($column);
    }
}
