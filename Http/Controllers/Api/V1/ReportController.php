<?php

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\InventoryLog;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\InventoryUser;
use App\Modules\Inventory\Models\TechnicianItemAssignment;
use App\Modules\Inventory\Support\ApiResponder;
use App\Modules\Inventory\Support\InventoryDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    use ApiResponder;

    /**
     * Get inventory value report
     */
    public function inventoryValue(Request $request)
    {
        $items = Item::query()
            ->with('group')
            ->get()
            ->map(function (Item $item) {
                $unitCost = (float) ($item->unit_cost ?? 0);
                $quantity = (int) ($item->quantity ?? 0);
                $totalValue = $unitCost * $quantity;

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'sku' => $item->sku,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'total_value' => $totalValue,
                    'reorder_level' => $item->reorder_level,
                    'is_low_stock' => $quantity <= ($item->reorder_level ?? 0),
                    'group' => optional($item->group)->name,
                ];
            });

        $totalValue = $items->sum('total_value');
        $lowStockCount = $items->where('is_low_stock', true)->count();

        return $this->successResponse([
            'items' => $items,
            'summary' => [
                'total_items' => $items->count(),
                'total_value' => $totalValue,
                'low_stock_count' => $lowStockCount,
                'average_item_value' => $items->count() > 0 ? $totalValue / $items->count() : 0,
            ],
        ], 'Inventory value report.');
    }

    /**
     * Get technician productivity/stats report
     */
    public function technicianReport(Request $request)
    {
        $data = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
        ]);

        $query = TechnicianItemAssignment::with('user', 'item');

        if ($data['from_date'] ?? null) {
            $query->whereDate('created_at', '>=', $data['from_date']);
        }

        if ($data['to_date'] ?? null) {
            $query->whereDate('created_at', '<=', $data['to_date']);
        }

        $assignments = $query->get();

        $technicianStats = $assignments->groupBy('inventory_user_id')
            ->map(function ($tecAssignments, $userId) {
                $tech = InventoryUser::find($userId);

                return [
                    'id' => $userId,
                    'name' => optional($tech)->name,
                    'email' => optional($tech)->email,
                    'assignments_count' => $tecAssignments->count(),
                    'items_allocated' => $tecAssignments->sum('qty_allocated'),
                    'items_deployed' => $tecAssignments->sum('qty_deployed') ?? 0,
                ];
            })
            ->values();

        return $this->successResponse([
            'technicians' => $technicianStats,
            'period' => [
                'from' => $data['from_date'] ?? null,
                'to' => $data['to_date'] ?? null,
            ],
        ], 'Technician report.');
    }

    /**
     * Get inventory movement summary
     */
    public function movementSummary(Request $request)
    {
        $data = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'movement_type' => ['nullable', 'string', 'in:transfer,return,deploy'],
        ]);

        $query = InventoryMovement::with('from_technician', 'to_technician');

        if ($data['from_date'] ?? null) {
            $query->whereDate('created_at', '>=', $data['from_date']);
        }

        if ($data['to_date'] ?? null) {
            $query->whereDate('created_at', '<=', $data['to_date']);
        }

        if ($data['movement_type'] ?? null) {
            $query->where('movement_type', $data['movement_type']);
        }

        $movements = $query->latest()->paginate(20);

        $summary = [
            'total_movements' => $movements->total(),
            'by_type' => InventoryMovement::query()
                ->select('movement_type', DB::raw('count(*) as count'))
                ->groupBy('movement_type')
                ->pluck('count', 'movement_type'),
        ];

        return $this->successResponse([
            'movements' => $movements->items(),
            'summary' => $summary,
            'period' => [
                'from' => $data['from_date'] ?? null,
                'to' => $data['to_date'] ?? null,
            ],
        ], 'Movement summary.', 200, [
            'pagination' => $this->paginationMeta($movements),
        ]);
    }

    /**
     * Get inventory activity/audit report
     */
    public function auditReport(Request $request)
    {
        $data = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'user_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string'],
        ]);

        $query = InventoryLog::with('user');

        if ($data['from_date'] ?? null) {
            $query->whereDate('created_at', '>=', $data['from_date']);
        }

        if ($data['to_date'] ?? null) {
            $query->whereDate('created_at', '<=', $data['to_date']);
        }

        if ($data['user_id'] ?? null) {
            $query->where('inventory_user_id', $data['user_id']);
        }

        if ($data['action'] ?? null) {
            $query->where('action', $data['action']);
        }

        $logs = $query->latest()->paginate(50);

        return $this->successResponse([
            'activities' => $logs->items(),
        ], 'Audit report.', 200, [
            'pagination' => $this->paginationMeta($logs),
        ]);
    }

    /**
     * Get low stock items trending over time
     */
    public function lowStockTrend(Request $request)
    {
        $data = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $days = $data['days'] ?? 30;

        // Simple trend: get items that are currently low stock
        $lowStockItems = Item::query()
            ->whereRaw('quantity <= reorder_level')
            ->with('group')
            ->orderByDesc('id')
            ->paginate(20);

        return $this->successResponse([
            'low_stock_items' => $lowStockItems->items(),
            'total_count' => $lowStockItems->total(),
            'period_days' => $days,
        ], 'Low stock trend report.', 200, [
            'pagination' => $this->paginationMeta($lowStockItems),
        ]);
    }
}
