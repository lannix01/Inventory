<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Support\UsesInventoryConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use UsesInventoryConnection;

    protected $table = 'inventory_departments';

    protected $fillable = ['name', 'code'];

    public function users(): HasMany
    {
        return $this->hasMany(InventoryUser::class, 'department_id');
    }
}
