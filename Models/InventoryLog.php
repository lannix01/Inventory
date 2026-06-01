<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Support\UsesInventoryConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryLog extends Model
{
    use UsesInventoryConnection;

    protected $table = 'inventory_logs';

    protected $fillable = [
        'action',              // received | assigned | deployed
        'item_id',
        'item_unit_id',        // nullable for bulk
        'qty',                 // nullable for serialized
        'serial_no',           // cached
        'from_user_id',        // nullable
        'to_user_id',          // nullable
        'site_code',           // nullable
        'site_name',           // nullable
        'reference',           // nullable
        'notes',               // nullable
        'created_by',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ItemUnit::class, 'item_unit_id');
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(InventoryUser::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(InventoryUser::class, 'to_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(InventoryUser::class, 'created_by');
    }
}
