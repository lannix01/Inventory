<?php

namespace App\Modules\Inventory\Http\Controllers\Inventory;

use App\Modules\Inventory\Support\ApiResponder;
use App\Modules\Inventory\Support\InventoryDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class ItemController extends Controller
{
    use ApiResponder;

    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        $items = InventoryDatabase::table('inventory_items as i')
            ->leftJoin('inventory_item_groups as g', 'g.id', '=', 'i.item_group_id')
            ->select([
                'i.*',
                DB::raw('g.name as group_name'),
                DB::raw('g.code as group_code'),
            ])
            ->when($q !== '', function ($query) use ($q) {
                // IMPORTANT: group the OR conditions to avoid breaking other WHERE clauses later
                $query->where(function ($sub) use ($q) {
                    $sub->where('i.name', 'like', "%{$q}%")
                        ->orWhere('i.sku', 'like', "%{$q}%");
                });
            })
            ->orderByDesc('i.id')
            ->paginate(20)
            ->withQueryString();

        if ($request->expectsJson()) {
            return $this->successResponse([
                'items' => $items->items(),
                'query' => ['q' => $q],
            ], 'OK', 200, [
                'pagination' => $this->paginationMeta($items),
            ]);
        }

        return view('inventory::items.index', compact('items', 'q'));
    }

    public function create()
    {
        $groupsQuery = InventoryDatabase::table('inventory_item_groups')->orderBy('name');

        // Only filter by is_active if the column exists (your table may not have it)
        if (InventoryDatabase::schema()->hasColumn('inventory_item_groups', 'is_active')) {
            $groupsQuery->where('is_active', 1);
        }

        $groups = $groupsQuery->get();

        if (request()->expectsJson()) {
            return $this->successResponse([
                'groups' => $groups,
            ]);
        }

        return view('inventory::items.create', compact('groups'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'item_group_id' => ['required', 'integer', 'exists:inventory_item_groups,id'],
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100'],
            'unit' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'has_serial' => ['nullable', 'boolean'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'qty_on_hand' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        InventoryDatabase::table('inventory_items')->insert([
            'item_group_id' => (int) $data['item_group_id'],
            'name' => $data['name'],
            'sku' => $data['sku'] ?? null,
            'unit' => $data['unit'] ?? 'pcs',
            'description' => $data['description'] ?? null,
            'has_serial' => (bool) ($data['has_serial'] ?? false),
            'reorder_level' => (int) ($data['reorder_level'] ?? 0),
            'qty_on_hand' => (int) ($data['qty_on_hand'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($request->expectsJson()) {
            return $this->successResponse([], 'Item created.', 201);
        }

        return redirect()->route('inventory.items.index')->with('success', 'Item created.');
    }

    public function edit($id)
    {
        $item = InventoryDatabase::table('inventory_items')->where('id', $id)->first();
        abort_if(!$item, 404);

        $groupsQuery = InventoryDatabase::table('inventory_item_groups')->orderBy('name');

        // Only filter by is_active if the column exists
        if (InventoryDatabase::schema()->hasColumn('inventory_item_groups', 'is_active')) {
            $groupsQuery->where('is_active', 1);
        }

        $groups = $groupsQuery->get();

        if (request()->expectsJson()) {
            return $this->successResponse([
                'item' => $item,
                'groups' => $groups,
            ]);
        }

        return view('inventory::items.edit', compact('item', 'groups'));
    }

    public function update(Request $request, $id)
    {
        $item = InventoryDatabase::table('inventory_items')->where('id', $id)->first();
        abort_if(!$item, 404);

        $data = $request->validate([
            'item_group_id' => ['required', 'integer', 'exists:inventory_item_groups,id'],
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100'],
            'unit' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'has_serial' => ['nullable', 'boolean'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        InventoryDatabase::table('inventory_items')->where('id', $id)->update([
            'item_group_id' => (int) $data['item_group_id'],
            'name' => $data['name'],
            'sku' => $data['sku'] ?? null,
            'unit' => $data['unit'] ?? 'pcs',
            'description' => $data['description'] ?? null,
            'has_serial' => (bool) ($data['has_serial'] ?? false),
            'reorder_level' => (int) ($data['reorder_level'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'updated_at' => now(),
        ]);

        if ($request->expectsJson()) {
            return $this->successResponse([], 'Item updated.');
        }

        return back()->with('success', 'Item updated.');
    }

    public function destroy($id)
    {
        InventoryDatabase::table('inventory_items')->where('id', $id)->delete();

        if (request()->expectsJson()) {
            return $this->successResponse([], 'Item deleted.');
        }

        return back()->with('success', 'Item deleted.');
    }

    /**
     * NOTE:
     * You already have AlertsController::lowStock() mounted at inventory/alerts/low-stock.
     * This method is harmless if unused, but keep it only if you still call it somewhere.
     */
    public function lowStock()
    {
        $items = InventoryDatabase::table('inventory_items as i')
            ->leftJoin('inventory_item_groups as g', 'g.id', '=', 'i.item_group_id')
            ->select([
                'i.*',
                DB::raw('g.name as group_name'),
                DB::raw('g.code as group_code'),
            ])
            ->where('i.is_active', 1)
            ->whereColumn('i.qty_on_hand', '<=', 'i.reorder_level')
            ->orderBy('i.name')
            ->paginate(50);

        if (request()->expectsJson()) {
            return $this->successResponse([
                'items' => $items->items(),
            ], 'OK', 200, [
                'pagination' => $this->paginationMeta($items),
            ]);
        }

        return view('inventory::alerts.low_stock', compact('items'));
    }
}
