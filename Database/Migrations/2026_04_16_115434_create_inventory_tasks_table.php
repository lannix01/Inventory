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
        
        Schema::connection($connection)->create('inventory_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();

            // Status and priority
            $table->enum('status', ['pending', 'in_progress', 'paused', 'completed', 'cancelled'])->default('pending');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');

            // Assignment
            $table->foreignId('assigned_to')->constrained('inventory_users')->onDelete('cascade');
            $table->foreignId('created_by')->constrained('inventory_users')->onDelete('cascade');

            // Scheduling
            $table->timestamp('scheduled_start')->nullable();
            $table->timestamp('scheduled_end')->nullable();
            $table->timestamp('actual_start')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Location and context
            $table->string('location')->nullable();
            $table->string('task_type')->default('general'); // installation, maintenance, inspection, etc.
            $table->json('metadata')->nullable(); // Additional task-specific data

            // Recurring tasks
            $table->boolean('is_recurring')->default(false);
            $table->string('recurrence_pattern')->nullable(); // daily, weekly, monthly
            $table->integer('recurrence_interval')->nullable(); // every X days/weeks/months
            $table->timestamp('recurrence_end_date')->nullable();

            // Parent task for subtasks
            $table->foreignId('parent_task_id')->nullable()->constrained('inventory_tasks')->onDelete('cascade');

            // Progress tracking
            $table->integer('progress_percentage')->default(0);
            $table->text('completion_notes')->nullable();

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->index(['assigned_to', 'status']);
            $table->index(['status', 'priority']);
            $table->index(['scheduled_start', 'scheduled_end']);
            $table->index('task_type');
            $table->index('is_recurring');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = InventoryDatabase::connectionName();
        Schema::connection($connection)->dropIfExists('inventory_tasks');
    }
};
