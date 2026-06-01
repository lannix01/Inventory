<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Support\UsesInventoryConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTeamMember extends Model
{
    use UsesInventoryConnection;

    protected $table = 'inventory_team_members';

    protected $fillable = [
        'team_id',
        'technician_id',
        'role',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(InventoryTeam::class, 'team_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'technician_id');
    }
}
