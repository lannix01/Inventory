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
        
        Schema::connection($connection)->create('inventory_task_items', function (Blueprint $table) {
            $table->id();

            // Relationships
            $table->foreignId('task_id')->constrained('inventory_tasks')->onDelete('cascade');
            $table->foreignId('item_id')->constrained('inventory_items')->onDelete('cascade');

            // Item details (snapshot at task creation time)
            $table->string('item_name');
            $table->string('item_sku');
            $table->integer('required_quantity');
            $table->integer('assigned_quantity')->default(0);
            $table->integer('used_quantity')->default(0);
            $table->integer('returned_quantity')->default(0);

            // Status tracking
            $table->enum('status', ['pending', 'assigned', 'in_use', 'returned', 'completed'])->default('pending');
            $table->text('notes')->nullable();

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->index(['task_id', 'status']);
            $table->index(['item_id', 'status']);
            $table->unique(['task_id', 'item_id']); // One item per task
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = InventoryDatabase::connectionName();
        Schema::connection($connection)->dropIfExists('inventory_task_items');
    }
};
