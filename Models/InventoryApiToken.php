<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Support\InventoryDatabase;
use App\Modules\Inventory\Support\UsesInventoryConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class InventoryApiToken extends Model
{
    use UsesInventoryConnection;

    protected $table = 'inventory_api_tokens';

    protected $fillable = [
        'inventory_user_id',
        'name',
        'device_id',
        'device_platform',
        'token',
        'last_used_at',
        'last_ip',
        'last_user_agent',
        'expires_at',
        'revoked_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(InventoryUser::class, 'inventory_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        $query->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });

        if (self::supportsColumn('revoked_at')) {
            $query->whereNull('revoked_at');
        }

        return $query;
    }

    public static function supportsColumn(string $column): bool
    {
        static $cache = [];

        if (!array_key_exists($column, $cache)) {
            $cache[$column] = Schema::connection(InventoryDatabase::connectionName())->hasColumn('inventory_api_tokens', $column);
        }

        return $cache[$column];
    }
}
