<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Support\UsesInventoryConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryMovement extends Model
{
    use UsesInventoryConnection;

    protected $table = 'inventory_movements';

    protected $fillable = [
        'reference',
        'type',
        'movement_at',
        'from_user_id',
        'to_user_id',
        'site_code',
        'site_name',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'movement_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryMovementLine::class, 'movement_id');
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'to_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
