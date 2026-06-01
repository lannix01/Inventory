<?php

namespace App\Modules\Inventory\Http\Controllers\Auth;

use App\Modules\Inventory\Support\InventoryAccess;
use App\Modules\Inventory\Support\InventoryActivity;
use App\Modules\Inventory\Support\InventoryDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class InventoryAuthController extends Controller
{
    public function showLogin()
    {
        return view('inventory::auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required','email'],
            'password' => ['required','string'],
        ]);

        $credentials = [
            'email' => $data['email'],
            'password' => $data['password'],
            'inventory_enabled' => 1,
        ];

        if (!Auth::guard('inventory')->attempt($credentials, true)) {
            return back()
                ->withErrors(['email' => 'Invalid credentials or no Inventory access.'])
                ->withInput();
        }

        $request->session()->regenerate();

        $user = Auth::guard('inventory')->user();
        if ($user) {
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

            InventoryActivity::log($user, 'login', $request);
        }

        // Force password change if flagged
        if ($user->inventory_force_password_change) {
            return redirect()->route('inventory.auth.password.change');
        }

        $landingRoute = InventoryAccess::landingRouteName($user);
        if ($landingRoute !== null) {
            return redirect()->route($landingRoute);
        }

        Auth::guard('inventory')->logout();

        return redirect()
            ->route('inventory.auth.login')
            ->withErrors(['email' => 'This account has no inventory access configured yet.']);
    }

    public function logout(Request $request)
    {
        $user = Auth::guard('inventory')->user();
        if ($user) {
            InventoryActivity::log($user, 'logout', $request);
        }

        Auth::guard('inventory')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('inventory.auth.login');
    }

    public function switchToTechnician(Request $request)
    {
        Auth::guard('inventory')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('inventory.auth.login')
            ->with('status', 'Sign in as a technician to view the technician workspace.');
    }
}
