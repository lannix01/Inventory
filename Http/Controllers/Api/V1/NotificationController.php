<?php

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\InventoryNotification;
use App\Modules\Inventory\Support\ApiResponder;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    use ApiResponder;

    /**
     * Get all notifications for the authenticated user
     */
    public function index(Request $request)
    {
        /** @var \App\Modules\Inventory\Models\InventoryUser $user */
        $user = $request->attributes->get('inventoryUser');

        if (!$user) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        $data = $request->validate([
            'type' => ['nullable', 'string'],
            'unread_only' => ['nullable', 'boolean', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = InventoryNotification::query()
            ->where('inventory_user_id', $user->id);

        if ($data['unread_only'] ?? false) {
            $query->unread();
        }

        if ($data['type'] ?? null) {
            $query->byType($data['type']);
        }

        $notifications = $query->latest()->paginate(20);

        return $this->successResponse([
            'notifications' => $notifications->items(),
            'unread_count' => InventoryNotification::query()
                ->where('inventory_user_id', $user->id)
                ->unread()
                ->count(),
        ], 'OK', 200, [
            'pagination' => $this->paginationMeta($notifications),
        ]);
    }

    /**
     * Mark a notification as read
     */
    public function markAsRead(Request $request, int $notificationId)
    {
        /** @var \App\Modules\Inventory\Models\InventoryUser $user */
        $user = $request->attributes->get('inventoryUser');

        if (!$user) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        $notification = InventoryNotification::query()
            ->where('inventory_user_id', $user->id)
            ->where('id', $notificationId)
            ->first();

        if (!$notification) {
            return $this->errorResponse('Notification not found.', 404);
        }

        $notification->markAsRead();

        return $this->successResponse([
            'notification' => $this->mapNotification($notification),
        ], 'Notification marked as read.');
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(Request $request)
    {
        /** @var \App\Modules\Inventory\Models\InventoryUser $user */
        $user = $request->attributes->get('inventoryUser');

        if (!$user) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        $count = InventoryNotification::query()
            ->where('inventory_user_id', $user->id)
            ->unread()
            ->update(['read_at' => now()]);

        return $this->successResponse([
            'marked_count' => $count,
        ], 'All notifications marked as read.');
    }

    /**
     * Delete a notification
     */
    public function delete(Request $request, int $notificationId)
    {
        /** @var \App\Modules\Inventory\Models\InventoryUser $user */
        $user = $request->attributes->get('inventoryUser');

        if (!$user) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        $notification = InventoryNotification::query()
            ->where('inventory_user_id', $user->id)
            ->where('id', $notificationId)
            ->first();

        if (!$notification) {
            return $this->errorResponse('Notification not found.', 404);
        }

        $notification->delete();

        return $this->successResponse([], 'Notification deleted.');
    }

    /**
     * Get unread count
     */
    public function unreadCount(Request $request)
    {
        /** @var \App\Modules\Inventory\Models\InventoryUser $user */
        $user = $request->attributes->get('inventoryUser');

        if (!$user) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        $count = InventoryNotification::query()
            ->where('inventory_user_id', $user->id)
            ->unread()
            ->count();

        return $this->successResponse([
            'unread_count' => $count,
        ]);
    }

    private function mapNotification(InventoryNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'title' => $notification->title,
            'message' => $notification->message,
            'type' => $notification->type,
            'source_type' => $notification->source_type,
            'source_id' => $notification->source_id,
            'metadata' => $notification->metadata,
            'read' => (bool) $notification->read_at,
            'read_at' => optional($notification->read_at)->toIso8601String(),
            'created_at' => optional($notification->created_at)->toIso8601String(),
        ];
    }
}
