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
        
        Schema::connection($connection)->create('mobile_device_queues', function (Blueprint $table) {
            $table->id();

            // Device identification
            $table->string('device_id')->index();
            $table->foreignId('user_id')->constrained('inventory_users')->onDelete('cascade');

            // Sync operation
            $table->enum('operation_type', [
                'task_start', 'task_pause', 'task_resume', 'task_complete',
                'evidence_upload', 'location_update', 'item_scan',
                'task_create', 'task_update', 'sync_request'
            ]);
            $table->enum('sync_status', ['pending', 'processing', 'completed', 'failed'])->default('pending');

            // Operation data
            $table->json('operation_data');
            $table->text('error_message')->nullable();

            // Sync metadata
            $table->timestamp('queued_at');
            $table->timestamp('processed_at')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();

            // Location context
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->index(['device_id', 'sync_status']);
            $table->index(['user_id', 'queued_at']);
            $table->index(['operation_type', 'sync_status']);
            $table->index('next_retry_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = InventoryDatabase::connectionName();
        Schema::connection($connection)->dropIfExists('mobile_device_queues');
    }
};
