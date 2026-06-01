<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Modules\Inventory\Support\InventoryDatabase;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection(InventoryDatabase::connectionName())->table('inventory_users', function (Blueprint $table) {
            // Add 2FA columns if they don't exist
            if (!Schema::connection(InventoryDatabase::connectionName())->hasColumn('inventory_users', 'two_factor_secret')) {
                $table->string('two_factor_secret')->nullable()->after('inventory_enabled');
            }

            if (!Schema::connection(InventoryDatabase::connectionName())->hasColumn('inventory_users', 'two_factor_backup_codes')) {
                $table->json('two_factor_backup_codes')->nullable()->after('two_factor_secret');
            }

            if (!Schema::connection(InventoryDatabase::connectionName())->hasColumn('inventory_users', 'two_factor_confirmed_at')) {
                $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_backup_codes');
            }

            if (!Schema::connection(InventoryDatabase::connectionName())->hasColumn('inventory_users', 'inventory_password_changed_at')) {
                $table->timestamp('inventory_password_changed_at')->nullable()->after('inventory_force_password_change');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection(InventoryDatabase::connectionName())->table('inventory_users', function (Blueprint $table) {
            $table->dropColumnIfExists('two_factor_secret');
            $table->dropColumnIfExists('two_factor_backup_codes');
            $table->dropColumnIfExists('two_factor_confirmed_at');
            $table->dropColumnIfExists('inventory_password_changed_at');
        });
    }
};
