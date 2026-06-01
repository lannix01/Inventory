<?php

namespace App\Modules\Inventory\Http\Middleware;

use App\Modules\Inventory\Models\InventoryApiToken;
use App\Modules\Inventory\Support\ApiEnvelope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InventoryApiAuth
{
    public function handle(Request $request, Closure $next)
    {
        $plainToken = (string) $request->bearerToken();
        if ($plainToken === '') {
            return $this->unauthenticatedResponse($request);
        }

        $hashedToken = hash('sha256', $plainToken);

        $apiToken = InventoryApiToken::query()
            ->with('user')
            ->where('token', $hashedToken)
            ->active()
            ->first();

        if (!$apiToken || !$apiToken->user || !$apiToken->user->inventory_enabled) {
            return $this->unauthenticatedResponse($request);
        }

        $idleMinutes = (int) config('inventory.api.token_idle_ttl_minutes', 0);
        if ($idleMinutes > 0 && $apiToken->last_used_at && $apiToken->last_used_at->lt(now()->subMinutes($idleMinutes))) {
            $this->revokeToken($apiToken);
            return $this->unauthenticatedResponse($request);
        }

        $updates = [
            'last_used_at' => now(),
        ];

        if (InventoryApiToken::supportsColumn('last_ip')) {
            $updates['last_ip'] = $request->ip();
        }
        if (InventoryApiToken::supportsColumn('last_user_agent')) {
            $updates['last_user_agent'] = substr((string) $request->userAgent(), 0, 255);
        }

        $refreshOnUse = (bool) config('inventory.api.token_refresh_on_use', false);
        $ttlDays = (int) config('inventory.api.token_ttl_days', 30);
        if ($refreshOnUse && $ttlDays > 0) {
            $updates['expires_at'] = now()->addDays($ttlDays);
        }

        $apiToken->forceFill($updates)->save();

        $request->attributes->set('inventoryUser', $apiToken->user);
        $request->attributes->set('inventoryApiToken', $apiToken);

        Auth::shouldUse('inventory');
        Auth::guard('inventory')->setUser($apiToken->user);
        $request->setUserResolver(fn () => $apiToken->user);

        return $next($request);
    }

    private function unauthenticatedResponse(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Unauthenticated.',
            'errors' => (object) [],
            'meta' => (object) ApiEnvelope::meta($request),
        ], 401);
    }

    private function revokeToken(InventoryApiToken $token): void
    {
        if (InventoryApiToken::supportsColumn('revoked_at')) {
            $token->forceFill(['revoked_at' => now()])->save();
            return;
        }

        $token->delete();
    }
}
