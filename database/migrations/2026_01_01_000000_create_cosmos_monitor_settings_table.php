<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Created to give the production monitor a durable settings source while keeping high-volume telemetry in Redis.
 */
class CreateCosmosMonitorSettingsTable extends Migration
{
    /**
     * Created to publish the notification, retention, and threshold settings table into the host application database.
     */
    public function up(): void
    {
        /**
         * Created to define the package settings schema inside Laravel's schema builder callback.
         */
        Schema::create('cosmos_monitor_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->string('type')->default('json');
            $table->text('description')->nullable();
            $table->string('updated_by')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Created so package rollback removes only the durable monitor settings table and leaves Redis telemetry untouched.
     */
    public function down(): void
    {
        Schema::dropIfExists('cosmos_monitor_settings');
    }
}
