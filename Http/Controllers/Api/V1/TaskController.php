<?php

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Modules\Inventory\Http\Controllers\Controller;
use App\Modules\Inventory\Models\InventoryTask;
use App\Modules\Inventory\Models\InventoryTaskItem;
use App\Modules\Inventory\Models\InventoryTaskExecution;
use App\Modules\Inventory\Models\InventoryTaskEvidence;
use App\Modules\Inventory\Models\InventoryTaskTemplate;
use App\Modules\Inventory\Models\InventoryUser;
use App\Modules\Inventory\Support\ApiResponder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    use ApiResponder;

    /**
     * Display a listing of tasks.
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => ['sometimes', Rule::in(['pending', 'in_progress', 'paused', 'completed', 'cancelled'])],
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'assigned_to' => ['sometimes', 'integer', 'exists:inventory_users,id'],
            'created_by' => ['sometimes', 'integer', 'exists:inventory_users,id'],
            'task_type' => ['sometimes', 'string'],
            'overdue' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        $query = InventoryTask::with(['assignedUser', 'creator']);

        // Apply filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('priority')) {
            $query->where('priority', $request->priority);
        }

        if ($request->has('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }

        if ($request->has('created_by')) {
            $query->where('created_by', $request->created_by);
        }

        if ($request->has('task_type')) {
            $query->where('task_type', $request->task_type);
        }

        if ($request->boolean('overdue')) {
            $query->overdue();
        }

        // Sort by priority and creation date
        $query->orderByRaw("FIELD(priority, 'urgent', 'high', 'medium', 'low')")
              ->orderBy('created_at', 'desc');

        $perPage = $request->get('per_page', 15);
        $tasks = $query->paginate($perPage);

        return $this->success('Tasks retrieved successfully', $tasks);
    }

    /**
     * Store a newly created task.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['required', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'assigned_to' => ['required', 'integer', 'exists:inventory_users,id'],
            'scheduled_start' => ['nullable', 'date'],
            'scheduled_end' => ['nullable', 'date', 'after:scheduled_start'],
            'location' => ['nullable', 'string', 'max:255'],
            'task_type' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'required_items' => ['nullable', 'array'],
            'required_items.*.item_id' => ['required_with:required_items', 'integer', 'exists:inventory_items,id'],
            'required_items.*.quantity' => ['required_with:required_items', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            DB::beginTransaction();

            $task = InventoryTask::create([
                'title' => $request->title,
                'description' => $request->description,
                'status' => 'pending',
                'priority' => $request->priority,
                'assigned_to' => $request->assigned_to,
                'created_by' => auth()->id(),
                'scheduled_start' => $request->scheduled_start,
                'scheduled_end' => $request->scheduled_end,
                'location' => $request->location,
                'task_type' => $request->task_type ?? 'general',
                'metadata' => $request->metadata,
            ]);

            // Create task items if provided
            if ($request->has('required_items') && is_array($request->required_items)) {
                foreach ($request->required_items as $itemData) {
                    // Get item details
                    $item = DB::table('inventory_items')->find($itemData['item_id']);

                    InventoryTaskItem::create([
                        'task_id' => $task->id,
                        'item_id' => $itemData['item_id'],
                        'item_name' => $item->name ?? '',
                        'item_sku' => $item->sku ?? '',
                        'required_quantity' => $itemData['quantity'],
                    ]);
                }
            }

            // Log task creation
            InventoryTaskExecution::logAction(
                $task->id,
                auth()->id(),
                'created',
                'Task created and assigned'
            );

            DB::commit();

            $task->load(['assignedUser', 'creator', 'taskItems']);

            return $this->success('Task created successfully', $task, 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to create task: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified task.
     */
    public function show(InventoryTask $task): JsonResponse
    {
        $task->load([
            'assignedUser',
            'creator',
            'taskItems.item',
            'executions.user',
            'evidence.uploader',
            'subtasks'
        ]);

        return $this->success('Task retrieved successfully', $task);
    }

    /**
     * Update the specified task.
     */
    public function update(Request $request, InventoryTask $task): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'assigned_to' => ['sometimes', 'integer', 'exists:inventory_users,id'],
            'scheduled_start' => ['nullable', 'date'],
            'scheduled_end' => ['nullable', 'date', 'after:scheduled_start'],
            'location' => ['nullable', 'string', 'max:255'],
            'task_type' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
            'status' => ['sometimes', Rule::in(['pending', 'in_progress', 'paused', 'completed', 'cancelled'])],
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            $oldAssignedTo = $task->assigned_to;
            $updates = $request->only([
                'title', 'description', 'priority', 'assigned_to',
                'scheduled_start', 'scheduled_end', 'location',
                'task_type', 'metadata', 'status'
            ]);

            $task->update($updates);

            // Log assignment change
            if (isset($updates['assigned_to']) && $updates['assigned_to'] != $oldAssignedTo) {
                InventoryTaskExecution::logAction(
                    $task->id,
                    auth()->id(),
                    'reassigned',
                    "Task reassigned from user {$oldAssignedTo} to {$updates['assigned_to']}"
                );
            }

            $task->load(['assignedUser', 'creator']);

            return $this->success('Task updated successfully', $task);

        } catch (\Exception $e) {
            return $this->error('Failed to update task: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove the specified task.
     */
    public function destroy(InventoryTask $task): JsonResponse
    {
        try {
            // Check if task can be deleted (not in progress or completed)
            if (in_array($task->status, ['in_progress', 'completed'])) {
                return $this->error('Cannot delete task that is in progress or completed', 422);
            }

            $task->delete();

            return $this->success('Task deleted successfully');

        } catch (\Exception $e) {
            return $this->error('Failed to delete task: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Start a task.
     */
    public function start(Request $request, InventoryTask $task): JsonResponse
    {
        if (!$task->canBeStarted()) {
            return $this->error('Task cannot be started', 422);
        }

        try {
            $task->markAsStarted();

            InventoryTaskExecution::logAction(
                $task->id,
                auth()->id(),
                'started',
                'Task started',
                null,
                $request->latitude,
                $request->longitude
            );

            return $this->success('Task started successfully', $task);

        } catch (\Exception $e) {
            return $this->error('Failed to start task: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Pause a task.
     */
    public function pause(Request $request, InventoryTask $task): JsonResponse
    {
        if ($task->status !== 'in_progress') {
            return $this->error('Task is not in progress', 422);
        }

        try {
            $task->update(['status' => 'paused']);

            InventoryTaskExecution::logAction(
                $task->id,
                auth()->id(),
                'paused',
                'Task paused',
                null,
                $request->latitude,
                $request->longitude
            );

            return $this->success('Task paused successfully', $task);

        } catch (\Exception $e) {
            return $this->error('Failed to pause task: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Resume a task.
     */
    public function resume(Request $request, InventoryTask $task): JsonResponse
    {
        if ($task->status !== 'paused') {
            return $this->error('Task is not paused', 422);
        }

        try {
            $task->update(['status' => 'in_progress']);

            InventoryTaskExecution::logAction(
                $task->id,
                auth()->id(),
                'resumed',
                'Task resumed',
                null,
                $request->latitude,
                $request->longitude
            );

            return $this->success('Task resumed successfully', $task);

        } catch (\Exception $e) {
            return $this->error('Failed to resume task: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Complete a task.
     */
    public function complete(Request $request, InventoryTask $task): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'completion_notes' => ['nullable', 'string'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        if (!$task->canBeCompleted()) {
            return $this->error('Task cannot be completed', 422);
        }

        try {
            $task->markAsCompleted($request->completion_notes);

            InventoryTaskExecution::logAction(
                $task->id,
                auth()->id(),
                'completed',
                'Task completed',
                ['completion_notes' => $request->completion_notes],
                $request->latitude,
                $request->longitude
            );

            return $this->success('Task completed successfully', $task);

        } catch (\Exception $e) {
            return $this->error('Failed to complete task: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get tasks assigned to current user.
     */
    public function myTasks(Request $request): JsonResponse
    {
        $query = InventoryTask::where('assigned_to', auth()->id())
            ->with(['creator', 'taskItems.item']);

        // Apply status filter
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Sort by priority and due date
        $query->orderByRaw("FIELD(priority, 'urgent', 'high', 'medium', 'low')")
              ->orderBy('scheduled_end', 'asc')
              ->orderBy('created_at', 'desc');

        $tasks = $query->paginate(20);

        return $this->success('My tasks retrieved successfully', $tasks);
    }

    /**
     * Get task statistics.
     */
    public function statistics(Request $request): JsonResponse
    {
        $userId = $request->get('user_id') ?: auth()->id();

        $stats = [
            'total_tasks' => InventoryTask::where('assigned_to', $userId)->count(),
            'pending_tasks' => InventoryTask::where('assigned_to', $userId)->where('status', 'pending')->count(),
            'in_progress_tasks' => InventoryTask::where('assigned_to', $userId)->where('status', 'in_progress')->count(),
            'completed_tasks' => InventoryTask::where('assigned_to', $userId)->where('status', 'completed')->count(),
            'overdue_tasks' => InventoryTask::where('assigned_to', $userId)->overdue()->count(),
            'completion_rate' => 0,
        ];

        if ($stats['total_tasks'] > 0) {
            $stats['completion_rate'] = round(($stats['completed_tasks'] / $stats['total_tasks']) * 100, 2);
        }

        return $this->success('Task statistics retrieved successfully', $stats);
    }

    /**
     * Create task from template.
     */
    public function createFromTemplate(Request $request, InventoryTaskTemplate $template): JsonResponse
    {
        if (!$template->is_active) {
            return $this->error('Template is not active', 422);
        }

        $validator = Validator::make($request->all(), [
            'assigned_to' => ['required', 'integer', 'exists:inventory_users,id'],
            'scheduled_start' => ['nullable', 'date'],
            'scheduled_end' => ['nullable', 'date', 'after:scheduled_start'],
            'location' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 422, $validator->errors());
        }

        try {
            DB::beginTransaction();

            $task = $template->createTask([
                'assigned_to' => $request->assigned_to,
                'created_by' => auth()->id(),
                'scheduled_start' => $request->scheduled_start,
                'scheduled_end' => $request->scheduled_end,
                'location' => $request->location,
                'metadata' => array_merge($template->default_metadata ?? [], $request->metadata ?? []),
            ]);

            DB::commit();

            $task->load(['assignedUser', 'creator', 'taskItems']);

            return $this->success('Task created from template successfully', $task, 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error('Failed to create task from template: ' . $e->getMessage(), 500);
        }
    }
}