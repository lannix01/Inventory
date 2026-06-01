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
        
        Schema::connection($connection)->create('inventory_task_templates', function (Blueprint $table) {
            $table->id();

            // Template details
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('task_type')->default('general');
            $table->enum('default_priority', ['low', 'medium', 'high', 'urgent'])->default('medium');

            // Default settings
            $table->integer('estimated_duration_hours')->nullable();
            $table->text('default_instructions')->nullable();
            $table->json('default_metadata')->nullable();

            // Required items template
            $table->json('required_items')->nullable(); // Array of item IDs and quantities

            // Template management
            $table->boolean('is_active')->default(true);
            $table->integer('usage_count')->default(0);
            $table->foreignId('created_by')->constrained('inventory_users')->onDelete('cascade');

            // Categories and tags
            $table->string('category')->nullable();
            $table->json('tags')->nullable();

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->index(['task_type', 'is_active']);
            $table->index('category');
            $table->index('created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = InventoryDatabase::connectionName();
        Schema::connection($connection)->dropIfExists('inventory_task_templates');
    }
};
