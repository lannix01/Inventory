<?php

namespace App\Modules\Inventory\Http\Controllers\Inventory;

use App\Modules\Inventory\Models\InventoryLog;
use App\Modules\Inventory\Models\InventoryTeam;
use App\Modules\Inventory\Models\InventoryTeamItemAssignment;
use App\Modules\Inventory\Support\ApiResponder;
use App\Modules\Inventory\Support\InventoryDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TeamDeploymentController extends Controller
{
    use ApiResponder;

    public function index()
    {
        $teams = InventoryTeam::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $teamAssignments = InventoryTeamItemAssignment::query()
            ->with(['team'])
            ->where('is_active', true)
            ->get()
            ->groupBy('team_id')
            ->map(function ($rows) {
                return $rows->map(function ($a) {
                    // Fetch item name via DB to avoid relying on model FK
                    $item = InventoryDatabase::table('inventory_items')->where('id', $a->item_id)->first();
                    return [
                        'item_id' => (int)$a->item_id,
                        'item_name' => $item?->name ?? ('Item #' . $a->item_id),
                        'available' => $a->availableToDeploy(),
                    ];
                })->values();
            });

        if (request()->expectsJson()) {
            return $this->successResponse([
                'teams' => $teams,
                'team_assignments' => $teamAssignments,
            ]);
        }

        return view('inventory::team_deployments.index', compact('teams', 'teamAssignments'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'team_id' => ['required','exists:inventory_teams,id'],
            'item_id' => ['required'],
            'qty' => ['required','integer','min:1'],
            'site_name' => ['required','string','max:255'],
            'site_code' => ['nullable','string','max:100'],
            'reference' => ['nullable','string','max:255'],
            'notes' => ['nullable','string'],
        ]);

        InventoryDatabase::transaction(function () use ($data) {
            $userId = auth()->id();

            $assignment = InventoryTeamItemAssignment::lockForUpdate()
                ->where('team_id', $data['team_id'])
                ->where('item_id', (int)$data['item_id'])
                ->where('is_active', true)
                ->firstOrFail();

            if ((int)$data['qty'] > $assignment->availableToDeploy()) {
                abort(422, 'Not enough available qty in team assignment to deploy.');
            }

            $assignment->qty_deployed += (int)$data['qty'];
            $assignment->save();

            // record deployment in inventory_item_deployments table (team_id + technician_id as "who deployed")
            InventoryDatabase::table('inventory_item_deployments')->insert([
                'technician_id' => $userId, // deployer (admin or tech)
                'team_id' => (int)$data['team_id'],
                'item_id' => (int)$data['item_id'],
                'qty' => (int)$data['qty'],
                'site_code' => $data['site_code'] ?? null,
                'site_name' => $data['site_name'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Log deployed with team_id
            InventoryLog::create([
                'action' => 'deployed',
                'item_id' => (int)$data['item_id'],
                'item_unit_id' => null,
                'qty' => (int)$data['qty'],
                'serial_no' => null,
                'from_user_id' => $userId,
                'to_user_id' => null,
                'team_id' => (int)$data['team_id'],
                'site_code' => $data['site_code'] ?? null,
                'site_name' => $data['site_name'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? 'Deployed from team stock',
                'created_by' => $userId,
            ]);
        });

        if ($request->expectsJson()) {
            return $this->successResponse([], 'Team deployment saved (team allocation reduced + logs written).', 201);
        }

        return back()->with('success', 'Team deployment saved (team allocation reduced + logs written).');
    }
}
