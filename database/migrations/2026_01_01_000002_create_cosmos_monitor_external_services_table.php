<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Created to let operators register low-volume external dependency checks while probe history stays in Redis.
 */
class CreateCosmosMonitorExternalServicesTable extends Migration
{
    /**
     * Created to persist user-managed external service definitions and their latest check snapshot.
     */
    public function up(): void
    {
        Schema::create('cosmos_monitor_external_services', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('url');
            $table->boolean('enabled')->default(true)->index();
            $table->string('latest_status')->nullable()->index();
            $table->unsignedSmallInteger('latest_http_status')->nullable();
            $table->unsignedInteger('latest_latency_ms')->nullable();
            $table->timestamp('latest_checked_at')->nullable();
            $table->text('latest_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Created so package rollback removes only service definitions and leaves Redis telemetry untouched.
     */
    public function down(): void
    {
        Schema::dropIfExists('cosmos_monitor_external_services');
    }
}
