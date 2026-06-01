<?php

namespace App\Modules\Inventory\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InventoryDatabase
{
    public static function connectionName(): string
    {
        return (string) config('inventory.database.connection', 'inventory');
    }

    public static function sourceConnectionName(): string
    {
        return (string) config('inventory.database.source_connection', config('database.default', 'mysql'));
    }

    public static function activate(): void
    {
        $connection = self::connectionName();

        config(['database.default' => $connection]);
        DB::setDefaultConnection($connection);
    }

    public static function connection(): Connection
    {
        return DB::connection(self::connectionName());
    }

    public static function table(string $table): Builder
    {
        return self::connection()->table($table);
    }

    public static function query(): Builder
    {
        return self::connection()->query();
    }

    public static function schema(): SchemaBuilder
    {
        return Schema::connection(self::connectionName());
    }

    public static function transaction(Closure $callback, int $attempts = 1): mixed
    {
        return self::connection()->transaction($callback, $attempts);
    }
}
