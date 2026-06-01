<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Support\UsesInventoryConnection;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class InventoryUser extends Authenticatable
{
    use Notifiable;
    use UsesInventoryConnection;

    protected $table = 'inventory_users';

    protected $fillable = [
        'name',
        'email',
        'phone_no',
        'password',
        'inventory_role',
        'inventory_permissions',
        'inventory_enabled',
        'inventory_force_password_change',
        'last_login_at',
        'last_login_ip',
        'last_login_user_agent',
        'login_sms_sent_at',
        'department_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'inventory_permissions' => 'array',
        'inventory_enabled' => 'boolean',
        'inventory_force_password_change' => 'boolean',
        'inventory_password_changed_at' => 'datetime',
        'last_login_at' => 'datetime',
        'login_sms_sent_at' => 'datetime',
        'two_factor_backup_codes' => 'array',
        'two_factor_confirmed_at' => 'datetime',
    ];


    public function department()
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function apiTokens()
    {
        return $this->hasMany(InventoryApiToken::class, 'inventory_user_id');
    }
}
