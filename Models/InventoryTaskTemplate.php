<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Support\UsesInventoryConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryTaskTemplate extends Model
{
    use UsesInventoryConnection;

    protected $table = 'inventory_task_templates';

    protected $fillable = [
        'name',
        'description',
        'task_type',
        'default_priority',
        'estimated_duration_hours',
        'default_instructions',
        'default_metadata',
        'required_items',
        'is_active',
        'usage_count',
        'created_by',
        'category',
        'tags',
    ];

    protected $casts = [
        'default_metadata' => 'array',
        'required_items' => 'array',
        'is_active' => 'boolean',
        'usage_count' => 'integer',
        'estimated_duration_hours' => 'integer',
        'tags' => 'array',
    ];

    // Relationships
    public function creator(): BelongsTo
    {
        return $this->belongsTo(InventoryUser::class, 'created_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(InventoryTask::class, 'template_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('task_type', $type);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByCreator($query, $userId)
    {
        return $query->where('created_by', $userId);
    }

    public function scopeMostUsed($query, $limit = 10)
    {
        return $query->orderBy('usage_count', 'desc')->limit($limit);
    }

    public function scopeWithTag($query, $tag)
    {
        return $query->whereJsonContains('tags', $tag);
    }

    // Helper methods
    public function incrementUsage(): bool
    {
        return $this->increment('usage_count') > 0;
    }

    public function hasRequiredItems(): bool
    {
        return !empty($this->required_items) && is_array($this->required_items);
    }

    public function getRequiredItemsCount(): int
    {
        return $this->hasRequiredItems() ? count($this->required_items) : 0;
    }

    public function createTask(array $overrides = []): InventoryTask
    {
        $taskData = array_merge([
            'title' => $this->name,
            'description' => $this->description,
            'task_type' => $this->task_type,
            'priority' => $this->default_priority,
            'metadata' => $this->default_metadata,
        ], $overrides);

        $task = InventoryTask::create($taskData);

        // Create task items if required
        if ($this->hasRequiredItems()) {
            foreach ($this->required_items as $itemData) {
                InventoryTaskItem::create([
                    'task_id' => $task->id,
                    'item_id' => $itemData['item_id'],
                    'item_name' => $itemData['item_name'] ?? '',
                    'item_sku' => $itemData['item_sku'] ?? '',
                    'required_quantity' => $itemData['quantity'] ?? 1,
                ]);
            }
        }

        // Increment usage count
        $this->incrementUsage();

        return $task;
    }

    public function getEstimatedDurationFormatted(): string
    {
        if (!$this->estimated_duration_hours) {
            return 'Not specified';
        }

        if ($this->estimated_duration_hours < 1) {
            return round($this->estimated_duration_hours * 60) . ' minutes';
        }

        if ($this->estimated_duration_hours == 1) {
            return '1 hour';
        }

        return $this->estimated_duration_hours . ' hours';
    }

    // Task type constants
    const TYPE_INSTALLATION = 'installation';
    const TYPE_MAINTENANCE = 'maintenance';
    const TYPE_INSPECTION = 'inspection';
    const TYPE_REPAIR = 'repair';
    const TYPE_DELIVERY = 'delivery';
    const TYPE_COLLECTION = 'collection';
    const TYPE_SURVEY = 'survey';
    const TYPE_TRAINING = 'training';
    const TYPE_GENERAL = 'general';

    public static function getTaskTypes(): array
    {
        return [
            self::TYPE_INSTALLATION,
            self::TYPE_MAINTENANCE,
            self::TYPE_INSPECTION,
            self::TYPE_REPAIR,
            self::TYPE_DELIVERY,
            self::TYPE_COLLECTION,
            self::TYPE_SURVEY,
            self::TYPE_TRAINING,
            self::TYPE_GENERAL,
        ];
    }

    // Priority constants
    const PRIORITY_LOW = 'low';
    const PRIORITY_MEDIUM = 'medium';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_URGENT = 'urgent';

    public static function getPriorities(): array
    {
        return [
            self::PRIORITY_LOW,
            self::PRIORITY_MEDIUM,
            self::PRIORITY_HIGH,
            self::PRIORITY_URGENT,
        ];
    }

    // Category constants
    const CATEGORY_TECHNICAL = 'technical';
    const CATEGORY_ADMINISTRATIVE = 'administrative';
    const CATEGORY_FIELD_WORK = 'field_work';
    const CATEGORY_CUSTOMER_SERVICE = 'customer_service';
    const CATEGORY_MAINTENANCE = 'maintenance';

    public static function getCategories(): array
    {
        return [
            self::CATEGORY_TECHNICAL,
            self::CATEGORY_ADMINISTRATIVE,
            self::CATEGORY_FIELD_WORK,
            self::CATEGORY_CUSTOMER_SERVICE,
            self::CATEGORY_MAINTENANCE,
        ];
    }
}