<?php

namespace App\Modules\Inventory\Http\Controllers\Inventory;

use App\Modules\Inventory\Http\Controllers\Controller;
use App\Modules\Inventory\Services\SkybrixRouterApiService;
use App\Modules\Inventory\Services\SkybrixSiteDeploymentWorkspaceService;
use App\Modules\Inventory\Support\InventoryDatabase;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

use App\Modules\Inventory\Models\InventoryLog;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemDeployment;
use App\Modules\Inventory\Models\ItemUnit;
use App\Modules\Inventory\Models\TechnicianItemAssignment;
use App\Modules\Inventory\Support\ApiResponder;
use Illuminate\Validation\ValidationException;

class DeploymentController extends Controller
{
    use ApiResponder;

    public function index(
        Request $request,
        SkybrixRouterApiService $routerApi,
        SkybrixSiteDeploymentWorkspaceService $siteDeploymentWorkspace
    )
    {
        // Inventory guard user
        $user = auth('inventory')->user();
        $section = in_array((string) $request->query('section', 'sites'), ['sites', 'history'], true)
            ? (string) $request->query('section', 'sites')
            : 'sites';

        $q = trim((string) $request->query('q', ''));
        $from = trim((string) $request->query('from', ''));
        $to = trim((string) $request->query('to', ''));
        $technician = trim((string) $request->query('technician', ''));

        try {
            $query = ItemDeployment::query()
                ->with(['item.group', 'technician', 'creator'])
                ->latest();

            // Safety: if a technician ever reaches index, restrict to their own deployments
            if (($user->inventory_role ?? null) === 'technician') {
                $query->where('technician_id', $user->id);
            }

            if ($q !== '') {
                $query->where(function ($builder) use ($q) {
                    $builder
                        ->where('site_name', 'like', '%' . $q . '%')
                        ->orWhere('site_code', 'like', '%' . $q . '%')
                        ->orWhere('reference', 'like', '%' . $q . '%')
                        ->orWhereHas('item', fn ($item) => $item->where('name', 'like', '%' . $q . '%'))
                        ->orWhereHas('technician', fn ($tech) => $tech->where('name', 'like', '%' . $q . '%'));
                });
            }

            if ($from !== '') {
                $query->whereDate('created_at', '>=', $from);
            }

            if ($to !== '') {
                $query->whereDate('created_at', '<=', $to);
            }

            if ($technician !== '') {
                $query->whereHas('technician', fn ($tech) => $tech->where('name', 'like', '%' . $technician . '%'));
            }

            $deployments = $query->paginate(30)->withQueryString();
        } catch (\Throwable) {
            $deployments = new LengthAwarePaginator(
                collect(),
                0,
                30,
                max(1, (int) $request->integer('page', 1)),
                [
                    'path' => route('inventory.deployments.index'),
                    'query' => $request->query(),
                    'pageName' => 'page',
                ]
            );
        }

        $siteFilters = [
            'page' => max(1, (int) $request->integer('page', 1)),
            'page_size' => max(1, (int) $request->integer(
                'page_size',
                (int) config('inventory.router_api.default_page_size', 20)
            )),
            'search' => trim((string) $request->query('search', '')),
            'site_id' => max(0, (int) $request->integer('site_id', 0)),
            'focus_site' => trim((string) $request->query('focus_site', '')),
            'batch_number' => '',
            'technician_id' => 0,
            'is_primary' => null,
        ];

        $siteWorkspace = $siteDeploymentWorkspace->build($request, $routerApi, $siteFilters);

        if ($request->expectsJson()) {
            return $this->successResponse([
                'deployments' => $deployments->items(),
                'section' => $section,
                'site_workspace' => $siteWorkspace['workspace'],
                'site_data' => $siteWorkspace['routerData'],
                'site_filters' => [
                    'search' => $siteFilters['search'],
                    'page_size' => $siteFilters['page_size'],
                    'site_id' => $siteFilters['site_id'] ?: '',
                    'focus_site' => $siteFilters['focus_site'],
                ],
                'filters' => [
                    'q' => $q,
                    'from' => $from,
                    'to' => $to,
                    'technician' => $technician,
                ],
            ], 'OK', 200, [
                'pagination' => $this->paginationMeta($deployments),
            ]);
        }

        return view('inventory::deployments.index', [
            'deployments' => $deployments,
            'section' => $section,
            'siteWorkspace' => $siteWorkspace['workspace'],
            'siteData' => $siteWorkspace['routerData'],
            'siteFilters' => [
                'search' => $siteFilters['search'],
                'page_size' => $siteFilters['page_size'],
                'site_id' => $siteFilters['site_id'] ?: '',
                'focus_site' => $siteFilters['focus_site'],
            ],
            'q' => $q,
            'from' => $from,
            'to' => $to,
            'technician' => $technician,
        ]);
    }

