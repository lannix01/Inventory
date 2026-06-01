<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use App\Modules\Inventory\Http\Middleware\UseInventoryDatabase;

class InventoryRouteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // View namespace: view('inventory::items.index')
        $this->loadViewsFrom(base_path('app/Modules/Inventory/resources/views'), 'inventory');
    }

    public function boot(): void
    {
        // Load migrations from module folder
        $this->loadMigrationsFrom(base_path('app/Modules/Inventory/Database/Migrations'));

        // Routes
        Route::middleware(['web', UseInventoryDatabase::class])
            ->group(base_path('app/Modules/Inventory/routes/web.php'));
    }
}
