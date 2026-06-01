<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Support\UsesInventoryConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemUnit extends Model
{
    use UsesInventoryConnection;

    protected $table = 'inventory_item_units';

    protected $fillable = [
        'item_id',
        'serial_no',
        'status',          // in_store | assigned | deployed
        'assigned_to',     // user_id (technician/admin) when assigned
        'assigned_at',
        'deployed_site_code',
        'deployed_site_name',
        'deployed_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'deployed_at' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(InventoryUser::class, 'assigned_to');
    }
}
