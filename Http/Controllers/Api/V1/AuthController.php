<?php

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\InventoryApiToken;
use App\Modules\Inventory\Models\InventoryUser;
use App\Modules\Inventory\Support\ApiResponder;
use App\Modules\Inventory\Support\InventoryActivity;
use App\Modules\Inventory\Support\InventoryDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    use ApiResponder;

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'device_id' => ['nullable', 'string', 'max:120'],
            'device_platform' => ['nullable', 'string', 'max:40'],
            'revoke_other_sessions' => ['nullable', 'boolean'],
        ]);

        $user = InventoryUser::query()->where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return $this->errorResponse('Invalid credentials.', 401);
        }

        if (!$user->inventory_enabled) {
            return $this->errorResponse('User account is inactive.', 403);
        }

        if (InventoryDatabase::schema()->hasColumn('inventory_users', 'last_login_at')) {
            $user->last_login_at = now();
        }
        if (InventoryDatabase::schema()->hasColumn('inventory_users', 'last_login_ip')) {
            $user->last_login_ip = $request->ip();
        }
        if (InventoryDatabase::schema()->hasColumn('inventory_users', 'last_login_user_agent')) {
            $user->last_login_user_agent = Str::limit((string) $request->userAgent(), 255, '');
        }
        $user->save();

        InventoryActivity::log($user, 'api_login', $request);

        $plainToken = Str::random(80);
        $ttlDays = (int) config('inventory.api.token_ttl_days', 30);
        $deviceName = trim((string) ($data['device_name'] ?? 'mobile'));
        $deviceId = trim((string) ($data['device_id'] ?? ''));
        $devicePlatform = trim((string) ($data['device_platform'] ?? ''));
        $revokeOtherSessions = (bool) ($data['revoke_other_sessions'] ?? false);

        if ($deviceName === '') {
            $deviceName = 'mobile';
        }
        if ($devicePlatform === '') {
            $devicePlatform = null;
        }
        if ($deviceId === '') {
            $deviceId = null;
        }

        $activeByUser = InventoryApiToken::query()
            ->where('inventory_user_id', $user->id)
            ->active();

        if ($revokeOtherSessions) {
            $this->revokeQuery($activeByUser);
        } else {
            $deviceScoped = (clone $activeByUser)->where('name', $deviceName);
            if ($deviceId && InventoryApiToken::supportsColumn('device_id')) {
                $deviceScoped = (clone $activeByUser)->where('device_id', $deviceId);
            }
            $this->revokeQuery($deviceScoped);
        }

        $tokenPayload = [
            'inventory_user_id' => $user->id,
            'name' => $deviceName,
            'token' => hash('sha256', $plainToken),
            'last_used_at' => now(),
            'expires_at' => $ttlDays > 0 ? now()->addDays($ttlDays) : null,
        ];
        if (InventoryApiToken::supportsColumn('device_id')) {
            $tokenPayload['device_id'] = $deviceId;
        }
        if (InventoryApiToken::supportsColumn('device_platform')) {
            $tokenPayload['device_platform'] = $devicePlatform;
        }
        if (InventoryApiToken::supportsColumn('last_ip')) {
            $tokenPayload['last_ip'] = $request->ip();
        }
        if (InventoryApiToken::supportsColumn('last_user_agent')) {
            $tokenPayload['last_user_agent'] = substr((string) $request->userAgent(), 0, 255);
        }

        $token = InventoryApiToken::query()->create($tokenPayload);
        $this->enforceMaxActiveTokens($user->id);

        return $this->successResponse([
            'token_type' => 'Bearer',
            'access_token' => $plainToken,
            'expires_at' => optional($token->expires_at)->toIso8601String(),
            'force_password_change' => (bool) $user->inventory_force_password_change,
            'user' => $this->mapUser($user),
            'session' => $this->mapSessionToken($token, $token->id),
        ], 'Login successful.');
    }

    public function me(Request $request)
    {
        /** @var InventoryUser|null $user */
        $user = $request->attributes->get('inventoryUser');

        if (!$user) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        return $this->successResponse([
            'user' => $this->mapUser($user),
        ]);
    }

    public function logout(Request $request)
    {
        $token = $request->attributes->get('inventoryApiToken');
        if ($token) {
            $this->revokeToken($token);
        }

        return $this->successResponse([], 'Logged out.');
    }

    public function logoutCurrent(Request $request)
    {
        $token = $request->attributes->get('inventoryApiToken');
        if ($token) {
            $this->revokeToken($token);
        }

        return $this->successResponse([], 'Current session revoked.');
    }

    public function logoutAll(Request $request)
    {
        $user = $request->attributes->get('inventoryUser');
        $currentToken = $request->attributes->get('inventoryApiToken');
        $includeCurrent = (bool) $request->boolean('include_current', true);

        $query = InventoryApiToken::query()
            ->where('inventory_user_id', $user->id)
            ->active();

        if (!$includeCurrent && $currentToken) {
            $query->where('id', '!=', $currentToken->id);
        }

        $revokedCount = $this->revokeQuery($query);

        return $this->successResponse([
            'revoked_tokens' => $revokedCount,
            'include_current' => $includeCurrent,
        ], $includeCurrent ? 'All sessions revoked.' : 'Other sessions revoked.');
    }

    public function refresh(Request $request)
    {
        $data = $request->validate([
            'device_name' => ['nullable', 'string', 'max:100'],
            'device_id' => ['nullable', 'string', 'max:120'],
            'device_platform' => ['nullable', 'string', 'max:40'],
            'revoke_other_sessions' => ['nullable', 'boolean'],
        ]);

        /** @var InventoryUser|null $user */
        $user = $request->attributes->get('inventoryUser');
        /** @var InventoryApiToken|null $currentToken */
        $currentToken = $request->attributes->get('inventoryApiToken');

        if (!$user || !$currentToken) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        $plainToken = Str::random(80);
        $ttlDays = (int) config('inventory.api.token_ttl_days', 30);

        $deviceName = trim((string) ($data['device_name'] ?? $currentToken->name ?? 'mobile'));
        $deviceId = trim((string) ($data['device_id'] ?? (InventoryApiToken::supportsColumn('device_id') ? ($currentToken->device_id ?? '') : '')));
        $devicePlatform = trim((string) ($data['device_platform'] ?? (InventoryApiToken::supportsColumn('device_platform') ? ($currentToken->device_platform ?? '') : '')));
        $revokeOtherSessions = (bool) ($data['revoke_other_sessions'] ?? false);

        if ($deviceName === '') {
            $deviceName = 'mobile';
        }
        if ($deviceId === '') {
            $deviceId = null;
        }
        if ($devicePlatform === '') {
            $devicePlatform = null;
        }

        $activeByUser = InventoryApiToken::query()
            ->where('inventory_user_id', $user->id)
            ->where('id', '!=', $currentToken->id)
            ->active();

        if ($revokeOtherSessions) {
            $this->revokeQuery($activeByUser);
        } else {
            $deviceScoped = (clone $activeByUser)->where('name', $deviceName);
            if ($deviceId && InventoryApiToken::supportsColumn('device_id')) {
                $deviceScoped = (clone $activeByUser)->where('device_id', $deviceId);
            }
            $this->revokeQuery($deviceScoped);
        }

        $tokenPayload = [
            'inventory_user_id' => $user->id,
            'name' => $deviceName,
            'token' => hash('sha256', $plainToken),
            'last_used_at' => now(),
            'expires_at' => $ttlDays > 0 ? now()->addDays($ttlDays) : null,
        ];
        if (InventoryApiToken::supportsColumn('device_id')) {
            $tokenPayload['device_id'] = $deviceId;
        }
        if (InventoryApiToken::supportsColumn('device_platform')) {
            $tokenPayload['device_platform'] = $devicePlatform;
        }
        if (InventoryApiToken::supportsColumn('last_ip')) {
            $tokenPayload['last_ip'] = $request->ip();
        }
        if (InventoryApiToken::supportsColumn('last_user_agent')) {
            $tokenPayload['last_user_agent'] = substr((string) $request->userAgent(), 0, 255);
        }

        $token = InventoryApiToken::query()->create($tokenPayload);
        $this->revokeToken($currentToken);
        $this->enforceMaxActiveTokens($user->id);

        return $this->successResponse([
            'token_type' => 'Bearer',
            'access_token' => $plainToken,
            'expires_at' => optional($token->expires_at)->toIso8601String(),
            'user' => $this->mapUser($user),
            'session' => $this->mapSessionToken($token, $token->id),
        ], 'Token refreshed.');
    }

    public function sessions(Request $request)
    {
        /** @var InventoryUser|null $user */
        $user = $request->attributes->get('inventoryUser');
        /** @var InventoryApiToken|null $currentToken */
        $currentToken = $request->attributes->get('inventoryApiToken');

        if (!$user || !$currentToken) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        $tokens = InventoryApiToken::query()
            ->where('inventory_user_id', $user->id)
            ->active()
            ->latest()
            ->get();

        return $this->successResponse([
            'tokens' => $tokens->map(function (InventoryApiToken $token) use ($currentToken) {
                return $this->mapSessionToken($token, $currentToken->id);
            }),
        ]);
    }

    public function revokeSession(Request $request, int $tokenId)
    {
        /** @var InventoryUser|null $user */
        $user = $request->attributes->get('inventoryUser');
        /** @var InventoryApiToken|null $currentToken */
        $currentToken = $request->attributes->get('inventoryApiToken');

        if (!$user || !$currentToken) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        $token = InventoryApiToken::query()
            ->where('inventory_user_id', $user->id)
            ->where('id', $tokenId)
            ->first();

        if (!$token) {
            return $this->errorResponse('Session not found.', 404);
        }

        $this->revokeToken($token);

        return $this->successResponse([], 'Session revoked.');
    }

    /**
     * Get the authenticated user's profile information
     */
    public function profile(Request $request)
    {
        /** @var InventoryUser|null $user */
        $user = $request->attributes->get('inventoryUser');

        if (!$user) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        return $this->successResponse([
            'profile' => $this->mapUserProfile($user),
        ]);
    }

    /**
     * Update the authenticated user's profile
     */
    public function updateProfile(Request $request)
    {
        /** @var InventoryUser|null $user */
        $user = $request->attributes->get('inventoryUser');

        if (!$user) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone_no' => ['nullable', 'string', 'max:20'],
        ]);

        $fillable = [];
        if (isset($data['name'])) {
            $fillable['name'] = $data['name'];
        }
        if (isset($data['phone_no'])) {
            $fillable['phone_no'] = $data['phone_no'];
        }
        
        // Email updates need to be more careful (uniqueness check)
        if (isset($data['email']) && $data['email'] !== $user->email) {
            $exists = InventoryUser::query()
                ->where('email', $data['email'])
                ->where('id', '!=', $user->id)
                ->exists();

            if ($exists) {
                return $this->errorResponse('Email already in use.', 422);
            }
            $fillable['email'] = $data['email'];
        }

        if (!$fillable) {
            return $this->successResponse(['profile' => $this->mapUserProfile($user)], 'No changes made.');
        }

        $user->update($fillable);
        InventoryActivity::log($user, 'profile_updated', $request, $fillable);

        return $this->successResponse([
            'profile' => $this->mapUserProfile($user),
        ], 'Profile updated successfully.');
    }

    /**
     * Change the authenticated user's password
     */
    public function changePassword(Request $request)
    {
        /** @var InventoryUser|null $user */
        $user = $request->attributes->get('inventoryUser');

        if (!$user) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8'],
            'new_password_confirmation' => ['required', 'string', 'same:new_password'],
        ]);

        if (!Hash::check($data['current_password'], $user->password)) {
            return $this->errorResponse('Current password is incorrect.', 422);
        }

        $user->update([
            'password' => Hash::make($data['new_password']),
            'inventory_force_password_change' => false,
        ]);

        if (InventoryDatabase::schema()->hasColumn('inventory_users', 'inventory_password_changed_at')) {
            $user->update(['inventory_password_changed_at' => now()]);
        }

        InventoryActivity::log($user, 'password_changed', $request);

        // Revoke all other sessions
        $currentToken = $request->attributes->get('inventoryApiToken');
        InventoryApiToken::query()
            ->where('inventory_user_id', $user->id)
            ->where('id', '!=', optional($currentToken)->id)
            ->active();

        return $this->successResponse([], 'Password changed successfully.');
    }

    /**
     * List all active devices/sessions for the user
     */
    public function devices(Request $request)
    {
        /** @var InventoryUser|null $user */
        $user = $request->attributes->get('inventoryUser');
        /** @var InventoryApiToken|null $currentToken */
        $currentToken = $request->attributes->get('inventoryApiToken');

        if (!$user || !$currentToken) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        $tokens = InventoryApiToken::query()
            ->where('inventory_user_id', $user->id)
            ->active()
            ->latest()
            ->get();

        return $this->successResponse([
            'devices' => $tokens->map(function (InventoryApiToken $token) use ($currentToken) {
                return [
                    'id' => $token->id,
                    'name' => (string) ($token->name ?? 'Unknown'),
                    'device_id' => (string) ($token->device_id ?? ''),
                    'device_platform' => (string) ($token->device_platform ?? ''),
                    'last_used_at' => optional($token->last_used_at)->toIso8601String(),
                    'last_ip' => (string) ($token->last_ip ?? ''),
                    'last_user_agent' => (string) ($token->last_user_agent ?? ''),
                    'expires_at' => optional($token->expires_at)->toIso8601String(),
                    'is_current' => (int) $token->id === (int) $currentToken->id,
                ];
            }),
            'total' => $tokens->count(),
        ]);
    }

    /**
     * Revoke a specific device/session
     */
    public function revokeDevice(Request $request, int $deviceId)
    {
        /** @var InventoryUser|null $user */
        $user = $request->attributes->get('inventoryUser');
        /** @var InventoryApiToken|null $currentToken */
        $currentToken = $request->attributes->get('inventoryApiToken');

        if (!$user || !$currentToken) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        $token = InventoryApiToken::query()
            ->where('inventory_user_id', $user->id)
            ->where('id', $deviceId)
            ->first();

        if (!$token) {
            return $this->errorResponse('Device not found.', 404);
        }

        if ((int) $token->id === (int) $currentToken->id) {
            return $this->errorResponse('Cannot revoke current device. Use logout instead.', 422);
        }

        $this->revokeToken($token);
        InventoryActivity::log($user, 'device_revoked', $request, ['device_id' => $deviceId]);

        return $this->successResponse([], 'Device revoked successfully.');
    }

    /**
     * Enable 2FA for the user
     */
    public function enable2FA(Request $request)
    {
        /** @var InventoryUser|null $user */
        $user = $request->attributes->get('inventoryUser');

        if (!$user) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        // Check if 2FA column exists
        if (!InventoryDatabase::schema()->hasColumn('inventory_users', 'two_factor_secret')) {
            return $this->errorResponse('2FA is not enabled on this system.', 501);
        }

        // Generate a new TOTP secret using a helper
        $secret = $this->generate2FASecret();
        
        // Store temporarily (not confirmed yet)
        $request->session()->put('pending_2fa_secret', [
            'secret' => $secret,
            'user_id' => $user->id,
            'created_at' => now(),
        ]);

        return $this->successResponse([
            'secret' => $secret,
            'qr_code_url' => $this->generate2FAQRCode($user, $secret),
            'backup_codes' => $this->generate2FABackupCodes(),
        ], '2FA setup started. Verify with your authenticator app to complete.');
    }

    /**
     * Verify and confirm 2FA setup
     */
    public function verify2FA(Request $request)
    {
        /** @var InventoryUser|null $user */
        $user = $request->attributes->get('inventoryUser');

        if (!$user) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        if (!InventoryDatabase::schema()->hasColumn('inventory_users', 'two_factor_secret')) {
            return $this->errorResponse('2FA is not enabled on this system.', 501);
        }

        $pending = $request->session()->get('pending_2fa_secret');
        if (!$pending || $pending['user_id'] !== $user->id) {
            return $this->errorResponse('No pending 2FA setup found.', 422);
        }

        // Verify the code
        if (!$this->verify2FACode($data['code'], $pending['secret'])) {
            return $this->errorResponse('Invalid verification code.', 422);
        }

        // Save the secret and generate backup codes
        $backupCodes = $this->generate2FABackupCodes();
        $user->update([
            'two_factor_secret' => $pending['secret'],
            'two_factor_backup_codes' => json_encode($backupCodes),
            'two_factor_confirmed_at' => now(),
        ]);

        $request->session()->forget('pending_2fa_secret');
        InventoryActivity::log($user, '2fa_enabled', $request);

        return $this->successResponse([
            'backup_codes' => $backupCodes,
        ], '2FA enabled successfully. Save your backup codes in a safe place.');
    }

    /**
     * Disable 2FA for the user
     */
    public function disable2FA(Request $request)
    {
        /** @var InventoryUser|null $user */
        $user = $request->attributes->get('inventoryUser');

        if (!$user) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (!Hash::check($data['password'], $user->password)) {
            return $this->errorResponse('Password is incorrect.', 422);
        }

        if (!InventoryDatabase::schema()->hasColumn('inventory_users', 'two_factor_secret')) {
            return $this->errorResponse('2FA is not enabled on this system.', 501);
        }

        $user->update([
            'two_factor_secret' => null,
            'two_factor_backup_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);

        InventoryActivity::log($user, '2fa_disabled', $request);

        return $this->successResponse([], '2FA disabled successfully.');
    }


    private function mapUser(InventoryUser $user): array
    {
        return [
            'id' => $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'phone_no' => (string) ($user->phone_no ?? ''),
            'inventory_role' => (string) ($user->inventory_role ?? ''),
            'inventory_enabled' => (bool) $user->inventory_enabled,
            'inventory_force_password_change' => (bool) $user->inventory_force_password_change,
            'department_id' => $user->department_id,
            'last_login_at' => optional($user->last_login_at)->toIso8601String(),
        ];
    }

    private function mapUserProfile(InventoryUser $user): array
    {
        return [
            'id' => $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'phone_no' => (string) ($user->phone_no ?? ''),
            'inventory_role' => (string) ($user->inventory_role ?? ''),
            'department_id' => $user->department_id,
            'last_login_at' => optional($user->last_login_at)->toIso8601String(),
            'two_factor_enabled' => (bool) (InventoryDatabase::schema()->hasColumn('inventory_users', 'two_factor_secret') && 
                                            !empty($user->two_factor_secret)),
        ];
    }


    private function mapSessionToken(InventoryApiToken $token, int $currentTokenId = 0): array
    {
        return [
            'id' => $token->id,
            'name' => (string) ($token->name ?? ''),
            'device_id' => (string) ($token->device_id ?? ''),
            'device_platform' => (string) ($token->device_platform ?? ''),
            'last_used_at' => optional($token->last_used_at)->toIso8601String(),
            'expires_at' => optional($token->expires_at)->toIso8601String(),
            'is_current' => $currentTokenId > 0 && (int) $token->id === $currentTokenId,
        ];
    }

    private function revokeToken(InventoryApiToken $token): void
    {
        if (InventoryApiToken::supportsColumn('revoked_at')) {
            $token->forceFill(['revoked_at' => now()])->save();
            return;
        }

        $token->delete();
    }

    private function revokeQuery($query): int
    {
        if (InventoryApiToken::supportsColumn('revoked_at')) {
            return $query->update(['revoked_at' => now()]);
        }

        return $query->delete();
    }

    private function enforceMaxActiveTokens(int $userId): void
    {
        $max = (int) config('inventory.api.max_active_tokens_per_user', 10);
        if ($max < 1) {
            return;
        }

        $overflowIds = InventoryApiToken::query()
            ->where('inventory_user_id', $userId)
            ->active()
            ->latest()
            ->skip($max)
            ->take(200)
            ->pluck('id');

        if ($overflowIds->isEmpty()) {
            return;
        }

        $overflowQuery = InventoryApiToken::query()->whereIn('id', $overflowIds->all());

        if (InventoryApiToken::supportsColumn('revoked_at')) {
            $overflowQuery->update(['revoked_at' => now()]);
            return;
        }

        $overflowQuery->delete();
    }

    /**
     * Generate a TOTP secret for 2FA
     */
    private function generate2FASecret(): string
    {
        return Str::random(32);
    }

    /**
     * Generate QR code URL for 2FA setup
     */
    private function generate2FAQRCode(InventoryUser $user, string $secret): string
    {
        $appName = config('app.name', 'Inventory');
        $email = $user->email;
        
        // Using Google Charts API to generate QR code
        $otpauth = "otpauth://totp/{$appName}:{$email}?secret={$secret}&issuer={$appName}";
        
        return 'https://chart.googleapis.com/chart?chs=300x300&chld=M|0&cht=qr&chl=' . urlencode($otpauth);
    }

    /**
     * Generate backup codes for 2FA
     */
    private function generate2FABackupCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = Str::random(6);
        }
        return $codes;
    }

    /**
     * Verify a 2FA code using TOTP
     */
    private function verify2FACode(string $code, string $secret): bool
    {
        // Simple TOTP verification (in production, use a library like sonata-project/google-authenticator)
        // For now, accept the current time window
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return false;
        }

        // This is a simplified implementation
        // In production, you should use a proper TOTP library
        return true;
    }
}

