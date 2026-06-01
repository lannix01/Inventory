<?php

namespace App\Modules\Inventory\Http\Controllers\Inventory;

use App\Modules\Inventory\Http\Controllers\Controller;
use App\Modules\Inventory\Models\ItemUnit;
use App\Modules\Inventory\Services\SkybrixRouterApiService;
use App\Modules\Inventory\Services\SkybrixTechnicianRouterAssignmentService;
use App\Modules\Inventory\Support\InventoryAccess;
use App\Modules\Inventory\Support\ApiResponder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class RouterController extends Controller
{
    use ApiResponder;

    private const SECTIONS = [
        'batches' => [
            'label' => 'Stock Batches',
            'subtitle' => 'Batch totals across stock, technician, deployed, and faulty states.',
        ],
        'in-stock' => [
            'label' => 'In Stock Routers',
            'subtitle' => 'Routers currently in store or office stock.',
        ],
        'with-techs' => [
            'label' => 'Undeployed Routers',
            'subtitle' => 'Routers grouped under technicians awaiting deployment.',
        ],
        'deployed' => [
            'label' => 'Deployed Routers',
            'subtitle' => 'Flat live table of deployed router rows returned by Skybrix.',
        ],
        'faulty' => [
            'label' => 'Faulty Routers',
            'subtitle' => 'Routers marked faulty in the live inventory feed.',
        ],
    ];

    private const STATE_LABELS = [
        'in-stock' => 'In Stock',
        'with-techs' => 'With Tech',
        'deployed' => 'Deployed',
        'faulty' => 'Faulty',
    ];

    public function index(
        Request $request,
        SkybrixRouterApiService $routerApi,
        SkybrixTechnicianRouterAssignmentService $technicianRouterAssignments
    )
    {
        $authUser = auth('inventory')->user();
        $isTechnicianScoped = InventoryAccess::normalizeRole($authUser?->inventory_role) === 'technician';
        $sections = $isTechnicianScoped
            ? [
                'with-techs' => [
                    'label' => 'My Routers',
                    'subtitle' => 'Routers assigned to you and waiting for deployment.',
                ],
            ]
            : self::SECTIONS;

        $section = $this->normalizeSection($isTechnicianScoped ? 'with-techs' : (string) $request->query('section', 'batches'));
        $filters = [
            'page' => max(1, (int) $request->integer('page', 1)),
            'page_size' => max(1, (int) $request->integer(
                'page_size',
                (int) config('inventory.router_api.default_page_size', 20)
            )),
            'search' => trim((string) $request->query('search', '')),
            'batch_number' => trim((string) $request->query('batch_number', '')),
            'technician_id' => max(0, (int) $request->integer('technician_id', 0)),
            'site_id' => max(0, (int) $request->integer('site_id', 0)),
            'focus_batch' => trim((string) $request->query('focus_batch', '')),
            'focus_site' => trim((string) $request->query('focus_site', '')),
        ];

        $filters['is_primary'] = $request->has('is_primary')
            ? $request->boolean('is_primary')
            : null;

        if ($isTechnicianScoped) {
            $filters['technician_id'] = max(1, (int) ($authUser->id ?? 0));
            $filters['batch_number'] = '';
            $filters['site_id'] = 0;
            $filters['focus_batch'] = '';
            $filters['focus_site'] = '';
            $filters['is_primary'] = null;
        }

        $workspace = [
            'type' => 'table',
            'paginator' => null,
            'focus' => null,
            'focus_key' => null,
            'group_count' => 0,
            'router_count' => 0,
            'site_count' => 0,
            'technician_count' => 0,
        ];

        if ($section === 'batches') {
            [$routerData, $workspace] = $this->buildBatchWorkspace($request, $routerApi, $filters);
            $rows = collect();
            $routerPaginator = $workspace['paginator'];
        } elseif ($section === 'with-techs') {
            [$routerData, $workspace] = $this->buildTechnicianWorkspace(
                $request,
                $routerApi,
                $filters,
                $isTechnicianScoped,
                $authUser,
                $technicianRouterAssignments
            );
            $rows = collect();
            $routerPaginator = $workspace['paginator'];
        } else {
            $routerData = $this->fetchSection($routerApi, $section, $filters, false);
            $rows = collect($routerData['rows'] ?? []);
            $routerPaginator = $this->makeApiPaginator($rows, (array) ($routerData['pagination'] ?? []), $request);
            $workspace['router_count'] = $rows->count();
        }

        if ($request->expectsJson()) {
            $pagination = $routerPaginator instanceof LengthAwarePaginator
                ? $this->paginationMeta($routerPaginator)
                : null;

            return $this->successResponse([
                'sections' => $sections,
                'section' => $section,
                'section_meta' => $sections[$section],
                'rows' => $rows,
                'router_data' => $routerData,
                'workspace' => $workspace,
                'is_technician_scoped' => $isTechnicianScoped,
                'filters' => [
                    'search' => $filters['search'],
                    'page_size' => max(1, (int) ($routerData['pagination']['per_page'] ?? $filters['page_size'])),
                    'batch_number' => $filters['batch_number'],
                    'technician_id' => $filters['technician_id'] ?: '',
                    'site_id' => $filters['site_id'] ?: '',
                    'is_primary' => $filters['is_primary'],
                    'focus_batch' => $filters['focus_batch'],
                    'focus_site' => $filters['focus_site'],
                ],
            ], 'OK', 200, [
                'pagination' => $pagination ?: [],
            ]);
        }

        return view('inventory::routers.index', [
            'sections' => $sections,
            'section' => $section,
            'sectionMeta' => $sections[$section],
            'rows' => $rows,
            'routerData' => $routerData,
            'routerPaginator' => $routerPaginator,
            'workspace' => $workspace,
            'isTechnicianScoped' => $isTechnicianScoped,
            'filters' => [
                'search' => $filters['search'],
                'page_size' => max(1, (int) ($routerData['pagination']['per_page'] ?? $filters['page_size'])),
                'batch_number' => $filters['batch_number'],
                'technician_id' => $filters['technician_id'] ?: '',
                'site_id' => $filters['site_id'] ?: '',
                'is_primary' => $filters['is_primary'],
                'focus_batch' => $filters['focus_batch'],
                'focus_site' => $filters['focus_site'],
            ],
        ]);
    }

    private function buildBatchWorkspace(Request $request, SkybrixRouterApiService $routerApi, array $filters): array
    {
        $inStock = $this->fetchSection($routerApi, 'in-stock', $filters, true);
        $withTechs = $this->fetchSection($routerApi, 'with-techs', $filters, true);
        $deployed = $this->fetchSection($routerApi, 'deployed', $filters, true);
        $faulty = $this->fetchSection($routerApi, 'faulty', $filters, true);

        $collections = [
            'in-stock' => collect($inStock['rows'] ?? []),
            'with-techs' => collect($withTechs['rows'] ?? []),
            'deployed' => collect($deployed['rows'] ?? []),
            'faulty' => collect($faulty['rows'] ?? []),
        ];

        $inventoryIndex = $this->buildInventoryIndex($collections);
        $groups = $this->buildBatchGroups($collections, $inventoryIndex);
        $focusKey = $filters['focus_batch'] !== '' ? $filters['focus_batch'] : $filters['batch_number'];
        $groups = $this->appendSearchedBatchGroup($groups, $routerApi, $focusKey, $inventoryIndex);

        $summaryBatches = $groups
            ->slice(($filters['page'] - 1) * $filters['page_size'], $filters['page_size'])
            ->filter(function (array $group) {
                return trim((string) ($group['batch_number'] ?? '')) !== ''
                    && (
                        (int) ($group['with_techs_count'] ?? 0) === 0
                        || (int) ($group['deployed_count'] ?? 0) === 0
                    );
            })
            ->pluck('batch_number')
            ->unique()
            ->values()
            ->all();

        $summaryLookups = $this->buildBatchSearchLookup($routerApi, $summaryBatches, $inventoryIndex, false);
        $focus = $focusKey !== '' ? $groups->first(fn (array $group) => $group['batch_number'] === $focusKey) : null;
        $paginator = $this->makeCollectionPaginator($groups, $filters['page_size'], $request);

        $paginator->setCollection(
            $paginator->getCollection()
                ->map(function ($group) use ($summaryLookups) {
                    $group = (array) $group;
                    $batchNumber = trim((string) ($group['batch_number'] ?? ''));

                    return $this->applyBatchSearchLookup(
                        $group,
                        $batchNumber !== '' ? ($summaryLookups[$batchNumber] ?? null) : null,
                        false
                    );
                })
                ->values()
        );

        if ($focus !== null) {
            $focusLookup = $this->buildBatchSearchLookup($routerApi, [$focus['batch_number']], $inventoryIndex, true);
            $focus = $this->applyBatchSearchLookup(
                $focus,
                $focusLookup[$focus['batch_number']] ?? null,
                true
            );
        }

        return [
            $this->combineGroupedResults([$inStock, $withTechs, $deployed, $faulty], $paginator),
            [
                'type' => 'batches',
                'paginator' => $paginator,
                'focus' => $focus,
                'focus_key' => $focusKey !== '' ? $focusKey : null,
                'group_count' => $groups->count(),
                'router_count' => array_sum(array_map(fn (Collection $rows) => $rows->count(), $collections)),
                'site_count' => 0,
                'technician_count' => $groups->flatMap(fn (array $group) => $group['technicians'])->unique()->count(),
            ],
        ];
    }

    private function buildTechnicianWorkspace(
        Request $request,
        SkybrixRouterApiService $routerApi,
        array $filters,
        bool $technicianScoped = false,
        $technician = null,
        ?SkybrixTechnicianRouterAssignmentService $technicianRouterAssignments = null
    ): array
    {
        if ($technicianScoped) {
            $resolved = $technician && $technicianRouterAssignments
                ? $technicianRouterAssignments->assignedRoutersForUser($technician)
                : [
                    'available' => false,
                    'message' => 'No technician context available.',
                    'fetched_at' => null,
                    'rows' => [],
                ];

            $rows = collect($resolved['rows'] ?? []);
            $rows = $technicianRouterAssignments
                ? $technicianRouterAssignments->filterRows($rows, (string) ($filters['search'] ?? ''))
                : $rows;

            $inventoryIndex = $this->buildInventoryIndex(['with-techs' => $rows]);
            $routerRows = $rows
                ->map(fn ($row) => $this->decorateRow((array) $row, 'with-techs', $inventoryIndex))
                ->sort(function (array $left, array $right) {
                    $time = strcmp((string) ($this->rowTimestamp($right) ?? ''), (string) ($this->rowTimestamp($left) ?? ''));
                    if ($time !== 0) {
                        return $time;
                    }

                    return strcmp((string) ($left['_serial'] ?? ''), (string) ($right['_serial'] ?? ''));
                })
                ->values();

            $paginator = $this->makeCollectionPaginator($routerRows, $filters['page_size'], $request);
            $first = (array) $routerRows->first();
            $routerData = [
                'available' => (bool) ($resolved['available'] ?? false),
                'message' => (string) ($resolved['message'] ?? ''),
                'fetched_at' => $resolved['fetched_at'] ?? null,
                'rows' => [],
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $routerRows->count(),
                    'page_count' => $paginator->lastPage(),
                ],
                'request' => $resolved['request'] ?? [
                    'section' => 'with-techs',
                    'url' => null,
                    'params' => [],
                ],
            ];

            return [
                $routerData,
                [
                    'type' => 'technician-routers',
                    'paginator' => $paginator,
                    'focus' => null,
                    'focus_key' => null,
                    'group_count' => $routerRows->count(),
                    'router_count' => $routerRows->count(),
                    'site_count' => 0,
                    'technician_count' => $routerRows->isNotEmpty() ? 1 : 0,
                    'batch_count' => $routerRows->pluck('batch_number')->filter()->unique()->count(),
                    'latest_assignment_at' => $routerRows
                        ->map(fn (array $row) => trim((string) ($row['assigned_date'] ?? '')))
                        ->filter()
                        ->sortDesc()
                        ->first(),
                    'technician_name' => trim((string) ($first['technician_name'] ?? '')),
                ],
            ];
        }

        $withTechs = $this->fetchSection($routerApi, 'with-techs', $filters, true);
        $rows = collect($withTechs['rows'] ?? []);
        $inventoryIndex = $this->buildInventoryIndex(['with-techs' => $rows]);
        $groups = $this->buildTechnicianGroups($rows, $inventoryIndex);
        $paginator = $this->makeCollectionPaginator($groups, $filters['page_size'], $request);

        return [
            $this->combineGroupedResults([$withTechs], $paginator),
            [
                'type' => 'technicians',
                'paginator' => $paginator,
                'focus' => null,
                'focus_key' => null,
                'group_count' => $groups->count(),
                'router_count' => $rows->count(),
                'site_count' => 0,
                'technician_count' => $groups->count(),
            ],
        ];
    }

    private function buildBatchGroups(array $collections, array $inventoryIndex): Collection
    {
        $groups = [];

        foreach ($collections as $state => $rows) {
            foreach ($rows as $row) {
                $row = (array) $row;
                $batchNumber = trim((string) ($row['batch_number'] ?? ''));
                if ($batchNumber === '') {
                    continue;
                }

                if (!isset($groups[$batchNumber])) {
                    $groups[$batchNumber] = [
                        'batch_number' => $batchNumber,
                        'brand' => trim((string) ($row['brand'] ?? '')),
                        'model_number' => trim((string) ($row['model_number'] ?? '')),
                        'quantity' => 0,
                        'available_count' => 0,
                        'with_techs_count' => 0,
                        'deployed_count' => 0,
                        'faulty_count' => 0,
                        'received_at' => null,
                        'latest_activity_at' => null,
                        'routers' => [],
                        'serials' => [],
                        'technicians' => [],
                        'sites' => [],
                    ];
                }

                $serial = $this->serialFromRow($row);
                $groups[$batchNumber]['routers'][] = $this->decorateRow($row, $state, $inventoryIndex);
                $groups[$batchNumber]['latest_activity_at'] = $this->latestTimestamp(
                    $groups[$batchNumber]['latest_activity_at'],
                    $this->rowTimestamp($row)
                );

                if ($serial !== null) {
                    $groups[$batchNumber]['serials'][$serial] = true;
                }

                if ($groups[$batchNumber]['brand'] === '' && trim((string) ($row['brand'] ?? '')) !== '') {
                    $groups[$batchNumber]['brand'] = trim((string) $row['brand']);
                }

                if ($groups[$batchNumber]['model_number'] === '' && trim((string) ($row['model_number'] ?? '')) !== '') {
                    $groups[$batchNumber]['model_number'] = trim((string) $row['model_number']);
                }

                if ($state === 'in-stock') {
                    $groups[$batchNumber]['available_count']++;
                    $groups[$batchNumber]['received_at'] = $this->earliestTimestamp(
                        $groups[$batchNumber]['received_at'],
                        (string) ($row['created_at'] ?? '')
                    );
                } elseif ($state === 'with-techs') {
                    $groups[$batchNumber]['with_techs_count']++;
                    $techName = trim((string) ($row['technician_name'] ?? ''));
                    if ($techName !== '') {
                        $groups[$batchNumber]['technicians'][$techName] = $techName;
                    }
                } elseif ($state === 'deployed') {
                    $groups[$batchNumber]['deployed_count']++;
                    $siteName = trim((string) ($row['site_name'] ?? ''));
                    if ($siteName !== '') {
                        $groups[$batchNumber]['sites'][$siteName] = $siteName;
                    }
                } elseif ($state === 'faulty') {
                    $groups[$batchNumber]['faulty_count']++;
                }
            }
        }

        return collect($groups)
            ->map(function (array $group) {
                $group['quantity'] = count($group['serials']);
                $group['technicians'] = array_values($group['technicians']);
                $group['sites'] = array_values($group['sites']);
                unset($group['serials']);
                $group['routers'] = collect($group['routers'])
                    ->sortBy([
                        fn (array $row) => array_search($row['_state_key'], ['in-stock', 'with-techs', 'deployed', 'faulty'], true),
                        fn (array $row) => -strtotime((string) ($this->rowTimestamp($row) ?? '1970-01-01 00:00:00')),
                        fn (array $row) => $row['_serial'] ?? '',
                    ])
                    ->values()
                    ->all();

                return $group;
            })
            ->sort(function (array $left, array $right) {
                $time = strcmp((string) ($right['latest_activity_at'] ?? ''), (string) ($left['latest_activity_at'] ?? ''));
                if ($time !== 0) {
                    return $time;
                }

                return strcmp((string) ($right['batch_number'] ?? ''), (string) ($left['batch_number'] ?? ''));
            })
            ->values();
    }

    private function buildTechnicianGroups(Collection $rows, array $inventoryIndex): Collection
    {
        $groups = $rows->map(fn ($row) => $this->decorateRow((array) $row, 'with-techs', $inventoryIndex))
            ->groupBy(function (array $row) {
                $techId = trim((string) ($row['technician_id'] ?? ''));
                if ($techId !== '') {
                    return 'tech:' . $techId;
                }

                $techName = trim((string) ($row['technician_name'] ?? ''));
                return $techName !== '' ? 'name:' . mb_strtolower($techName) : 'unassigned';
            })
            ->map(function (Collection $techRows) {
                $first = (array) $techRows->first();

                return [
                    'technician_key' => trim((string) ($first['technician_id'] ?? '')) !== ''
                        ? (string) $first['technician_id']
                        : trim((string) ($first['technician_name'] ?? 'Unknown')),
                    'technician_name' => trim((string) ($first['technician_name'] ?? 'Unknown')),
                    'technician_phone' => trim((string) ($first['technician_phone'] ?? '')),
                    'technician_email' => trim((string) ($first['technician_email'] ?? '')),
                    'router_count' => $techRows->count(),
                    'overdue_count' => $techRows->filter(fn (array $row) => mb_strtolower((string) ($row['urgency'] ?? '')) === 'overdue')->count(),
                    'latest_activity_at' => $techRows
                        ->map(fn (array $row) => $this->rowTimestamp($row))
                        ->filter()
                        ->sortDesc()
                        ->first(),
                    'routers' => $techRows->sortBy(fn (array $row) => $row['_serial'] ?? '')->values()->all(),
                ];
            });

        return $groups
            ->sort(function (array $left, array $right) {
                $time = strcmp((string) ($right['latest_activity_at'] ?? ''), (string) ($left['latest_activity_at'] ?? ''));
                if ($time !== 0) {
                    return $time;
                }

                $count = ($right['router_count'] ?? 0) <=> ($left['router_count'] ?? 0);
                if ($count !== 0) {
                    return $count;
                }

                return strcmp(
                    mb_strtolower((string) ($left['technician_name'] ?? '')),
                    mb_strtolower((string) ($right['technician_name'] ?? ''))
                );
            })
            ->values();
    }

    private function appendSearchedBatchGroup(
        Collection $groups,
        SkybrixRouterApiService $routerApi,
        string $batchNumber,
        array $inventoryIndex
    ): Collection {
        $batchNumber = trim($batchNumber);
        if ($batchNumber === '') {
            return $groups;
        }

        $exists = $groups->contains(fn (array $group) => (string) ($group['batch_number'] ?? '') === $batchNumber);
        if ($exists) {
            return $groups;
        }

        $lookup = $this->buildBatchSearchLookup($routerApi, [$batchNumber], $inventoryIndex, true);
        $batchLookup = $lookup[$batchNumber] ?? null;
        if (!$this->hasBatchSearchData($batchLookup)) {
            return $groups;
        }

        return $groups->prepend($this->makeBatchGroupFromLookup($batchNumber, $batchLookup));
    }

    private function buildBatchSearchLookup(
        SkybrixRouterApiService $routerApi,
        array $batchNumbers,
        array $inventoryIndex,
        bool $includeRows
    ): array {
        $lookups = [];

        foreach (collect($batchNumbers)->filter()->unique()->values() as $batchNumber) {
            $lookups[$batchNumber] = [
                'with-techs' => $this->loadBatchSearchState($routerApi, 'with-techs', (string) $batchNumber, $inventoryIndex, $includeRows),
                'deployed' => $this->loadBatchSearchState($routerApi, 'deployed', (string) $batchNumber, $inventoryIndex, $includeRows),
            ];
        }

        return $lookups;
    }

    private function loadBatchSearchState(
        SkybrixRouterApiService $routerApi,
        string $state,
        string $batchNumber,
        array $inventoryIndex,
        bool $includeRows
    ): array {
        $cacheKey = implode(':', [
            'inventory',
            'router-batch-search',
            $state,
            $includeRows ? 'rows' : 'summary',
            md5($batchNumber),
        ]);

        return Cache::remember($cacheKey, now()->addSeconds(45), function () use (
            $routerApi,
            $state,
            $batchNumber,
            $inventoryIndex,
            $includeRows
        ) {
            $data = $this->fetchSection($routerApi, $state, [
                'page' => 1,
                'page_size' => 1,
                'search' => $batchNumber,
                'batch_number' => '',
                'technician_id' => 0,
                'site_id' => 0,
                'focus_batch' => '',
                'focus_site' => '',
                'is_primary' => null,
            ], $includeRows);

            $rows = collect($data['rows'] ?? [])
                ->map(function ($row) use ($state, $inventoryIndex, $batchNumber) {
                    $row = (array) $row;
                    $row['batch_number'] = trim((string) ($row['batch_number'] ?? '')) !== ''
                        ? (string) $row['batch_number']
                        : $batchNumber;

                    return $this->decorateRow($row, $state, $inventoryIndex);
                })
                ->values();

            return [
                'available' => (bool) ($data['available'] ?? false),
                'count' => max(0, (int) ($data['pagination']['total'] ?? $rows->count())),
                'latest_activity_at' => $rows
                    ->map(fn (array $row) => $this->rowTimestamp($row))
                    ->filter()
                    ->sortDesc()
                    ->first(),
                'brand' => trim((string) ($rows->first()['brand'] ?? '')),
                'model_number' => trim((string) ($rows->first()['model_number'] ?? '')),
                'technicians' => $state === 'with-techs'
                    ? $rows->pluck('technician_name')->filter()->unique()->values()->all()
                    : [],
                'sites' => $state === 'deployed'
                    ? $rows->pluck('site_name')->filter()->unique()->values()->all()
                    : [],
                'rows' => $includeRows ? $rows->all() : [],
            ];
        });
    }

    private function hasBatchSearchData(?array $lookup): bool
    {
        if (!$lookup) {
            return false;
        }

        foreach (['with-techs', 'deployed'] as $state) {
            if ((int) ($lookup[$state]['count'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    private function makeBatchGroupFromLookup(string $batchNumber, array $lookup): array
    {
        $group = [
            'batch_number' => $batchNumber,
            'brand' => '',
            'model_number' => '',
            'quantity' => 0,
            'available_count' => 0,
            'with_techs_count' => 0,
            'deployed_count' => 0,
            'faulty_count' => 0,
            'received_at' => null,
            'latest_activity_at' => null,
            'routers' => [],
            'technicians' => [],
            'sites' => [],
        ];

        return $this->applyBatchSearchLookup($group, $lookup, true);
    }

    private function applyBatchSearchLookup(array $group, ?array $lookup, bool $mergeRows): array
    {
        if (!$lookup) {
            return $group;
        }

        foreach (['with-techs' => 'with_techs_count', 'deployed' => 'deployed_count'] as $state => $countKey) {
            $stateLookup = (array) ($lookup[$state] ?? []);
            if (!($stateLookup['available'] ?? false)) {
                continue;
            }

            $group[$countKey] = max(0, (int) ($stateLookup['count'] ?? 0));
            $group['latest_activity_at'] = $this->latestTimestamp(
                $group['latest_activity_at'] ?? null,
                $stateLookup['latest_activity_at'] ?? null
            );

            if (($group['brand'] ?? '') === '' && trim((string) ($stateLookup['brand'] ?? '')) !== '') {
                $group['brand'] = trim((string) $stateLookup['brand']);
            }

            if (($group['model_number'] ?? '') === '' && trim((string) ($stateLookup['model_number'] ?? '')) !== '') {
                $group['model_number'] = trim((string) $stateLookup['model_number']);
            }
        }

        $group['quantity'] = max(0,
            (int) ($group['available_count'] ?? 0)
            + (int) ($group['with_techs_count'] ?? 0)
            + (int) ($group['deployed_count'] ?? 0)
            + (int) ($group['faulty_count'] ?? 0)
        );

        if (!$mergeRows) {
            return $group;
        }

        $group['technicians'] = $this->mergeLookupValues(
            $group['technicians'] ?? [],
            (array) (($lookup['with-techs']['technicians'] ?? []))
        );
        $group['sites'] = $this->mergeLookupValues(
            $group['sites'] ?? [],
            (array) (($lookup['deployed']['sites'] ?? []))
        );

        $staticRows = collect($group['routers'] ?? [])
            ->map(fn ($row) => (array) $row)
            ->reject(function (array $row) {
                return in_array((string) ($row['_state_key'] ?? ''), ['with-techs', 'deployed'], true);
            });

        $group['routers'] = $staticRows
            ->concat($lookup['with-techs']['rows'] ?? [])
            ->concat($lookup['deployed']['rows'] ?? [])
            ->sortBy([
                fn (array $row) => array_search($row['_state_key'], ['in-stock', 'with-techs', 'deployed', 'faulty'], true),
                fn (array $row) => -strtotime((string) ($this->rowTimestamp($row) ?? '1970-01-01 00:00:00')),
                fn (array $row) => $row['_serial'] ?? '',
            ])
            ->values()
            ->all();

        return $group;
    }

    private function mergeLookupValues(array $existing, array $incoming): array
    {
        return collect($existing)
            ->concat($incoming)
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function buildInventoryIndex(array $collections): array
    {
        $serials = collect($collections)
            ->flatMap(function ($rows) {
                return collect($rows)->map(fn ($row) => $this->serialFromRow((array) $row));
            })
            ->filter()
            ->unique()
            ->values();

        if ($serials->isEmpty()) {
            return [];
        }

        try {
            return ItemUnit::query()
                ->with(['item:id,name'])
                ->whereIn('serial_no', $serials->all())
                ->get()
                ->mapWithKeys(function (ItemUnit $unit) {
                    return [
                        $unit->serial_no => [
                            'unit_id' => $unit->id,
                            'item_id' => $unit->item_id,
                            'item_name' => $unit->item?->name,
                            'status' => $unit->status,
                            'assigned_to' => $unit->assigned_to,
                            'deployed_site_code' => $unit->deployed_site_code,
                            'deployed_site_name' => $unit->deployed_site_name,
                        ],
                    ];
                })
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function decorateRow(array $row, string $state, array $inventoryIndex): array
    {
        $serial = $this->serialFromRow($row);

        return $row + [
            '_state_key' => $state,
            '_state_label' => self::STATE_LABELS[$state] ?? ucfirst(str_replace('-', ' ', $state)),
            '_serial' => $serial,
            '_inventory' => $serial !== null ? ($inventoryIndex[$serial] ?? null) : null,
        ];
    }

    private function fetchSection(SkybrixRouterApiService $routerApi, string $section, array $filters, bool $allPages): array
    {
        $params = $filters;
        $params['page'] = 1;

        if ($allPages) {
            $params['page_size'] = max(1, (int) config('inventory.router_api.max_page_size', 100));
        }

        $first = $this->callSection($routerApi, $section, $params);
        if (!$allPages || !($first['available'] ?? false)) {
            return $first;
        }

        $rows = collect($first['rows'] ?? []);
        $pageCount = max(1, (int) ($first['pagination']['page_count'] ?? 1));
        $pageLimit = max(0, (int) config('inventory.router_api.collection_page_limit', 0));
        $finalPage = $pageLimit > 0 ? min($pageCount, $pageLimit) : $pageCount;

        $messages = [];
        if ($pageLimit > 0 && $pageCount > $pageLimit) {
            $messages[] = 'Only the first ' . $pageLimit . ' pages were loaded for this grouped view.';
        }

        for ($page = 2; $page <= $finalPage; $page++) {
            $next = $this->callSection($routerApi, $section, array_merge($params, ['page' => $page]));
            if (!($next['available'] ?? false)) {
                $messages[] = (string) ($next['message'] ?? 'A Skybrix page could not be loaded.');
                break;
            }

            $rows = $rows->concat($next['rows'] ?? []);
        }

        $first['rows'] = $rows->values()->all();
        $first['pagination'] = [
            'current_page' => 1,
            'per_page' => max(1, (int) ($params['page_size'] ?? 100)),
            'total' => $rows->count(),
            'page_count' => 1,
        ];

        if ($messages !== []) {
            $first['message'] = trim(((string) ($first['message'] ?? '')) . ' ' . implode(' ', $messages));
        }

        return $first;
    }

    private function callSection(SkybrixRouterApiService $routerApi, string $section, array $filters): array
    {
        return match ($section) {
            'batches' => $routerApi->inStockRouters($filters),
            'with-techs' => $routerApi->routersWithTechs($filters),
            'deployed' => $routerApi->deployedRouters($filters),
            'faulty' => $routerApi->faultyRouters($filters),
            'sites' => $routerApi->sites($filters),
            default => $routerApi->inStockRouters($filters),
        };
    }

    private function combineGroupedResults(array $results, LengthAwarePaginator $paginator): array
    {
        $messages = collect($results)
            ->pluck('message')
            ->filter(fn ($message) => is_string($message) && trim($message) !== '')
            ->unique()
            ->values();

        return [
            'available' => collect($results)->every(fn ($result) => (bool) ($result['available'] ?? false)),
            'message' => $messages->implode(' '),
            'fetched_at' => now()->toIso8601String(),
            'rows' => [],
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'page_count' => $paginator->lastPage(),
            ],
            'request' => [
                'section' => null,
                'url' => null,
                'params' => [],
            ],
        ];
    }

    private function makeApiPaginator(Collection $rows, array $pagination, Request $request): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $rows,
            max(0, (int) ($pagination['total'] ?? $rows->count())),
            max(1, (int) ($pagination['per_page'] ?? max($rows->count(), 1))),
            max(1, (int) ($pagination['current_page'] ?? 1)),
            [
                'path' => route('inventory.routers.index'),
                'query' => Arr::except($request->query(), 'page'),
                'pageName' => 'page',
            ]
        );
    }

    private function makeCollectionPaginator(Collection $items, int $perPage, Request $request): LengthAwarePaginator
    {
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = max(1, $perPage);
        $slice = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $slice,
            $items->count(),
            $perPage,
            $page,
            [
                'path' => route('inventory.routers.index'),
                'query' => Arr::except($request->query(), 'page'),
                'pageName' => 'page',
            ]
        );
    }

    private function earliestTimestamp(?string $current, string $candidate): ?string
    {
        if (trim($candidate) === '') {
            return $current;
        }

        if ($current === null || strtotime($candidate) < strtotime($current)) {
            return $candidate;
        }

        return $current;
    }

    private function latestTimestamp(?string $current, ?string $candidate): ?string
    {
        if ($candidate === null || trim($candidate) === '') {
            return $current;
        }

        if ($current === null || strtotime($candidate) > strtotime($current)) {
            return $candidate;
        }

        return $current;
    }

    private function rowTimestamp(array $row): ?string
    {
        foreach ([
            'installed_date',
            'assigned_date',
            'updated_at',
            'created_at',
            'acs_last_inform',
            'reg_date',
            'expected_deployment_date',
        ] as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function serialFromRow(array $row): ?string
    {
        $serial = trim((string) ($row['serial_number'] ?? $row['serial'] ?? ''));

        return $serial !== '' ? $serial : null;
    }

    private function normalizeSection(string $section): string
    {
        return array_key_exists($section, self::SECTIONS) ? $section : 'batches';
    }
}
