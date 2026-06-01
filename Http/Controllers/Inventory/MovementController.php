<?php

namespace App\Modules\Inventory\Http\Controllers\Inventory;

use App\Modules\Inventory\Http\Controllers\Controller;
use App\Modules\Inventory\Models\InventoryLog;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryMovementLine;
use App\Modules\Inventory\Models\InventoryUser;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemUnit;
use App\Modules\Inventory\Models\TechnicianItemAssignment;
use App\Modules\Inventory\Support\ApiResponder;
use App\Modules\Inventory\Support\InventoryDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MovementController extends Controller
{
    use ApiResponder;

    public function index()
    {
        $movements = InventoryMovement::query()
            ->with(['fromUser', 'toUser', 'creator', 'lines.item'])
            ->latest()
            ->paginate(30)
            ->withQueryString();

        if (request()->expectsJson()) {
            return $this->successResponse([
                'movements' => $movements->items(),
            ], 'OK', 200, [
                'pagination' => $this->paginationMeta($movements),
            ]);
        }

        return view('inventory::movements.index', compact('movements'));
    }

    public function transferForm()
    {
        $technicians = InventoryUser::query()
            ->where('inventory_role', 'technician')
            ->where('inventory_enabled', true)
            ->with('department')
            ->orderBy('name')
            ->get();

        $assignmentsByTech = TechnicianItemAssignment::query()
            ->with(['item'])
            ->where('is_active', true)
            ->get()
            ->groupBy('technician_id')
            ->map(function ($rows) {
                return $rows->map(function ($a) {
                    return [
                        'item_id' => $a->item_id,
                        'item_name' => $a->item?->name,
                        'available' => $a->availableToDeploy(), // transferable available (allocated - deployed)
                        'has_serial' => (bool)($a->item?->has_serial),
                    ];
                })->values();
            });

        $assignedUnitsByTech = ItemUnit::query()
            ->where('status', 'assigned')
            ->whereNotNull('assigned_to')
            ->orderBy('serial_no')
            ->get()
            ->groupBy(fn ($u) => $u->assigned_to)
            ->map(function ($units) {
                return $units->groupBy('item_id')->map(function ($u2) {
                    return $u2->map(fn ($u) => ['id' => $u->id, 'serial_no' => $u->serial_no])->values();
                });
            });

        if (request()->expectsJson()) {
            return $this->successResponse([
                'technicians' => $technicians,
                'assignments' => $assignmentsByTech,
                'assigned_units' => $assignedUnitsByTech,
            ]);
        }

        return view('inventory::movements.transfer', compact('technicians', 'assignmentsByTech', 'assignedUnitsByTech'));
    }

    public function returnToStoreForm()
    {
        $technicians = InventoryUser::query()
            ->where('inventory_role', 'technician')
            ->where('inventory_enabled', true)
            ->with('department')
            ->orderBy('name')
            ->get();

        $assignmentsByTech = TechnicianItemAssignment::query()
            ->with(['item'])
            ->where('is_active', true)
            ->get()
            ->groupBy('technician_id')
            ->map(function ($rows) {
                return $rows->map(function ($a) {
                    return [
                        'item_id' => $a->item_id,
                        'item_name' => $a->item?->name,
                        'available' => $a->availableToDeploy(),
                        'has_serial' => (bool)($a->item?->has_serial),
                    ];
                })->values();
            });

        $assignedUnitsByTech = ItemUnit::query()
            ->where('status', 'assigned')
            ->whereNotNull('assigned_to')
            ->orderBy('serial_no')
            ->get()
            ->groupBy(fn ($u) => $u->assigned_to)
            ->map(function ($units) {
                return $units->groupBy('item_id')->map(function ($u2) {
                    return $u2->map(fn ($u) => ['id' => $u->id, 'serial_no' => $u->serial_no])->values();
                });
            });

        if (request()->expectsJson()) {
            return $this->successResponse([
                'technicians' => $technicians,
                'assignments' => $assignmentsByTech,
                'assigned_units' => $assignedUnitsByTech,
            ]);
        }

        return view('inventory::movements.return_to_store', compact('technicians', 'assignmentsByTech', 'assignedUnitsByTech'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:transfer,return_to_store,return_from_site'],

            'from_user_id' => ['nullable', 'integer', 'exists:inventory_users,id'],
            'to_user_id' => ['nullable', 'integer', 'exists:inventory_users,id'],

            'item_id' => ['required', 'integer', 'exists:inventory_items,id'],

            // bulk
            'qty' => ['nullable', 'integer', 'min:1'],

            // serialized (single unit per submission for simplicity)
            'item_unit_id' => ['nullable', 'integer'],

            'site_code' => ['nullable', 'string', 'max:100'],
            'site_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $createdBy = auth('inventory')->id();

        $prefix = match ($data['type']) {
            'transfer' => 'TRF',
            'return_to_store' => 'RTS',
            'return_from_site' => 'RFS',
        };

        $reference = $prefix . '-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6));

        InventoryDatabase::transaction(function () use ($data, $createdBy, $reference) {
            $type = $data['type'];

            // Basic party validation
            if ($type === 'transfer') {
                if (empty($data['from_user_id']) || empty($data['to_user_id'])) abort(422, 'Transfer needs from_user_id and to_user_id');
                if ((int)$data['from_user_id'] === (int)$data['to_user_id']) abort(422, 'Cannot transfer to same technician');
            }

            if ($type === 'return_to_store') {
                if (empty($data['from_user_id'])) abort(422, 'Return to store needs from_user_id (technician)');
            }

            $item = Item::lockForUpdate()->findOrFail($data['item_id']);

            $movement = InventoryMovement::create([
                'reference' => $reference,
                'type' => $type,
                'movement_at' => now(),
                'from_user_id' => $data['from_user_id'] ?? null,
                'to_user_id' => $data['to_user_id'] ?? null,
                'site_code' => $data['site_code'] ?? null,
                'site_name' => $data['site_name'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $createdBy,
            ]);

            // SERIALIZED
            if ($item->has_serial) {
                $unitId = $data['item_unit_id'] ?? null;
                if (!$unitId) abort(422, 'item_unit_id required for serialized movement');

                $unit = ItemUnit::query()
                    ->where('id', $unitId)
                    ->where('item_id', $item->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Transfer: unit must be assigned to from_user
                if ($type === 'transfer') {
                    if ($unit->status !== 'assigned' || (int)$unit->assigned_to !== (int)$data['from_user_id']) {
                        abort(422, 'Selected serial is not assigned to the FROM technician');
                    }

                    $unit->update([
                        'assigned_to' => $data['to_user_id'],
                        'assigned_at' => now(),
                        'status' => 'assigned',
                    ]);

                    $fromAssign = TechnicianItemAssignment::query()
                        ->where('technician_id', $data['from_user_id'])
                        ->where('item_id', $item->id)
                        ->where('is_active', true)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($fromAssign->availableToDeploy() < 1) abort(422, 'No transferable available qty on FROM assignment');
                    $fromAssign->decrement('qty_allocated', 1);

                    $toAssign = TechnicianItemAssignment::query()
                        ->where('technician_id', $data['to_user_id'])
                        ->where('item_id', $item->id)
                        ->lockForUpdate()
                        ->first();

                    if (!$toAssign) {
                        $toAssign = TechnicianItemAssignment::create([
                            'technician_id' => $data['to_user_id'],
                            'item_id' => $item->id,
                            'qty_allocated' => 0,
                            'qty_deployed' => 0,
                            'assigned_by' => $createdBy,
                            'assigned_at' => now(),
                            'is_active' => true,
                        ]);
                    } else {
                        $toAssign->update(['is_active' => true]);
                    }

                    $toAssign->increment('qty_allocated', 1);

                    InventoryMovementLine::create([
                        'movement_id' => $movement->id,
                        'item_id' => $item->id,
                        'qty' => null,
                        'item_unit_id' => $unit->id,
                        'serial_no' => $unit->serial_no,
                    ]);

                    InventoryLog::create([
                        'action' => 'assigned',
                        'item_id' => $item->id,
                        'item_unit_id' => $unit->id,
                        'qty' => null,
                        'serial_no' => $unit->serial_no,
                        'from_user_id' => $data['from_user_id'],
                        'to_user_id' => $data['to_user_id'],
                        'reference' => $movement->reference,
                        'notes' => $data['notes'] ?? 'Transfer',
                        'created_by' => $createdBy,
                    ]);

                    return;
                }

                // Return to store: unit must be assigned to from_user
                if ($type === 'return_to_store') {
                    if ($unit->status !== 'assigned' || (int)$unit->assigned_to !== (int)$data['from_user_id']) {
                        abort(422, 'Selected serial is not assigned to that technician');
                    }

                    $unit->update([
                        'status' => 'in_store',
                        'assigned_to' => null,
                        'assigned_at' => null,
                        'deployed_site_code' => null,
                        'deployed_site_name' => null,
                        'deployed_at' => null,
                    ]);

                    $assign = TechnicianItemAssignment::query()
                        ->where('technician_id', $data['from_user_id'])
                        ->where('item_id', $item->id)
                        ->where('is_active', true)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($assign->availableToDeploy() < 1) abort(422, 'No available qty to return (allocated already deployed)');
                    $assign->decrement('qty_allocated', 1);

                    $item->increment('qty_on_hand', 1);

                    InventoryMovementLine::create([
                        'movement_id' => $movement->id,
                        'item_id' => $item->id,
                        'qty' => null,
                        'item_unit_id' => $unit->id,
                        'serial_no' => $unit->serial_no,
                    ]);

                    InventoryLog::create([
                        'action' => 'received',
                        'item_id' => $item->id,
                        'item_unit_id' => $unit->id,
                        'qty' => null,
                        'serial_no' => $unit->serial_no,
                        'from_user_id' => $data['from_user_id'],
                        'to_user_id' => null,
                        'reference' => $movement->reference,
                        'notes' => $data['notes'] ?? 'Return to store',
                        'created_by' => $createdBy,
                    ]);

                    return;
                }

                abort(422, 'Unsupported serialized movement type.');
            }

            // BULK
            $qty = (int)($data['qty'] ?? 0);
            if ($qty < 1) abort(422, 'qty required for bulk movement');

            if ($type === 'transfer') {
                $fromAssign = TechnicianItemAssignment::query()
                    ->where('technician_id', $data['from_user_id'])
                    ->where('item_id', $item->id)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($qty > $fromAssign->availableToDeploy()) abort(422, 'Not enough available qty to transfer');

                $fromAssign->decrement('qty_allocated', $qty);

                $toAssign = TechnicianItemAssignment::query()
                    ->where('technician_id', $data['to_user_id'])
                    ->where('item_id', $item->id)
                    ->lockForUpdate()
                    ->first();

                if (!$toAssign) {
                    $toAssign = TechnicianItemAssignment::create([
                        'technician_id' => $data['to_user_id'],
                        'item_id' => $item->id,
                        'qty_allocated' => 0,
                        'qty_deployed' => 0,
                        'assigned_by' => $createdBy,
                        'assigned_at' => now(),
                        'is_active' => true,
                    ]);
                } else {
                    $toAssign->update(['is_active' => true]);
                }

                $toAssign->increment('qty_allocated', $qty);

                InventoryMovementLine::create([
                    'movement_id' => $movement->id,
                    'item_id' => $item->id,
                    'qty' => $qty,
                    'item_unit_id' => null,
                    'serial_no' => null,
                ]);

                InventoryLog::create([
                    'action' => 'assigned',
                    'item_id' => $item->id,
                    'item_unit_id' => null,
                    'qty' => $qty,
                    'serial_no' => null,
                    'from_user_id' => $data['from_user_id'],
                    'to_user_id' => $data['to_user_id'],
                    'reference' => $movement->reference,
                    'notes' => $data['notes'] ?? 'Transfer',
                    'created_by' => $createdBy,
                ]);

                return;
            }

            if ($type === 'return_to_store') {
                $assign = TechnicianItemAssignment::query()
                    ->where('technician_id', $data['from_user_id'])
                    ->where('item_id', $item->id)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($qty > $assign->availableToDeploy()) abort(422, 'Not enough available qty to return');

                $assign->decrement('qty_allocated', $qty);

                $item->increment('qty_on_hand', $qty);

                InventoryMovementLine::create([
                    'movement_id' => $movement->id,
                    'item_id' => $item->id,
                    'qty' => $qty,
                    'item_unit_id' => null,
                    'serial_no' => null,
                ]);

                InventoryLog::create([
                    'action' => 'received',
                    'item_id' => $item->id,
                    'item_unit_id' => null,
                    'qty' => $qty,
                    'serial_no' => null,
                    'from_user_id' => $data['from_user_id'],
                    'to_user_id' => null,
                    'reference' => $movement->reference,
                    'notes' => $data['notes'] ?? 'Return to store',
                    'created_by' => $createdBy,
                ]);

                return;
            }

            abort(422, 'Unsupported bulk movement type.');
        });

        if ($request->expectsJson()) {
            return $this->successResponse([
                'reference' => $reference,
            ], 'Movement saved (assignments updated + store updated + logs written).', 201);
        }

        return back()->with('success', 'Movement saved (assignments updated + store updated + logs written).');
    }
}
