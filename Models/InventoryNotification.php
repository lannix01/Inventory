<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Support\UsesInventoryConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InventoryNotification extends Model
{
    use UsesInventoryConnection;

    protected $table = 'inventory_notifications';

    protected $fillable = [
        'inventory_user_id',
        'title',
        'message',
        'type',
        'source_type',
        'source_id',
        'metadata',
        'read_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(InventoryUser::class, 'inventory_user_id');
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeRecent(Builder $query, int $days = 30): Builder
    {
        return $query->whereDate('created_at', '>=', now()->subDays($days)->toDateString());
    }

    public function markAsRead(): void
    {
        if (!$this->read_at) {
            $this->update(['read_at' => now()]);
        }
    }

    public static function createNotification(
        InventoryUser $user,
        string $title,
        string $message,
        string $type,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?array $metadata = null
    ): self {
        return self::create([
            'inventory_user_id' => $user->id,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'metadata' => $metadata,
        ]);
    }
}
