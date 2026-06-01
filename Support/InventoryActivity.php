<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\InventoryActivityLog;
use App\Modules\Inventory\Models\InventoryUser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InventoryActivity
{
    /**
     * @param array<string,mixed> $metadata
     */
    public static function log(?InventoryUser $user, string $action, ?Request $request = null, array $metadata = []): void
    {
        if (!$user) {
            return;
        }

        if (!InventoryDatabase::schema()->hasTable('inventory_activity_logs')) {
            return;
        }

        $request = $request ?: request();
        $route = $request?->route();
        $routeName = $route?->getName();

        try {
            InventoryActivityLog::create([
                'inventory_user_id' => $user->id,
                'action' => $action,
                'route_name' => $routeName ? (string) $routeName : null,
                'method' => $request?->method() ? strtoupper((string) $request->method()) : null,
                'url' => $request?->fullUrl() ? (string) $request->fullUrl() : null,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent() ? Str::limit((string) $request->userAgent(), 255, '') : null,
                'metadata' => $metadata ?: null,
            ]);
        } catch (\Throwable) {
            // Activity logging must never break the main request.
        }
    }
}
