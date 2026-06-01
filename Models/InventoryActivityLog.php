<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Support\UsesInventoryConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryActivityLog extends Model
{
    use UsesInventoryConnection;

    protected $table = 'inventory_activity_logs';

    protected $fillable = [
        'inventory_user_id',
        'action',
        'route_name',
        'method',
        'url',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(InventoryUser::class, 'inventory_user_id');
    }
}
