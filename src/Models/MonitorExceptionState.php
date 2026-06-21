<?php

namespace Cosmos\LaravelMonitor\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Created to persist operator-facing exception state without mutating exception telemetry.
 */
class MonitorExceptionState extends Model
{
    protected $table = 'cosmos_monitor_exception_states';

    protected $fillable = [
        'hash',
        'status',
        'snoozed_until',
        'note',
        'updated_by',
    ];

    protected $casts = [
        'snoozed_until' => 'datetime',
    ];
}