    public function store(Request $request, SkybrixRouterApiService $routerApi)
    {
        $user = auth('inventory')->user();
        $role = (string)($user->inventory_role ?? '');

        $data = $request->validate([
            // Admin can deploy on behalf of a technician (nullable)
            // IMPORTANT: now points to inventory_users table
            'technician_id' => ['nullable', 'integer', 'exists:inventory_users,id'],

            'item_id' => ['required', 'integer', 'exists:inventory_items,id'],

            // bulk deploy
            'qty' => ['nullable', 'integer', 'min:1'],

            // serialized deploy
            'unit_id' => ['nullable', 'integer'],

            'site_code' => ['nullable', 'string', 'max:100'],
            'site_id' => ['nullable', 'string', 'max:100'],
            'site_serial' => ['nullable', 'string', 'max:120'],
            'site_name' => [$role === 'technician' ? 'nullable' : 'required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $requiresRegisteredSite = $role === 'technician' || trim((string) ($data['site_serial'] ?? '')) !== '';

        if ($requiresRegisteredSite) {
            $resolvedSite = $this->resolveAuthorizedSite($data, $routerApi);
            $data['site_code'] = $resolvedSite['site_id'];
            $data['site_id'] = $resolvedSite['site_id'];
            $data['site_name'] = $resolvedSite['site_name'];
            $data['site_serial'] = $resolvedSite['site_serial'];
        }

        /**
         * Role enforcement:
         * - Technician: ALWAYS deploy under themselves (ignore technician_id)
         * - Admin: can deploy under selected technician_id; fallback to self if not provided
         */
        $technicianId = ($role === 'technician')
            ? $user->id
            : ((int)($data['technician_id'] ?? $user->id));

        InventoryDatabase::transaction(function () use ($data, $technicianId, $user, $role) {
            // Lock item row
            $item = Item::lockForUpdate()->findOrFail($data['item_id']);

            // Lock assignment row
            $assignment = TechnicianItemAssignment::query()
                ->where('technician_id', $technicianId)
                ->where('item_id', $item->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (!$assignment) {
                abort(422, "No active assignment found for this technician and item.");
            }

            /**
             * SERIALIZED DEPLOY
             */
            if ((bool)$item->has_serial) {
                $unitId = $data['unit_id'] ?? null;
                if (!$unitId) {
                    abort(422, "unit_id is required for serialized deploy: {$item->name}");
                }

                // Make sure they have available allocation
                if ($assignment->availableToDeploy() < 1) {
                    abort(422, "No available allocated units to deploy for {$item->name}");
                }

                // Lock the unit and ensure it belongs to that technician and is in assigned state
                $unit = ItemUnit::query()
                    ->where('id', $unitId)
                    ->where('item_id', $item->id)
                    ->where('status', 'assigned')
                    ->where('assigned_to', $technicianId)
                    ->lockForUpdate()
                    ->first();

                if (!$unit) {
                    abort(422, "Selected serial is not available for this technician.");
                }

                // Create deployment row
                ItemDeployment::create([
                    'technician_id' => $technicianId,
                    'item_id' => $item->id,
                    'qty' => 1,
                    'site_code' => $data['site_code'] ?? null,
                    'site_name' => $data['site_name'],
                    'reference' => $data['reference'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $user->id,
                ]);

                // Increment deployed count
                $assignment->increment('qty_deployed', 1);

                // Update unit status to deployed
                $unit->update([
                    'status' => 'deployed',
                    'deployed_site_code' => $data['site_code'] ?? null,
                    'deployed_site_name' => $data['site_name'],
                    'deployed_at' => now(),
                ]);

                // Log
                InventoryLog::create([
                    'action' => 'deployed',
                    'item_id' => $item->id,
                    'item_unit_id' => $unit->id,
                    'qty' => null,
                    'serial_no' => $unit->serial_no,
                    'from_user_id' => $technicianId,
                    'to_user_id' => null,
                    'site_code' => $data['site_code'] ?? null,
                    'site_name' => $data['site_name'],
                    'reference' => $data['reference'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $user->id,
                ]);

                return;
            }

            /**
             * BULK DEPLOY
             */
            $qty = (int)($data['qty'] ?? 0);
            if ($qty < 1) {
                abort(422, "qty is required for bulk deploy: {$item->name}");
            }

            if ($qty > $assignment->availableToDeploy()) {
                abort(422, "Not enough allocated quantity to deploy for {$item->name}");
            }

            ItemDeployment::create([
                'technician_id' => $technicianId,
                'item_id' => $item->id,
                'qty' => $qty,
                'site_code' => $data['site_code'] ?? null,
                'site_name' => $data['site_name'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            // deployed increments on assignment (store qty already reduced at assignment time)
            $assignment->increment('qty_deployed', $qty);

            InventoryLog::create([
                'action' => 'deployed',
                'item_id' => $item->id,
                'item_unit_id' => null,
                'qty' => $qty,
                'serial_no' => null,
                'from_user_id' => $technicianId,
                'to_user_id' => null,
                'site_code' => $data['site_code'] ?? null,
                'site_name' => $data['site_name'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);
        });

        if ($request->expectsJson()) {
            return $this->successResponse([], 'Deployment recorded (log created, unit/store records updated).', 201);
        }

        return back()->with('success', 'Deployment recorded (log created, unit/store records updated).');
    }

    /**
     * @param array<string,mixed> $data
     * @return array{site_id:string,site_name:string,site_serial:string}
     */
    private function resolveAuthorizedSite(array $data, SkybrixRouterApiService $routerApi): array
    {
        $siteId = trim((string) ($data['site_id'] ?? $data['site_code'] ?? ''));
        $siteName = trim((string) ($data['site_name'] ?? ''));
        $siteSerial = trim((string) ($data['site_serial'] ?? ''));

        if ($siteId === '' || $siteName === '' || $siteSerial === '') {
            throw ValidationException::withMessages([
                'site_name' => 'Select a registered ONT/site from the lookup before deploying.',
            ]);
        }

        $siteData = $routerApi->sites([
            'page' => 1,
            'page_size' => 10,
            'search' => $siteSerial,
        ]);

        if (!($siteData['available'] ?? false)) {
            throw ValidationException::withMessages([
                'site_name' => (string) ($siteData['message'] ?? 'Registered ONT/site lookup is currently unavailable.'),
            ]);
        }

        $matchedSite = collect($siteData['rows'] ?? [])
            ->map(fn ($row) => (array) $row)
            ->first(function (array $row) use ($siteId, $siteSerial) {
                $rowSiteId = trim((string) ($row['site_id'] ?? ''));
                $rowSerial = trim((string) ($row['serial'] ?? ''));

                return $rowSiteId === $siteId && strcasecmp($rowSerial, $siteSerial) === 0;
            });

        if (!$matchedSite) {
            throw ValidationException::withMessages([
                'site_name' => 'The selected ONT/site is not registered or no longer available for deployment.',
            ]);
        }

        return [
            'site_id' => trim((string) ($matchedSite['site_id'] ?? $siteId)),
            'site_name' => trim((string) ($matchedSite['site_name'] ?? $matchedSite['name'] ?? $siteName)),
            'site_serial' => trim((string) ($matchedSite['serial'] ?? $siteSerial)),
        ];
    }
}
