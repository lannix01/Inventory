<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Support\UsesInventoryConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTeamItemAssignment extends Model
{
    use UsesInventoryConnection;

    protected $table = 'inventory_team_item_assignments';

    protected $fillable = [
        'team_id',
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

    public function team(): BelongsTo
    {
        return $this->belongsTo(InventoryTeam::class, 'team_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'assigned_by');
    }

    public function availableToDeploy(): int
    {
        $allocated = (int)$this->qty_allocated;
        $deployed  = (int)$this->qty_deployed;
        return max(0, $allocated - $deployed);
    }
}
