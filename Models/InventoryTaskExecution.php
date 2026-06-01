<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Support\UsesInventoryConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTaskExecution extends Model
{
    use UsesInventoryConnection;

    protected $table = 'inventory_task_executions';

    protected $fillable = [
        'task_id',
        'user_id',
        'action',
        'description',
        'metadata',
        'latitude',
        'longitude',
        'location_accuracy',
        'executed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'executed_at' => 'datetime',
    ];

    // Relationships
    public function task(): BelongsTo
    {
        return $this->belongsTo(InventoryTask::class, 'task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(InventoryUser::class, 'user_id');
    }

    // Scopes
    public function scopeByTask($query, $taskId)
    {
        return $query->where('task_id', $taskId);
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByAction($query, $action)
    {
        return $query->where('action', $action);
    }

    public function scopeRecent($query, $days = 7)
    {
        return $query->where('executed_at', '>=', now()->subDays($days));
    }

    public function scopeToday($query)
    {
        return $query->whereDate('executed_at', today());
    }

    public function scopeThisWeek($query)
    {
        return $query->whereBetween('executed_at', [
            now()->startOfWeek(),
            now()->endOfWeek()
        ]);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereYear('executed_at', now()->year)
                    ->whereMonth('executed_at', now()->month);
    }

    // Helper methods
    public static function logAction(int $taskId, int $userId, string $action, string $description = null, array $metadata = null, float $latitude = null, float $longitude = null, string $accuracy = null): self
    {
        return static::create([
            'task_id' => $taskId,
            'user_id' => $userId,
            'action' => $action,
            'description' => $description,
            'metadata' => $metadata,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_accuracy' => $accuracy,
            'executed_at' => now(),
        ]);
    }

    public function hasLocation(): bool
    {
        return !is_null($this->latitude) && !is_null($this->longitude);
    }

    public function getLocationString(): string
    {
        if (!$this->hasLocation()) {
            return 'No location data';
        }

        return "{$this->latitude}, {$this->longitude}" .
               ($this->location_accuracy ? " (±{$this->location_accuracy})" : '');
    }

    // Action type constants
    const ACTION_STARTED = 'started';
    const ACTION_PAUSED = 'paused';
    const ACTION_RESUMED = 'resumed';
    const ACTION_COMPLETED = 'completed';
    const ACTION_CANCELLED = 'cancelled';
    const ACTION_ITEM_ASSIGNED = 'item_assigned';
    const ACTION_ITEM_USED = 'item_used';
    const ACTION_ITEM_RETURNED = 'item_returned';
    const ACTION_LOCATION_CHECKIN = 'location_checkin';
    const ACTION_LOCATION_CHECKOUT = 'location_checkout';
    const ACTION_EVIDENCE_UPLOADED = 'evidence_uploaded';
    const ACTION_NOTES_ADDED = 'notes_added';

    public static function getActionTypes(): array
    {
        return [
            self::ACTION_STARTED,
            self::ACTION_PAUSED,
            self::ACTION_RESUMED,
            self::ACTION_COMPLETED,
            self::ACTION_CANCELLED,
            self::ACTION_ITEM_ASSIGNED,
            self::ACTION_ITEM_USED,
            self::ACTION_ITEM_RETURNED,
            self::ACTION_LOCATION_CHECKIN,
            self::ACTION_LOCATION_CHECKOUT,
            self::ACTION_EVIDENCE_UPLOADED,
            self::ACTION_NOTES_ADDED,
        ];
    }
}