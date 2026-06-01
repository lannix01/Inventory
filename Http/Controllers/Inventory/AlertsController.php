<?php

namespace App\Modules\Inventory\Http\Controllers\Inventory;

use App\Modules\Inventory\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\ApiResponder;

class AlertsController extends Controller
{
    use ApiResponder;

    public function lowStock(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        $group = trim((string) $request->get('group', ''));

        $items = Item::query()
            ->with(['group'])
            ->where('is_active', 1)
            ->whereColumn('qty_on_hand', '<=', 'reorder_level')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($qq) use ($q) {
                    $qq->where('name', 'like', "%{$q}%")
                       ->orWhere('sku', 'like', "%{$q}%");
                });
            })
            ->when($group !== '', function ($query) use ($group) {
                // group filter supports name match
                $query->whereHas('group', function ($gq) use ($group) {
                    $gq->where('name', 'like', "%{$group}%")
                       ->orWhere('code', 'like', "%{$group}%");
                });
            })
            ->orderBy('qty_on_hand', 'asc')
            ->paginate(20)
            ->withQueryString();

        if ($request->expectsJson()) {
            return $this->successResponse([
                'items' => $items->items(),
                'query' => [
                    'q' => $q,
                    'group' => $group,
                ],
            ], 'OK', 200, [
                'pagination' => $this->paginationMeta($items),
            ]);
        }

        return view('inventory::alerts.low_stock', [
            'items' => $items,
            'q' => $q,
            'group' => $group,
        ]);
    }
}
