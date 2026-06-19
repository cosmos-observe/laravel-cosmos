<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Created to persist low-volume exception workflow state while exception telemetry itself stays in Redis.
 */
class CreateCosmosMonitorExceptionStatesTable extends Migration
{
    /**
     * Created to store resolve, snooze, and active overlays by exception hash for dashboard workflows.
     */
    public function up(): void
    {
        /**
         * Created to define the exception-state schema inside Laravel's schema builder callback.
         */
        Schema::create('cosmos_monitor_exception_states', function (Blueprint $table): void {
            $table->id();
            $table->string('hash')->unique();
            $table->string('status')->default('active');
            $table->timestamp('snoozed_until')->nullable();
            $table->text('note')->nullable();
            $table->string('updated_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Created so package rollback removes only durable exception workflow state and leaves Redis telemetry untouched.
     */
    public function down(): void
    {
        Schema::dropIfExists('cosmos_monitor_exception_states');
    }
}
