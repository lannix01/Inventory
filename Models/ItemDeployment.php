<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Support\UsesInventoryConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemDeployment extends Model
{
    use UsesInventoryConnection;

    protected $table = 'inventory_item_deployments';

    protected $fillable = [
        'technician_id',
        'item_id',
        'qty',
        'site_code',
        'site_name',
        'reference',
        'notes',
        'created_by',
    ];

    public function technician(): BelongsTo
    {
        return $this->belongsTo(InventoryUser::class, 'technician_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(InventoryUser::class, 'created_by');
    }
}
