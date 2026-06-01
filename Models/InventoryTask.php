<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Support\UsesInventoryConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class InventoryTask extends Model
{
    use UsesInventoryConnection;

    protected $table = 'inventory_tasks';

    protected $fillable = [
        'title',
        'description',
        'status',
        'priority',
        'assigned_to',
        'created_by',
        'scheduled_start',
        'scheduled_end',
        'actual_start',
        'completed_at',
        'location',
        'task_type',
        'metadata',
        'is_recurring',
        'recurrence_pattern',
        'recurrence_interval',
        'recurrence_end_date',
        'parent_task_id',
        'progress_percentage',
        'completion_notes',
    ];

    protected $casts = [
        'scheduled_start' => 'datetime',
        'scheduled_end' => 'datetime',
        'actual_start' => 'datetime',
        'completed_at' => 'datetime',
        'recurrence_end_date' => 'datetime',
        'metadata' => 'array',
        'is_recurring' => 'boolean',
        'progress_percentage' => 'integer',
    ];

    // Relationships
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(InventoryUser::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(InventoryUser::class, 'created_by');
    }

    public function parentTask(): BelongsTo
    {
        return $this->belongsTo(InventoryTask::class, 'parent_task_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(InventoryTask::class, 'parent_task_id');
    }

    public function taskItems(): HasMany
    {
        return $this->hasMany(InventoryTaskItem::class, 'task_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(InventoryTaskExecution::class, 'task_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(InventoryTaskEvidence::class, 'task_id');
    }

    public function requiredItems(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'inventory_task_items', 'task_id', 'item_id')
                    ->withPivot(['required_quantity', 'assigned_quantity', 'used_quantity', 'returned_quantity', 'status', 'notes'])
                    ->withTimestamps();
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeAssignedTo($query, $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    public function scopeCreatedBy($query, $userId)
    {
        return $query->where('created_by', $userId);
    }

    public function scopeByPriority($query, $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('task_type', $type);
    }

    public function scopeOverdue($query)
    {
        return $query->where('scheduled_end', '<', now())
                    ->whereNotIn('status', ['completed', 'cancelled']);
    }

    public function scopeRecurring($query)
    {
        return $query->where('is_recurring', true);
    }

    // Helper methods
    public function isOverdue(): bool
    {
        return $this->scheduled_end && $this->scheduled_end->isPast() &&
               !in_array($this->status, ['completed', 'cancelled']);
    }

    public function canBeStarted(): bool
    {
        return in_array($this->status, ['pending', 'paused']);
    }

    public function canBeCompleted(): bool
    {
        return $this->status === 'in_progress';
    }

    public function markAsStarted(): bool
    {
        if (!$this->canBeStarted()) {
            return false;
        }

        $this->update([
            'status' => 'in_progress',
            'actual_start' => now(),
            'progress_percentage' => 0,
        ]);

        return true;
    }

    public function markAsCompleted(string $notes = null): bool
    {
        if (!$this->canBeCompleted()) {
            return false;
        }

        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'progress_percentage' => 100,
            'completion_notes' => $notes,
        ]);

        return true;
    }

    public function updateProgress(int $percentage): bool
    {
        if ($percentage < 0 || $percentage > 100) {
            return false;
        }

        $this->update(['progress_percentage' => $percentage]);
        return true;
    }
}