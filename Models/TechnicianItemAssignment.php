<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Support\UsesInventoryConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicianItemAssignment extends Model
{
    use UsesInventoryConnection;

    protected $table = 'inventory_technician_item_assignments';

    protected $fillable = [
        'technician_id',
        'item_id',
        'qty_allocated',
        'qty_deployed',
        'assigned_by',
        'assigned_at',
        'is_active',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function technician(): BelongsTo
    {
        return $this->belongsTo(InventoryUser::class, 'technician_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(InventoryUser::class, 'assigned_by');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function availableToDeploy(): int
    {
        $available = (int)$this->qty_allocated - (int)$this->qty_deployed;
        return max(0, $available);
    }
}
