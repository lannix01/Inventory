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
        $connection = InventoryDatabase::connectionName();
        
        // Check if table already exists
        if (Schema::connection($connection)->hasTable('inventory_notifications')) {
            return;
        }

        Schema::connection($connection)->create('inventory_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_user_id')->constrained('inventory_users')->onDelete('cascade');
            $table->string('title');
            $table->text('message');
            $table->string('type'); // 'low_stock', 'deployment', 'assignment', 'movement', 'system'
            $table->string('source_type')->nullable(); // 'item', 'deployment', 'assignment', etc
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('metadata')->nullable(); // Store additional data
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index('title');
            $table->index('type');
            $table->index(['inventory_user_id', 'read_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection(InventoryDatabase::connectionName())->dropIfExists('inventory_notifications');
    }
};
