<?php

namespace App\Modules\Inventory\Http\Controllers\Inventory;

use App\Modules\Inventory\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemDeployment;
use App\Modules\Inventory\Models\ItemUnit;
use App\Modules\Inventory\Models\TechnicianItemAssignment;
use App\Modules\Inventory\Services\SkybrixRouterApiService;
use App\Modules\Inventory\Services\SkybrixTechnicianRouterAssignmentService;
use App\Modules\Inventory\Support\ApiResponder;
use Illuminate\Support\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TechnicianInventoryController extends Controller
{
    use ApiResponder;

    public function index(SkybrixTechnicianRouterAssignmentService $technicianRouters)
    {
        $user = auth('inventory')->user();

        if (!$user) {
            return redirect()->route('inventory.auth.login');
        }

        $baseQuery = TechnicianItemAssignment::query()
            ->with(['item.group'])
            ->where('technician_id', $user->id)
            ->where('is_active', true);

        $summaryAssignments = (clone $baseQuery)
            ->get();

        $assignments = (clone $baseQuery)
            ->orderByDesc('updated_at')
            ->paginate(24)
            ->withQueryString();

        $assignedSerialCounts = ItemUnit::query()
            ->select('item_id', DB::raw('COUNT(*) as total'))
            ->where('assigned_to', $user->id)
            ->where('status', 'assigned')
            ->groupBy('item_id')
            ->pluck('total', 'item_id');

        $recentDeployments = ItemDeployment::query()
            ->with('item')
            ->where('technician_id', $user->id)
            ->latest()
            ->limit(6)
            ->get();

        $deploymentRecordCount = ItemDeployment::query()
            ->where('technician_id', $user->id)
            ->count();

        $assignedRouterData = $technicianRouters->assignedRoutersForUser($user);
        $assignedRouters = $this->mapAssignedRouters(collect($assignedRouterData['rows'] ?? []));

        $summary = [
            'assigned_items' => $summaryAssignments->count(),
            'allocated_total' => (int) $summaryAssignments->sum(fn (TechnicianItemAssignment $assignment) => (int) ($assignment->qty_allocated ?? 0)),
            'deployable_total' => (int) $summaryAssignments->sum(fn (TechnicianItemAssignment $assignment) => (int) $assignment->availableToDeploy()),
            'serialized_items' => (int) $summaryAssignments->filter(fn (TechnicianItemAssignment $assignment) => (bool) ($assignment->item?->has_serial ?? false))->count(),
            'deployments_recorded' => (int) $deploymentRecordCount,
            'assigned_routers' => $assignedRouters->count(),
        ];

        $routerSummary = [
            'assigned' => $assignedRouters->count(),
            'batch_count' => $assignedRouters->pluck('batch_number')->filter()->unique()->count(),
            'latest_assigned_at' => $assignedRouters
                ->map(fn (array $router) => $this->routerTimestamp($router))
                ->filter()
                ->sortDesc()
                ->first(),
            'api_available' => (bool) ($assignedRouterData['available'] ?? false),
        ];

        if (request()->expectsJson()) {
            return $this->successResponse([
                'assignments' => $assignments->items(),
                'summary' => $summary,
                'assigned_serial_counts' => $assignedSerialCounts,
                'recent_deployments' => $recentDeployments,
                'router_summary' => $routerSummary,
            ], 'OK', 200, [
                'pagination' => $this->paginationMeta($assignments),
            ]);
        }

        return view('inventory::tech.items.index', compact(
            'assignments',
            'summary',
            'assignedSerialCounts',
            'recentDeployments',
            'routerSummary'
        ));
    }

    public function show(Item $item)
    {
        $user = auth('inventory')->user();

        if (!$user) {
            return redirect()->route('inventory.auth.login');
        }

        $assignment = TechnicianItemAssignment::query()
            ->with(['item.group'])
            ->where('technician_id', $user->id)
            ->where('item_id', $item->id)
            ->where('is_active', true)
            ->firstOrFail();

        $assignedUnits = collect();

        if ($assignment->item?->has_serial) {
            $assignedUnits = ItemUnit::query()
                ->where('item_id', $item->id)
                ->where('status', 'assigned')
                ->where('assigned_to', $user->id)
                ->orderBy('serial_no')
                ->get();
        }

        $recentDeployments = ItemDeployment::query()
            ->where('technician_id', $user->id)
            ->where('item_id', $item->id)
            ->latest()
            ->limit(6)
            ->get();

        $siteLookupUrl = route('inventory.tech.sites.lookup');

        if (request()->expectsJson()) {
            return $this->successResponse([
                'assignment' => $assignment,
                'assigned_units' => $assignedUnits,
                'recent_deployments' => $recentDeployments,
                'site_lookup_url' => $siteLookupUrl,
            ]);
        }

        return view('inventory::tech.items.show', compact(
            'assignment',
            'assignedUnits',
            'recentDeployments',
            'siteLookupUrl'
        ));
    }

    public function siteLookup(Request $request, SkybrixRouterApiService $routerApi): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));
        $limit = min(12, max(5, (int) $request->integer('limit', 8)));

        $siteData = $routerApi->sites([
            'page' => 1,
            'page_size' => $limit,
            'search' => $search,
        ]);

        $sites = collect($siteData['rows'] ?? [])
            ->map(function ($row) {
                $row = (array) $row;

                $siteId = trim((string) ($row['site_id'] ?? ''));
                $siteSerial = trim((string) ($row['serial'] ?? ''));
                $siteName = trim((string) ($row['site_name'] ?? $row['name'] ?? ''));

                return [
                    'site_id' => $siteId,
                    'site_name' => $siteName,
                    'site_serial' => $siteSerial,
                    'status' => trim((string) ($row['status'] ?? '')),
                    'rx_power' => isset($row['rx_power']) ? (string) $row['rx_power'] : '',
                    'tx_power' => isset($row['tx_power']) ? (string) $row['tx_power'] : '',
                    'olt' => trim((string) ($row['olt'] ?? '')),
                    'slot' => trim((string) ($row['slot'] ?? '')),
                    'pon' => trim((string) ($row['pon'] ?? '')),
                    'acs_last_inform' => trim((string) ($row['acs_last_inform'] ?? '')),
                    'registered_at' => trim((string) ($row['reg_date'] ?? '')),
                ];
            })
            ->filter(fn (array $site) => $site['site_id'] !== '' && $site['site_name'] !== '' && $site['site_serial'] !== '')
            ->values();

        return response()->json([
            'available' => (bool) ($siteData['available'] ?? false),
            'message' => (string) ($siteData['message'] ?? ''),
            'data' => $sites,
        ]);
    }

    private function mapAssignedRouters(Collection $rows): Collection
    {
        return $rows
            ->map(function ($row) {
                $row = (array) $row;

                return [
                    'serial_number' => trim((string) ($row['serial_number'] ?? '')),
                    'batch_number' => trim((string) ($row['batch_number'] ?? '')),
                    'brand' => trim((string) ($row['brand'] ?? '')),
                    'model_number' => trim((string) ($row['model_number'] ?? '')),
                    'assigned_date' => trim((string) ($row['assigned_date'] ?? '')),
                    'expected_deployment_date' => trim((string) ($row['expected_deployment_date'] ?? '')),
                    'urgency' => trim((string) ($row['urgency'] ?? '')),
                    'days_assigned' => trim((string) ($row['days_assigned'] ?? '')),
                    'technician_name' => trim((string) ($row['technician_name'] ?? '')),
                    'status' => trim((string) ($row['status'] ?? '')),
                    'mac_address' => trim((string) ($row['mac_address'] ?? '')),
                ];
            })
            ->sort(function (array $left, array $right) {
                $time = strcmp((string) $this->routerTimestamp($right), (string) $this->routerTimestamp($left));
                if ($time !== 0) {
                    return $time;
                }

                return strcmp((string) ($left['serial_number'] ?? ''), (string) ($right['serial_number'] ?? ''));
            })
            ->values();
    }

    private function routerTimestamp(array $router): ?string
    {
        foreach ([
            'assigned_date',
            'expected_deployment_date',
            'updated_at',
            'created_at',
        ] as $field) {
            $value = trim((string) ($router[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
