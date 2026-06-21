<?php

namespace Cosmos\LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Created to persist low-volume monitor settings in the host database while high-volume telemetry stays in ClickHouse.
 */
class MonitorSetting extends Model
{
    protected $table = 'cosmos_monitor_settings';

    protected $fillable = [
        'key',
        'value',
        'type',
        'description',
        'updated_by',
    ];

    protected $casts = [
        'value' => 'array',
    ];
}
