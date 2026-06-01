<?php

namespace App\Modules\Inventory\Http\Middleware;

use App\Modules\Inventory\Support\InventoryDatabase;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UseInventoryDatabase
{
    public function handle(Request $request, Closure $next): Response
    {
        InventoryDatabase::activate();

        return $next($request);
    }
}
