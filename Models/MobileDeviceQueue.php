<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Support\UsesInventoryConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileDeviceQueue extends Model
{
    use UsesInventoryConnection;

    protected $table = 'mobile_device_queues';

    protected $fillable = [
        'device_id',
        'user_id',
        'operation_type',
        'sync_status',
        'operation_data',
        'error_message',
        'queued_at',
        'processed_at',
        'retry_count',
        'next_retry_at',
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'operation_data' => 'array',
        'queued_at' => 'datetime',
        'processed_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'retry_count' => 'integer',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(InventoryUser::class, 'user_id');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('sync_status', 'pending');
    }

    public function scopeProcessing($query)
    {
        return $query->where('sync_status', 'processing');
    }

    public function scopeCompleted($query)
    {
        return $query->where('sync_status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('sync_status', 'failed');
    }

    public function scopeByDevice($query, $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByOperationType($query, $type)
    {
        return $query->where('operation_type', $type);
    }

    public function scopeReadyForRetry($query)
    {
        return $query->where('sync_status', 'failed')
                    ->where('next_retry_at', '<=', now())
                    ->where('retry_count', '<', 3);
    }

    public function scopeOverdue($query)
    {
        return $query->where('queued_at', '<', now()->subHours(24))
                    ->whereIn('sync_status', ['pending', 'processing']);
    }

    // Helper methods
    public function markAsProcessing(): bool
    {
        return $this->update([
            'sync_status' => 'processing',
            'processed_at' => now(),
        ]);
    }

    public function markAsCompleted(): bool
    {
        return $this->update([
            'sync_status' => 'completed',
            'processed_at' => now(),
        ]);
    }

    public function markAsFailed(string $errorMessage = null): bool
    {
        $retryCount = $this->retry_count + 1;
        $nextRetryAt = null;

        // Exponential backoff: 5min, 30min, 2hours
        if ($retryCount < 3) {
            $delays = [5, 30, 120]; // minutes
            $nextRetryAt = now()->addMinutes($delays[$retryCount - 1]);
        }

        return $this->update([
            'sync_status' => 'failed',
            'error_message' => $errorMessage,
            'retry_count' => $retryCount,
            'next_retry_at' => $nextRetryAt,
            'processed_at' => now(),
        ]);
    }

    public function canRetry(): bool
    {
        return $this->sync_status === 'failed' &&
               $this->retry_count < 3 &&
               $this->next_retry_at &&
               $this->next_retry_at->isPast();
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

        return "{$this->latitude}, {$this->longitude}";
    }

    public function getOperationData(): array
    {
        return $this->operation_data ?? [];
    }

    public function setOperationData(array $data): bool
    {
        return $this->update(['operation_data' => $data]);
    }

    // Operation type constants
    const OP_TASK_START = 'task_start';
    const OP_TASK_PAUSE = 'task_pause';
    const OP_TASK_RESUME = 'task_resume';
    const OP_TASK_COMPLETE = 'task_complete';
    const OP_EVIDENCE_UPLOAD = 'evidence_upload';
    const OP_LOCATION_UPDATE = 'location_update';
    const OP_ITEM_SCAN = 'item_scan';
    const OP_TASK_CREATE = 'task_create';
    const OP_TASK_UPDATE = 'task_update';
    const OP_SYNC_REQUEST = 'sync_request';

    public static function getOperationTypes(): array
    {
        return [
            self::OP_TASK_START,
            self::OP_TASK_PAUSE,
            self::OP_TASK_RESUME,
            self::OP_TASK_COMPLETE,
            self::OP_EVIDENCE_UPLOAD,
            self::OP_LOCATION_UPDATE,
            self::OP_ITEM_SCAN,
            self::OP_TASK_CREATE,
            self::OP_TASK_UPDATE,
            self::OP_SYNC_REQUEST,
        ];
    }

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    public static function getStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
        ];
    }

    // Static factory methods
    public static function queueOperation(string $deviceId, int $userId, string $operationType, array $operationData, float $latitude = null, float $longitude = null): self
    {
        return static::create([
            'device_id' => $deviceId,
            'user_id' => $userId,
            'operation_type' => $operationType,
            'operation_data' => $operationData,
            'queued_at' => now(),
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }
}