<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Support\UsesInventoryConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    use UsesInventoryConnection;

    protected $table = 'inventory_items';

    protected $fillable = [
        'item_group_id',
        'name',
        'sku',
        'unit',
        'description',
        'has_serial',
        'reorder_level',
        'qty_on_hand',
        'is_active',
    ];

    protected $casts = [
        'has_serial' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ItemGroup::class, 'item_group_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(ItemUnit::class, 'item_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TechnicianItemAssignment::class, 'item_id');
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(ItemDeployment::class, 'item_id');
    }

    public function isLowStock(): bool
    {
        return (int)$this->qty_on_hand <= (int)$this->reorder_level;
    }
}
