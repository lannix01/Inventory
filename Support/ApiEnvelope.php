<?php

namespace App\Modules\Inventory\Support;

use Illuminate\Http\Request;

class ApiEnvelope
{
    public static function meta(?Request $request = null, array $extra = []): array
    {
        $request = $request ?: request();

        $meta = [
            'request_id' => (string) ($request?->attributes->get('inventory_request_id') ?: $request?->headers->get('X-Request-Id', '')),
            'api_version' => (string) ($request?->attributes->get('inventory_api_version') ?: config('inventory.api.version', 'v1')),
            'timestamp' => now()->toIso8601String(),
        ];

        if ($meta['request_id'] === '') {
            unset($meta['request_id']);
        }

        if (empty($extra)) {
            return $meta;
        }

        return array_replace_recursive($meta, $extra);
    }
}
