<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\InventoryUser;

class InventoryAccess
{
    private static ?bool $permissionsColumnExists = null;
    private static ?array $allPermissionKeys = null;
    /** @var array<int,array<int,string>> */
    private static array $resolvedPermissionsByUser = [];

    /**
     * @return array<string,string>
     */
    public static function roleOptions(): array
    {
        return [
            'admin' => 'Admin',
            'manager' => 'Manager',
            'technician' => 'Technician',
            'viewer' => 'Viewer',
        ];
    }

    public static function normalizeRole(?string $role): string
    {
        $normalized = strtolower(trim((string) $role));
        $normalized = str_replace([' ', '-'], '_', $normalized);

        return match ($normalized) {
            'operations', 'supervisor' => 'manager',
            'read_only' => 'viewer',
            default => $normalized,
        };
    }

    public static function roleLabel(?string $role): string
    {
        $normalized = self::normalizeRole($role);

        return self::roleOptions()[$normalized] ?? ucfirst(str_replace('_', ' ', $normalized));
    }

    public static function isAdmin(?InventoryUser $user): bool
    {
        return self::normalizeRole($user?->inventory_role) === 'admin';
    }

    /**
     * @return array<string,array{label:string,actions:array<string,string>}>
     */
    public static function permissionCatalog(): array
    {
        return [
            'dashboard' => [
                'label' => 'Dashboard',
                'actions' => [
                    'view' => 'View the main dashboard',
                ],
            ],
            'routers' => [
                'label' => 'Routers',
                'actions' => [
                    'view' => 'View router workspaces and live router feeds',
                ],
            ],
            'alerts' => [
                'label' => 'Alerts',
                'actions' => [
                    'view' => 'View low stock alerts',
                ],
            ],
            'items' => [
                'label' => 'Items',
                'actions' => [
                    'view' => 'View items',
                    'manage' => 'Create and edit items',
                ],
            ],
            'item_groups' => [
                'label' => 'Item Groups',
                'actions' => [
                    'view' => 'View item groups',
                    'manage' => 'Create and edit item groups',
                ],
            ],
            'receipts' => [
                'label' => 'Receipts',
                'actions' => [
                    'view' => 'View stock receipts',
                    'manage' => 'Receive stock and create receipts',
                ],
            ],
            'assignments' => [
                'label' => 'Tech Assignments',
                'actions' => [
                    'view' => 'View technician assignments',
                    'manage' => 'Assign and update technician stock',
                ],
            ],
            'deployments' => [
                'label' => 'Deployments',
                'actions' => [
                    'view' => 'View deployments and site workspaces',
                    'manage' => 'Deploy routers/items and update deployment flows',
                    'admin_deploy' => 'Use the admin deployment workspace',
                ],
            ],
            'movements' => [
                'label' => 'Transfers / Returns',
                'actions' => [
                    'view' => 'View transfer and return pages',
                    'manage' => 'Transfer and return stock',
                ],
            ],
            'logs' => [
                'label' => 'Logs',
                'actions' => [
                    'view' => 'View inventory audit logs',
                ],
            ],
            'teams' => [
                'label' => 'Teams',
                'actions' => [
                    'view' => 'View teams',
                    'manage' => 'Create/edit teams and team members',
                ],
            ],
            'team_assignments' => [
                'label' => 'Team Assignments',
                'actions' => [
                    'view' => 'View team assignment pages',
                    'manage' => 'Assign stock to teams',
                ],
            ],
            'team_deployments' => [
                'label' => 'Team Deployments',
                'actions' => [
                    'view' => 'View team deployment pages',
                    'manage' => 'Deploy stock from teams',
                ],
            ],
            'tech_inventory' => [
                'label' => 'Technician Inventory',
                'actions' => [
                    'view' => 'View technician assigned inventory and deploy pages',
                ],
            ],
            'profile' => [
                'label' => 'Profile',
                'actions' => [
                    'view' => 'Use account tools like password change',
                ],
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function routePermissions(): array
    {
        return [
            'inventory.dashboard' => 'dashboard.view',
            'inventory.dashboard.alt' => 'dashboard.view',

            'inventory.routers.index' => 'routers.view',

            'inventory.alerts.low_stock' => 'alerts.view',

            'inventory.items.index' => 'items.view',
            'inventory.items.create' => 'items.manage',
            'inventory.items.store' => 'items.manage',
            'inventory.items.edit' => 'items.manage',
            'inventory.items.update' => 'items.manage',
            'inventory.items.destroy' => 'items.manage',

            'inventory.item-groups.index' => 'item_groups.view',
            'inventory.item-groups.create' => 'item_groups.manage',
            'inventory.item-groups.store' => 'item_groups.manage',
            'inventory.item-groups.edit' => 'item_groups.manage',
            'inventory.item-groups.update' => 'item_groups.manage',
            'inventory.item-groups.destroy' => 'item_groups.manage',

            'inventory.receipts.index' => 'receipts.view',
            'inventory.receipts.show' => 'receipts.view',
            'inventory.receipts.create' => 'receipts.manage',
            'inventory.receipts.store' => 'receipts.manage',

            'inventory.assignments.index' => 'assignments.view',
            'inventory.assignments.store' => 'assignments.manage',
            'inventory.assignments.update' => 'assignments.manage',
            'inventory.assignments.destroy' => 'assignments.manage',

            'inventory.deployments.index' => 'deployments.view',
            'inventory.deployments.store' => 'deployments.manage',
            'inventory.admin.deploy.create' => 'deployments.admin_deploy',

            'inventory.logs.index' => 'logs.view',

            'inventory.movements.index' => 'movements.view',
            'inventory.movements.transfer' => 'movements.manage',
            'inventory.movements.return_to_store' => 'movements.manage',
            'inventory.movements.store' => 'movements.manage',

            'inventory.teams.index' => 'teams.view',
            'inventory.teams.create' => 'teams.manage',
            'inventory.teams.store' => 'teams.manage',
            'inventory.teams.edit' => 'teams.manage',
            'inventory.teams.update' => 'teams.manage',
            'inventory.teams.destroy' => 'teams.manage',
            'inventory.teams.members.store' => 'teams.manage',
            'inventory.teams.members.destroy' => 'teams.manage',

            'inventory.team_assignments.index' => 'team_assignments.view',
            'inventory.team_assignments.store' => 'team_assignments.manage',

            'inventory.team_deployments.index' => 'team_deployments.view',
            'inventory.team_deployments.store' => 'team_deployments.manage',

            'inventory.tech.dashboard' => 'tech_inventory.view',
            'inventory.tech.items.index' => 'tech_inventory.view',
            'inventory.tech.items.show' => 'tech_inventory.view',
            'inventory.tech.sites.lookup' => 'tech_inventory.view',
        ];
    }

    public static function permissionForRoute(?string $routeName): ?string
    {
        if ($routeName === null || $routeName === '') {
            return null;
        }

        return self::routePermissions()[$routeName] ?? null;
    }

    public static function supportsExplicitPermissions(): bool
    {
        return self::permissionsColumnExists();
    }

    /**
     * @return array<int,string>
     */
    public static function allPermissionKeys(): array
    {
        if (self::$allPermissionKeys !== null) {
            return self::$allPermissionKeys;
        }

        $keys = [];

        foreach (self::permissionCatalog() as $pageKey => $pageMeta) {
            foreach (array_keys((array) ($pageMeta['actions'] ?? [])) as $actionKey) {
                $keys[] = $pageKey . '.' . $actionKey;
            }
        }

        self::$allPermissionKeys = array_values(array_unique($keys));

        return self::$allPermissionKeys;
    }

    /**
     * @param array<int,mixed> $permissions
     * @return array<int,string>
     */
    public static function normalizePermissionList(array $permissions): array
    {
        $allowed = array_flip(self::allPermissionKeys());
        $normalized = [];

        foreach ($permissions as $permission) {
            $key = strtolower(trim((string) $permission));
            if ($key === '' || !isset($allowed[$key])) {
                continue;
            }

            $normalized[$key] = $key;
        }

        return array_values($normalized);
    }

    /**
     * @param array<int,mixed> $permissions
     * @return array<int,string>
     */
    public static function withImplicitViewPermissions(array $permissions): array
    {
        $normalized = self::normalizePermissionList($permissions);
        $map = array_fill_keys($normalized, true);
        $catalog = self::permissionCatalog();

        foreach ($normalized as $permission) {
            [$page, $action] = array_pad(explode('.', $permission, 2), 2, '');
            if ($page === '' || $action === '' || $action === 'view') {
                continue;
            }

            if (isset($catalog[$page]['actions']['view'])) {
                $map[$page . '.view'] = true;
            }
        }

        return array_keys($map);
    }

    /**
     * @return array<int,string>
     */
    public static function defaultPermissionsForRole(?string $role): array
    {
        return match (self::normalizeRole($role)) {
            'admin' => self::allPermissionKeys(),
            'manager' => [
                'dashboard.view',
                'routers.view',
                'alerts.view',
                'items.view',
                'items.manage',
                'item_groups.view',
                'item_groups.manage',
                'receipts.view',
                'receipts.manage',
                'assignments.view',
                'assignments.manage',
                'deployments.view',
                'deployments.manage',
                'deployments.admin_deploy',
                'movements.view',
                'movements.manage',
                'logs.view',
                'teams.view',
                'teams.manage',
                'team_assignments.view',
                'team_assignments.manage',
                'team_deployments.view',
                'team_deployments.manage',
                'profile.view',
            ],
            'technician' => [
                'tech_inventory.view',
                'routers.view',
                'deployments.manage',
                'profile.view',
            ],
            default => [
                'dashboard.view',
                'routers.view',
                'items.view',
                'item_groups.view',
                'receipts.view',
                'assignments.view',
                'deployments.view',
                'logs.view',
                'teams.view',
                'team_assignments.view',
                'team_deployments.view',
                'profile.view',
            ],
        };
    }

    /**
     * @return array<int,string>|null
     */
    public static function explicitPermissionsForUser(?InventoryUser $user): ?array
    {
        if (!$user || !self::permissionsColumnExists()) {
            return null;
        }

        $permissions = is_array($user->inventory_permissions)
            ? $user->inventory_permissions
            : [];

        if ($permissions === []) {
            return null;
        }

        return self::normalizePermissionList($permissions);
    }

    /**
     * @return array<int,string>
     */
    public static function permissionsForUser(?InventoryUser $user): array
    {
        if (!$user) {
            return [];
        }

        $userId = (int) ($user->id ?? 0);
        if ($userId > 0 && isset(self::$resolvedPermissionsByUser[$userId])) {
            return self::$resolvedPermissionsByUser[$userId];
        }

        if (self::isAdmin($user)) {
            $all = self::allPermissionKeys();
            if ($userId > 0) {
                self::$resolvedPermissionsByUser[$userId] = $all;
            }

            return $all;
        }

        $explicit = self::explicitPermissionsForUser($user);
        $resolved = $explicit ?? self::defaultPermissionsForRole((string) $user->inventory_role);

        if (!in_array('profile.view', $resolved, true)) {
            $resolved[] = 'profile.view';
        }

        $resolved = self::normalizePermissionList($resolved);

        if ($userId > 0) {
            self::$resolvedPermissionsByUser[$userId] = $resolved;
        }

        return $resolved;
    }

    public static function allows(?InventoryUser $user, ?string $permission): bool
    {
        if (!$user) {
            return false;
        }

        $permissionKey = strtolower(trim((string) $permission));
        if ($permissionKey === '') {
            return true;
        }

        if (self::isAdmin($user)) {
            return true;
        }

        return in_array($permissionKey, self::permissionsForUser($user), true);
    }

    public static function landingRouteName(?InventoryUser $user): ?string
    {
        if (!$user) {
            return null;
        }

        $candidates = [
            'dashboard.view' => 'inventory.dashboard',
            'tech_inventory.view' => 'inventory.tech.dashboard',
            'routers.view' => 'inventory.routers.index',
            'items.view' => 'inventory.items.index',
            'receipts.view' => 'inventory.receipts.index',
            'deployments.view' => 'inventory.deployments.index',
            'logs.view' => 'inventory.logs.index',
            'teams.view' => 'inventory.teams.index',
        ];

        foreach ($candidates as $permission => $routeName) {
            if (self::allows($user, $permission)) {
                return $routeName;
            }
        }

        return null;
    }

    public static function forgetResolvedPermissions(?InventoryUser $user = null): void
    {
        if ($user === null) {
            self::$resolvedPermissionsByUser = [];
            return;
        }

        $userId = (int) ($user->id ?? 0);
        if ($userId > 0) {
            unset(self::$resolvedPermissionsByUser[$userId]);
        }
    }

    private static function permissionsColumnExists(): bool
    {
        if (self::$permissionsColumnExists !== true) {
            self::$permissionsColumnExists = InventoryDatabase::schema()->hasTable('inventory_users')
                && InventoryDatabase::schema()->hasColumn('inventory_users', 'inventory_permissions');
        }

        return self::$permissionsColumnExists;
    }
}
