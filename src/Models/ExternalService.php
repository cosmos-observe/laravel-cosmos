<?php

namespace Cosmos\LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Created to store external service definitions separately from high-volume health-check telemetry.
 */
class ExternalService extends Model
{
    protected $table = 'cosmos_monitor_external_services';

    protected $fillable = [
        'name',
        'url',
        'enabled',
        'latest_status',
        'latest_http_status',
        'latest_latency_ms',
        'latest_checked_at',
        'latest_error',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'latest_checked_at' => 'datetime',
        'latest_latency_ms' => 'integer',
        'latest_http_status' => 'integer',
    ];
}
