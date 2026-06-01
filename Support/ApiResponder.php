<?php

namespace App\Modules\Inventory\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

trait ApiResponder
{
    protected function successResponse(
        array $data = [],
        string $message = 'OK',
        int $status = 200,
        array $meta = []
    ) {
        $meta = ApiEnvelope::meta(request(), $meta);

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => (object) $meta,
        ], $status);
    }

    protected function errorResponse(
        string $message,
        int $status = 400,
        array $errors = [],
        array $meta = []
    ) {
        $meta = ApiEnvelope::meta(request(), $meta);

        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => (object) $errors,
            'meta' => (object) $meta,
        ], $status);
    }

    protected function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'has_more_pages' => $paginator->hasMorePages(),
        ];
    }
}
