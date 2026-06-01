<?php

namespace App\Modules\Inventory\Http\Controllers\Inventory;

use App\Modules\Inventory\Models\InventoryLog;
use App\Modules\Inventory\Models\InventoryTeam;
use App\Modules\Inventory\Models\InventoryTeamItemAssignment;
use App\Modules\Inventory\Support\ApiResponder;
use App\Modules\Inventory\Support\InventoryDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Modules\Inventory\Models\InventoryItem; // if you don't have this model, swap to DB::table('inventory_items')

class TeamAssignmentController extends Controller
{
    use ApiResponder;

    public function index()
    {
        $teams = InventoryTeam::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        // If you don't have InventoryItem model, comment this and use DB query in view
        $items = class_exists(\App\Modules\Inventory\Models\InventoryItem::class)
            ? \App\Modules\Inventory\Models\InventoryItem::query()->where('is_active', true)->orderBy('name')->get()
            : collect(InventoryDatabase::table('inventory_items')->where('is_active', 1)->orderBy('name')->get());

        $assignments = InventoryTeamItemAssignment::query()
            ->with(['team', 'assigner'])
            ->latest()
            ->paginate(30);

        if (request()->expectsJson()) {
            return $this->successResponse([
                'teams' => $teams,
                'items' => $items,
                'assignments' => $assignments->items(),
            ], 'OK', 200, [
                'pagination' => $this->paginationMeta($assignments),
            ]);
        }

        return view('inventory::team_assignments.index', compact('teams', 'items', 'assignments'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'team_id' => ['required','exists:inventory_teams,id'],
            'item_id' => ['required'],
            'qty_allocated' => ['required','integer','min:1'],
            'reference' => ['nullable','string','max:255'],
            'notes' => ['nullable','string'],
        ]);

        InventoryDatabase::transaction(function () use ($data) {
            $adminId = auth()->id();

            // store qty reduces (inventory_items exists; we don't assume FK)
            $itemRow = InventoryDatabase::table('inventory_items')->lockForUpdate()->where('id', $data['item_id'])->first();
            if (!$itemRow) abort(422, 'Invalid item_id');

            if ((int)$data['qty_allocated'] > (int)$itemRow->qty_on_hand) {
                abort(422, 'Allocated qty cannot exceed store qty_on_hand.');
            }

            InventoryDatabase::table('inventory_items')->where('id', $data['item_id'])->decrement('qty_on_hand', (int)$data['qty_allocated']);

            $assignment = InventoryTeamItemAssignment::lockForUpdate()->firstOrCreate(
                ['team_id' => $data['team_id'], 'item_id' => (int)$data['item_id']],
                [
                    'qty_allocated' => 0,
                    'qty_deployed' => 0,
                    'assigned_by' => $adminId,
                    'assigned_at' => now(),
                    'is_active' => true,
                ]
            );

            $assignment->qty_allocated += (int)$data['qty_allocated'];
            $assignment->assigned_by = $adminId;
            $assignment->assigned_at = now();
            $assignment->is_active = true;
            $assignment->save();

            // Log as "assigned" with team_id
            InventoryLog::create([
                'action' => 'assigned',
                'item_id' => (int)$data['item_id'],
                'item_unit_id' => null,
                'qty' => (int)$data['qty_allocated'],
                'serial_no' => null,
                'from_user_id' => $adminId,
                'to_user_id' => null,
                'team_id' => (int)$data['team_id'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? 'Assigned to team',
                'created_by' => $adminId,
            ]);
        });

        if ($request->expectsJson()) {
            return $this->successResponse([], 'Team assignment saved (store reduced + log written).', 201);
        }

        return back()->with('success', 'Team assignment saved (store reduced + log written).');
    }
}
