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
        
        Schema::connection($connection)->create('inventory_task_executions', function (Blueprint $table) {
            $table->id();

            // Relationships
            $table->foreignId('task_id')->constrained('inventory_tasks')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('inventory_users')->onDelete('cascade');

            // Execution details
            $table->enum('action', [
                'started', 'paused', 'resumed', 'completed', 'cancelled',
                'item_assigned', 'item_used', 'item_returned',
                'location_checkin', 'location_checkout',
                'evidence_uploaded', 'notes_added'
            ]);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable(); // Action-specific data

            // Location tracking (for mobile app)
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('location_accuracy')->nullable();

            // Timestamps
            $table->timestamp('executed_at');
            $table->timestamps();

            // Indexes
            $table->index(['task_id', 'executed_at']);
            $table->index(['user_id', 'executed_at']);
            $table->index('action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = InventoryDatabase::connectionName();
        Schema::connection($connection)->dropIfExists('inventory_task_executions');
    }
};
