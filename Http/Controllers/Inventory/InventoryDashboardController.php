<?php

namespace App\Modules\Inventory\Http\Controllers\Inventory;

use App\Modules\Inventory\Support\ApiResponder;
use App\Modules\Inventory\Support\InventoryDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class InventoryDashboardController extends Controller
{
    use ApiResponder;

    public function index(Request $request)
    {
        $lowStock = InventoryDatabase::table('inventory_items')
            ->where('is_active', 1)
            ->whereColumn('qty_on_hand', '<=', 'reorder_level')
            ->count();

        $items = InventoryDatabase::table('inventory_items')->count();
        $teams = InventoryDatabase::table('inventory_teams')->count();
        $logs7d = InventoryDatabase::table('inventory_logs')
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        if ($request->expectsJson()) {
            return $this->successResponse([
                'low_stock' => (int) $lowStock,
                'items' => (int) $items,
                'teams' => (int) $teams,
                'logs_7d' => (int) $logs7d,
            ]);
        }

        return view('inventory::dashboard.index', compact('lowStock', 'items', 'teams', 'logs7d'));
    }
}
