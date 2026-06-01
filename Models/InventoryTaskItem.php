<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Support\UsesInventoryConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTaskItem extends Model
{
    use UsesInventoryConnection;

    protected $table = 'inventory_task_items';

    protected $fillable = [
        'task_id',
        'item_id',
        'item_name',
        'item_sku',
        'required_quantity',
        'assigned_quantity',
        'used_quantity',
        'returned_quantity',
        'status',
        'notes',
    ];

    protected $casts = [
        'required_quantity' => 'integer',
        'assigned_quantity' => 'integer',
        'used_quantity' => 'integer',
        'returned_quantity' => 'integer',
    ];

    // Relationships
    public function task(): BelongsTo
    {
        return $this->belongsTo(InventoryTask::class, 'task_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeAssigned($query)
    {
        return $query->where('status', 'assigned');
    }

    public function scopeInUse($query)
    {
        return $query->where('status', 'in_use');
    }

    public function scopeReturned($query)
    {
        return $query->where('status', 'returned');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeByTask($query, $taskId)
    {
        return $query->where('task_id', $taskId);
    }

    public function scopeByItem($query, $itemId)
    {
        return $query->where('item_id', $itemId);
    }

    // Helper methods
    public function isFullyAssigned(): bool
    {
        return $this->assigned_quantity >= $this->required_quantity;
    }

    public function isFullyUsed(): bool
    {
        return $this->used_quantity >= $this->required_quantity;
    }

    public function isFullyReturned(): bool
    {
        return $this->returned_quantity >= $this->assigned_quantity;
    }

    public function getRemainingToAssign(): int
    {
        return max(0, $this->required_quantity - $this->assigned_quantity);
    }

    public function getRemainingToUse(): int
    {
        return max(0, $this->assigned_quantity - $this->used_quantity);
    }

    public function getRemainingToReturn(): int
    {
        return max(0, $this->used_quantity - $this->returned_quantity);
    }

    public function assignQuantity(int $quantity): bool
    {
        if ($quantity <= 0 || $this->assigned_quantity + $quantity > $this->required_quantity) {
            return false;
        }

        $this->increment('assigned_quantity', $quantity);

        if ($this->isFullyAssigned()) {
            $this->update(['status' => 'assigned']);
        }

        return true;
    }

    public function useQuantity(int $quantity): bool
    {
        if ($quantity <= 0 || $this->used_quantity + $quantity > $this->assigned_quantity) {
            return false;
        }

        $this->increment('used_quantity', $quantity);

        if ($this->used_quantity > 0) {
            $this->update(['status' => 'in_use']);
        }

        return true;
    }

    public function returnQuantity(int $quantity): bool
    {
        if ($quantity <= 0 || $this->returned_quantity + $quantity > $this->used_quantity) {
            return false;
        }

        $this->increment('returned_quantity', $quantity);

        if ($this->isFullyReturned()) {
            $this->update(['status' => 'returned']);
        }

        return true;
    }
}