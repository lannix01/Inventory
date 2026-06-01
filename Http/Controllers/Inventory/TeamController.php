<?php

namespace App\Modules\Inventory\Http\Controllers\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\InventoryTeam;
use App\Modules\Inventory\Models\InventoryTeamMember;
use App\Modules\Inventory\Support\ApiResponder;
use App\Modules\Inventory\Support\InventoryDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TeamController extends Controller
{
    use ApiResponder;

    public function index(Request $request)
    {
        $q = trim((string)$request->get('q', ''));
        $status = $request->get('status', 'active'); // active | all | inactive

        $teams = InventoryTeam::query()
            ->with(['creator'])
            ->when($status === 'active', fn($qq) => $qq->where('is_active', true))
            ->when($status === 'inactive', fn($qq) => $qq->where('is_active', false))
            ->when($q !== '', function ($qq) use ($q) {
                $qq->where(function ($sub) use ($q) {
                    $sub->where('name', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%");
                });
            })
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        if ($request->expectsJson()) {
            return $this->successResponse([
                'teams' => $teams->items(),
                'query' => [
                    'q' => $q,
                    'status' => $status,
                ],
            ], 'OK', 200, [
                'pagination' => $this->paginationMeta($teams),
            ]);
        }

        return view('inventory::teams.index', compact('teams', 'q', 'status'));
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

        return view('inventory::teams.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required','string','max:255'],
            'code' => ['nullable','string','max:50','unique:inventory_teams,code'],
            'description' => ['nullable','string'],
            'is_active' => ['nullable','boolean'],
        ]);

        InventoryTeam::create([
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'description' => $data['description'] ?? null,
            'is_active' => (bool)($data['is_active'] ?? true),
            'created_by' => auth()->id(),
        ]);

        if ($request->expectsJson()) {
            return $this->successResponse([], 'Team created.', 201);
        }

        return redirect()->route('inventory.teams.index')->with('success', 'Team created.');
    }

    public function edit(InventoryTeam $team)
    {
        $team->load(['members.technician']);

        $technicians = User::query()
            ->where('role', 'technician')
            ->with('department')
            ->orderBy('name')
            ->get();

        if (request()->expectsJson()) {
            return $this->successResponse([
                'team' => $team,
                'technicians' => $technicians,
            ]);
        }

        return view('inventory::teams.edit', compact('team', 'technicians'));
    }

    public function update(Request $request, InventoryTeam $team)
    {
        $data = $request->validate([
            'name' => ['required','string','max:255'],
            'code' => ['nullable','string','max:50','unique:inventory_teams,code,' . $team->id],
            'description' => ['nullable','string'],
            'is_active' => ['nullable','boolean'],
        ]);

        $team->update([
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'description' => $data['description'] ?? null,
            'is_active' => (bool)($data['is_active'] ?? true),
        ]);

        if ($request->expectsJson()) {
            return $this->successResponse([], 'Team updated.');
        }

        return back()->with('success', 'Team updated.');
    }

    public function destroy(InventoryTeam $team)
    {
        $team->delete();

        if (request()->expectsJson()) {
            return $this->successResponse([], 'Team deleted.');
        }

        return back()->with('success', 'Team deleted.');
    }

    public function addMember(Request $request, InventoryTeam $team)
    {
        $data = $request->validate([
            'technician_id' => ['required','exists:users,id'],
            'role' => ['nullable','in:leader,member'],
            'is_active' => ['nullable','boolean'],
        ]);

        InventoryDatabase::transaction(function () use ($team, $data) {
            InventoryTeamMember::updateOrCreate(
                ['team_id' => $team->id, 'technician_id' => $data['technician_id']],
                ['role' => $data['role'] ?? 'member', 'is_active' => (bool)($data['is_active'] ?? true)]
            );
        });

        if ($request->expectsJson()) {
            return $this->successResponse([], 'Member added/updated.');
        }

        return back()->with('success', 'Member added/updated.');
    }

    public function removeMember(InventoryTeam $team, InventoryTeamMember $member)
    {
        if ((int)$member->team_id !== (int)$team->id) {
            abort(404);
        }

        $member->delete();

        if (request()->expectsJson()) {
            return $this->successResponse([], 'Member removed.');
        }

        return back()->with('success', 'Member removed.');
    }
}
