<?php

namespace App\Modules\Inventory\Http\Controllers\Inventory;

use App\Modules\Inventory\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Department;
use App\Modules\Inventory\Models\InventoryActivityLog;
use App\Modules\Inventory\Models\InventoryUser;
use App\Modules\Inventory\Support\InventoryAccess;
use App\Modules\Inventory\Support\InventoryActivity;
use App\Modules\Inventory\Support\ApiResponder;
use App\Modules\Inventory\Support\InventoryDatabase;
use App\Services\Sms\AdvantaSmsService;
use App\Services\Sms\AmazonsSmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SettingsController extends Controller
{
    use ApiResponder;

    public function index(Request $request)
    {
        $currentUser = auth('inventory')->user();
        abort_unless(InventoryAccess::isAdmin($currentUser), 403);
        $permissionsSupported = InventoryAccess::supportsExplicitPermissions();

        $users = InventoryUser::query()
            ->with('department')
            ->orderByDesc('inventory_enabled')
            ->orderBy('name')
            ->get();

        $departments = Department::query()
            ->orderBy('name')
            ->get();

        $selectedUserId = (int) $request->integer('user', 0);
        $selectedUser = $selectedUserId > 0
            ? $users->firstWhere('id', $selectedUserId)
            : null;

        $selectedExplicitPermissions = [];
        $selectedEffectivePermissions = [];

        if ($selectedUser) {
            $selectedExplicitPermissions = InventoryAccess::explicitPermissionsForUser($selectedUser) ?? [];
            $selectedEffectivePermissions = InventoryAccess::permissionsForUser($selectedUser);
        }

        $supportsPhoneNo = InventoryDatabase::schema()->hasColumn('inventory_users', 'phone_no');
        $supportsLoginTracking = InventoryDatabase::schema()->hasColumn('inventory_users', 'last_login_at');
        $supportsLoginSmsTracking = InventoryDatabase::schema()->hasColumn('inventory_users', 'login_sms_sent_at');

        $activityLogs = collect();
        if ($selectedUser && InventoryDatabase::schema()->hasTable('inventory_activity_logs')) {
            $activityLogs = InventoryActivityLog::query()
                ->where('inventory_user_id', $selectedUser->id)
                ->latest()
                ->limit(60)
                ->get();
        }

        if ($request->expectsJson()) {
            return $this->successResponse([
                'users' => $users,
                'departments' => $departments,
                'selected_user' => $selectedUser,
                'role_options' => InventoryAccess::roleOptions(),
                'permission_catalog' => InventoryAccess::permissionCatalog(),
                'selected_explicit_permissions' => $selectedExplicitPermissions,
                'selected_effective_permissions' => $selectedEffectivePermissions,
                'permissions_supported' => $permissionsSupported,
                'supports_phone_no' => $supportsPhoneNo,
                'supports_login_tracking' => $supportsLoginTracking,
                'supports_login_sms_tracking' => $supportsLoginSmsTracking,
                'activity_logs' => $activityLogs,
                'default_password' => $this->defaultPassword(),
            ]);
        }

        return view('inventory::settings.index', [
            'users' => $users,
            'departments' => $departments,
            'selectedUser' => $selectedUser,
            'roleOptions' => InventoryAccess::roleOptions(),
            'permissionCatalog' => InventoryAccess::permissionCatalog(),
            'selectedExplicitPermissions' => $selectedExplicitPermissions,
            'selectedEffectivePermissions' => $selectedEffectivePermissions,
            'permissionsSupported' => $permissionsSupported,
            'supportsPhoneNo' => $supportsPhoneNo,
            'supportsLoginTracking' => $supportsLoginTracking,
            'supportsLoginSmsTracking' => $supportsLoginSmsTracking,
            'activityLogs' => $activityLogs,
            'defaultPassword' => $this->defaultPassword(),
        ]);
    }

    public function updateUser(Request $request, InventoryUser $user)
    {
        $currentUser = auth('inventory')->user();
        abort_unless(InventoryAccess::isAdmin($currentUser), 403);

        $roleValues = array_keys(InventoryAccess::roleOptions());
        $supportsPhoneNo = InventoryDatabase::schema()->hasColumn('inventory_users', 'phone_no');

        $data = $request->validateWithBag('updateUser', [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:inventory_users,email,' . $user->id],
            'phone_no' => $supportsPhoneNo
                ? ['nullable', 'string', 'max:32']
                : ['nullable'],
            'department_id' => ['nullable', 'integer', 'exists:inventory_departments,id'],
            'role' => ['required', 'string', 'in:' . implode(',', $roleValues)],
            'inventory_enabled' => ['nullable', 'boolean'],
            'inventory_force_password_change' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ]);

        $targetRole = InventoryAccess::normalizeRole((string) $data['role']);
        $targetEnabled = (bool) ($data['inventory_enabled'] ?? false);

        if ((int) $user->id === (int) $currentUser->id && $targetRole !== 'admin') {
            if ($request->expectsJson()) {
                return $this->errorResponse('You cannot remove your own admin role.', 422);
            }

            return back()->with('warning', 'You cannot remove your own admin role.');
        }

        if ((int) $user->id === (int) $currentUser->id && !$targetEnabled) {
            if ($request->expectsJson()) {
                return $this->errorResponse('You cannot disable your own account.', 422);
            }

            return back()->with('warning', 'You cannot disable your own account.');
        }

        $user->fill([
            'name' => trim((string) $data['name']),
            'email' => strtolower(trim((string) $data['email'])),
            'department_id' => $data['department_id'] ?? null,
            'inventory_role' => $targetRole,
            'inventory_enabled' => $targetEnabled,
            'inventory_force_password_change' => (bool) ($data['inventory_force_password_change'] ?? false),
        ]);

        if ($supportsPhoneNo) {
            $user->phone_no = trim((string) ($data['phone_no'] ?? '')) ?: null;
        }

        if (!empty($data['password'])) {
            $user->password = Hash::make((string) $data['password']);
            $user->inventory_force_password_change = true;
            $user->inventory_password_changed_at = now();
        }

        if (InventoryAccess::supportsExplicitPermissions()) {
            if ($targetRole === 'admin') {
                $user->inventory_permissions = null;
            } else {
                $permissions = InventoryAccess::withImplicitViewPermissions((array) ($data['permissions'] ?? []));

                if (!in_array('profile.view', $permissions, true)) {
                    $permissions[] = 'profile.view';
                }

                sort($permissions);
                $user->inventory_permissions = $permissions;
            }
        }

        $user->save();
        InventoryAccess::forgetResolvedPermissions($user);

        InventoryActivity::log($currentUser, 'user_updated', $request, [
            'target_user_id' => $user->id,
        ]);

        $message = InventoryAccess::supportsExplicitPermissions()
            ? 'User access updated.'
            : 'User updated. Custom permissions are unavailable until the inventory permissions migration is applied.';

        if ($request->expectsJson()) {
            return $this->successResponse([
                'user' => $user->fresh(),
            ], $message);
        }

        return redirect()
            ->route('inventory.settings.index', ['user' => $user->id])
            ->with('success', $message);
    }

    public function storeUser(Request $request)
    {
        $currentUser = auth('inventory')->user();
        abort_unless(InventoryAccess::isAdmin($currentUser), 403);

        $roleValues = array_keys(InventoryAccess::roleOptions());
        $supportsPhoneNo = InventoryDatabase::schema()->hasColumn('inventory_users', 'phone_no');

        $data = $request->validateWithBag('createUser', [
            'create.name' => ['required', 'string', 'max:120'],
            'create.email' => ['required', 'email', 'max:255', 'unique:inventory_users,email'],
            'create.phone_no' => $supportsPhoneNo
                ? ['nullable', 'string', 'max:32']
                : ['nullable'],
            'create.department_id' => ['nullable', 'integer', 'exists:inventory_departments,id'],
            'create.role' => ['required', 'string', 'in:' . implode(',', $roleValues)],
            'create.inventory_enabled' => ['nullable', 'boolean'],
            'create.force_password_change' => ['nullable', 'boolean'],
            'create.password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'create.send_login_sms' => ['nullable', 'boolean'],
        ]);

        $payload = (array) ($data['create'] ?? []);
        $role = InventoryAccess::normalizeRole((string) ($payload['role'] ?? 'technician'));
        $plainPassword = trim((string) ($payload['password'] ?? ''));
        $useDefaultPassword = $plainPassword === '';
        if ($useDefaultPassword) {
            $plainPassword = $this->defaultPassword();
        }

        $forcePasswordChange = $useDefaultPassword || (bool) ($payload['force_password_change'] ?? false);

        try {
            $createPayload = [
                'name' => (string) ($payload['name'] ?? ''),
                'email' => strtolower(trim((string) ($payload['email'] ?? ''))),
                'password' => Hash::make($plainPassword),
                'inventory_role' => $role,
                'inventory_enabled' => (bool) ($payload['inventory_enabled'] ?? true),
                'inventory_force_password_change' => $forcePasswordChange,
                'department_id' => $payload['department_id'] ?? null,
            ];
            if ($supportsPhoneNo) {
                $createPayload['phone_no'] = trim((string) ($payload['phone_no'] ?? '')) ?: null;
            }

            $user = InventoryUser::query()->create($createPayload);
        } catch (\Throwable $e) {
            report($e);

            if ($request->expectsJson()) {
                return $this->errorResponse('Unable to create user. Please confirm migrations and try again.', 500);
            }

            return back()->withInput()->with('error', 'Unable to create user. Please confirm migrations and try again.');
        }

        InventoryActivity::log($currentUser, 'user_created', $request, [
            'target_user_id' => $user->id,
        ]);

        $sendLoginSms = (bool) ($payload['send_login_sms'] ?? false);
        if ($sendLoginSms) {
            $smsResult = $this->sendLoginSmsWithPassword($user, $plainPassword);
            $smsMessage = (string) ($smsResult['message'] ?? '');
            $smsSent = (bool) ($smsResult['sent'] ?? false);

            if ($smsSent) {
                InventoryActivity::log($currentUser, 'login_sms_sent', $request, [
                    'target_user_id' => $user->id,
                    'source' => 'create_user',
                ]);
            }

            if ($request->expectsJson()) {
                return $this->successResponse([
                    'user' => $user,
                    'sms_sent' => $smsSent,
                    'sms_message' => $smsMessage,
                    'temp_password' => $plainPassword,
                ], $smsSent ? 'User created and login SMS sent.' : 'User created, but login SMS failed.');
            }

            return redirect()
                ->route('inventory.settings.index', ['user' => $user->id])
                ->with($smsSent ? 'success' : 'error', $smsSent
                    ? ('User created and login SMS sent to ' . $user->name . '.')
                    : ('User created, but login SMS failed: ' . ($smsMessage !== '' ? $smsMessage : 'unknown error.'))
                )
                ->with('inventory_temp_password', $plainPassword)
                ->with('inventory_temp_user', $user->id);
        }

        if ($request->expectsJson()) {
            return $this->successResponse([
                'user' => $user,
                'temp_password' => $plainPassword,
            ], 'User created.', 201);
        }

        return redirect()
            ->route('inventory.settings.index', ['user' => $user->id])
            ->with('success', 'User created. You can now update their access.')
            ->with('inventory_temp_password', $plainPassword)
            ->with('inventory_temp_user', $user->id);
    }

    public function sendLoginSms(Request $request, InventoryUser $user)
    {
        $currentUser = auth('inventory')->user();
        abort_unless(InventoryAccess::isAdmin($currentUser), 403);

        $defaultPassword = $this->defaultPassword();
        $oldPasswordHash = (string) $user->password;
        $oldForceChange = (bool) $user->inventory_force_password_change;
        $hasLoginSmsTracking = InventoryDatabase::schema()->hasColumn('inventory_users', 'login_sms_sent_at');
        $oldSmsSentAt = $hasLoginSmsTracking ? $user->login_sms_sent_at : null;

        $user->password = Hash::make($defaultPassword);
        $user->inventory_force_password_change = true;
        $user->inventory_password_changed_at = now();
        $user->save();

        $smsResult = $this->sendLoginSmsWithPassword($user, $defaultPassword);
        $smsMessage = (string) ($smsResult['message'] ?? '');
        $smsSent = (bool) ($smsResult['sent'] ?? false);

        if (!$smsSent) {
            $user->password = $oldPasswordHash;
            $user->inventory_force_password_change = $oldForceChange;
            if ($hasLoginSmsTracking) {
                $user->login_sms_sent_at = $oldSmsSentAt;
            }
            $user->save();

            if ($request->expectsJson()) {
                return $this->errorResponse('Failed to send login SMS: ' . ($smsMessage !== '' ? $smsMessage : 'unknown error.'), 422);
            }

            return redirect()
                ->route('inventory.settings.index', ['user' => $user->id])
                ->with('error', 'Failed to send login SMS: ' . ($smsMessage !== '' ? $smsMessage : 'unknown error.'));
        }

        InventoryActivity::log($currentUser, 'login_sms_sent', $request, [
            'target_user_id' => $user->id,
        ]);

        if ($request->expectsJson()) {
            return $this->successResponse([
                'user' => $user,
                'sms_sent' => true,
                'sms_message' => $smsMessage,
                'temp_password' => $defaultPassword,
            ], 'Login SMS sent.');
        }

        return redirect()
            ->route('inventory.settings.index', ['user' => $user->id])
            ->with('success', 'Login SMS sent to ' . $user->name . '.')
            ->with('inventory_temp_password', $defaultPassword)
            ->with('inventory_temp_user', $user->id);
    }

    public function resetLogin(Request $request, InventoryUser $user)
    {
        $currentUser = auth('inventory')->user();
        abort_unless(InventoryAccess::isAdmin($currentUser), 403);

        $defaultPassword = $this->defaultPassword();
        $user->password = Hash::make($defaultPassword);
        $user->inventory_force_password_change = true;
        $user->inventory_password_changed_at = now();
        $user->save();

        InventoryActivity::log($currentUser, 'login_reset', $request, [
            'target_user_id' => $user->id,
        ]);

        if ($request->expectsJson()) {
            return $this->successResponse([
                'user' => $user,
                'temp_password' => $defaultPassword,
            ], 'Login reset to default password.');
        }

        return redirect()
            ->route('inventory.settings.index', ['user' => $user->id])
            ->with('success', 'Login reset to default password for ' . $user->name . '.')
            ->with('inventory_temp_password', $defaultPassword)
            ->with('inventory_temp_user', $user->id);
    }

    private function defaultPassword(): string
    {
        return (string) config('inventory.default_password', '123456789');
    }

    /**
     * @return array{sent:bool,message:string}
     */
    private function sendLoginSmsWithPassword(InventoryUser $user, string $plainPassword): array
    {
        if (!InventoryDatabase::schema()->hasColumn('inventory_users', 'phone_no')) {
            return [
                'sent' => false,
                'message' => 'phone field is missing (run migrations).',
            ];
        }

        if (!$user->inventory_enabled) {
            return [
                'sent' => false,
                'message' => 'user is disabled.',
            ];
        }

        $phone = $this->normalizePhone((string) ($user->phone_no ?? ''));
        if ($phone === '') {
            return [
                'sent' => false,
                'message' => 'user phone number is missing or invalid.',
            ];
        }

        if (trim($plainPassword) === '') {
            return [
                'sent' => false,
                'message' => 'password was empty.',
            ];
        }

        if (!(bool) config('inventory.sms.enabled', true)) {
            return [
                'sent' => false,
                'message' => 'SMS sending is disabled in inventory settings.',
            ];
        }

        $gateway = strtolower((string) config('inventory.sms.gateway', 'advanta'));
        if (!in_array($gateway, ['advanta', 'amazons'], true)) {
            $gateway = 'advanta';
        }

        $service = $gateway === 'amazons'
            ? app(AmazonsSmsService::class)
            : app(AdvantaSmsService::class);

        $loginUrl = route('inventory.auth.login');
        $displayName = trim((string) ($user->name ?: 'User'));
        $message = sprintf(
            'Hello %s, Inventory login details: Email %s, Password %s, Login link %s',
            $displayName,
            (string) $user->email,
            $plainPassword,
            $loginUrl
        );

        try {
            $response = $service->send($phone, $message);
            if (!$this->isSmsSuccess((array) $response)) {
                throw new \RuntimeException('SMS gateway rejected the message.');
            }
        } catch (\Throwable $e) {
            return [
                'sent' => false,
                'message' => $e->getMessage(),
            ];
        }

        if (InventoryDatabase::schema()->hasColumn('inventory_users', 'login_sms_sent_at')) {
            $user->login_sms_sent_at = now();
            $user->save();
        }

        return [
            'sent' => true,
            'message' => '',
        ];
    }

    private function normalizePhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }

        if (str_starts_with($phone, '+')) {
            $phone = substr($phone, 1);
        }
        $phone = preg_replace('/\D+/', '', $phone) ?: '';

        if (preg_match('/^07\d{8}$/', $phone)) {
            return '254' . substr($phone, 1);
        }
        if (preg_match('/^01\d{8}$/', $phone)) {
            return '254' . substr($phone, 1);
        }

        return $phone;
    }

    private function isSmsSuccess(array $response): bool
    {
        if (array_key_exists('success', $response)) {
            return (bool) $response['success'];
        }

        if (isset($response['responses'][0]['response-code'])) {
            return (string) $response['responses'][0]['response-code'] === '200';
        }

        if (isset($response['response-code'])) {
            return (string) $response['response-code'] === '200';
        }

        if (isset($response['status'])) {
            return in_array(strtolower((string) $response['status']), ['ok', 'success', 'sent'], true);
        }

        return false;
    }
}
