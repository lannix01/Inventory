<?php

namespace App\Modules\Inventory\Http\Controllers\Inventory;

use App\Modules\Inventory\Http\Controllers\Controller;
use App\Modules\Inventory\Models\ItemGroup;
use App\Modules\Inventory\Support\ApiResponder;
use Illuminate\Http\Request;

class ItemGroupController extends Controller
{
    use ApiResponder;

    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        $groups = ItemGroup::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('name', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%")
                        ->orWhere('description', 'like', "%{$q}%");
                });
            })
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        if ($request->expectsJson()) {
            return $this->successResponse([
                'groups' => $groups->items(),
                'query' => ['q' => $q],
            ], 'OK', 200, [
                'pagination' => $this->paginationMeta($groups),
            ]);
        }

        return view('inventory::item_groups.index', compact('groups', 'q'));
    }

    public function create()
    {
        if (request()->expectsJson()) {
            return $this->successResponse([
                'defaults' => [
                    'is_active' => true,
                ],
            ]);
        }

        return view('inventory::item_groups.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required','string','max:255'],
            'code' => ['nullable','string','max:100'],
            'description' => ['nullable','string'],
            'is_active' => ['nullable','boolean'],
        ]);

        ItemGroup::create([
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'description' => $data['description'] ?? null,
            'is_active' => (bool)($data['is_active'] ?? true),
        ]);

        if ($request->expectsJson()) {
            return $this->successResponse([], 'Group created.', 201);
        }

        return redirect()
            ->route('inventory.item-groups.index')
            ->with('success', 'Group created.');
    }

    public function edit(ItemGroup $item_group)
    {
        // NOTE: route param name is {item_group} from resource()
        $group = $item_group;

        if (request()->expectsJson()) {
            return $this->successResponse([
                'group' => $group,
            ]);
        }

        return view('inventory::item_groups.edit', compact('group'));
    }

    public function update(Request $request, ItemGroup $item_group)
    {
        $data = $request->validate([
            'name' => ['required','string','max:255'],
            'code' => ['nullable','string','max:100'],
            'description' => ['nullable','string'],
            'is_active' => ['nullable','boolean'],
        ]);

        $item_group->update([
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'description' => $data['description'] ?? null,
            'is_active' => (bool)($data['is_active'] ?? true),
        ]);

        if ($request->expectsJson()) {
            return $this->successResponse([], 'Group updated.');
        }

        return back()->with('success', 'Group updated.');
    }

    public function destroy(ItemGroup $item_group)
    {
        $item_group->delete();

        if (request()->expectsJson()) {
            return $this->successResponse([], 'Group deleted.');
        }

        return back()->with('success', 'Group deleted.');
    }
}
