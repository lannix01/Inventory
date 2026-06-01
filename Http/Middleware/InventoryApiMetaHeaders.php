<?php

namespace App\Modules\Inventory\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InventoryApiMetaHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');
        $requestId = trim((string) $request->headers->get('X-Request-Id', ''));
        if ($requestId === '') {
            $requestId = (string) Str::uuid();
        }
        $apiVersion = (string) config('inventory.api.version', 'v1');

        $request->attributes->set('inventory_request_id', $requestId);
        $request->attributes->set('inventory_api_version', $apiVersion);

        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('X-Inventory-Api-Version', $apiVersion);

        $deprecated = (bool) config('inventory.api.deprecated', false);
        if ($deprecated) {
            $response->headers->set('Deprecation', 'true');
        }

        $sunsetAt = trim((string) config('inventory.api.sunset_at', ''));
        if ($sunsetAt !== '') {
            $response->headers->set('Sunset', $sunsetAt);
        }

        return $response;
    }
}
