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
        
        Schema::connection($connection)->create('inventory_task_evidence', function (Blueprint $table) {
            $table->id();

            // Relationships
            $table->foreignId('task_id')->constrained('inventory_tasks')->onDelete('cascade');
            $table->foreignId('uploaded_by')->constrained('inventory_users')->onDelete('cascade');

            // Evidence details
            $table->string('evidence_type'); // photo, document, signature, audio, video
            $table->string('file_name');
            $table->string('file_path');
            $table->string('mime_type');
            $table->integer('file_size')->nullable(); // in bytes

            // Metadata
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable(); // GPS coordinates, device info, etc.

            // Location tracking (for mobile uploads)
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->timestamp('captured_at')->nullable();

            // Approval status
            $table->boolean('is_approved')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('inventory_users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->index(['task_id', 'evidence_type']);
            $table->index(['uploaded_by', 'created_at']);
            $table->index('is_approved');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = InventoryDatabase::connectionName();
        Schema::connection($connection)->dropIfExists('inventory_task_evidence');
    }
};
