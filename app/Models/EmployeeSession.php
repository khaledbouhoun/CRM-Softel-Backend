<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeSession extends Model
{
    protected $fillable = [
        'employee_id',
        'username',
        'device_name',
        'ip_address',
        'last_request_path',
        'last_heartbeat_at',
        'custom_name',
        'role',
        'presence_status',
        'status_note',
    ];

    public const ROLES = ['sales', 'support', 'management', 'training'];

    public const PRESENCE = ['available', 'busy', 'away', 'break'];

    protected $casts = [
        'last_heartbeat_at' => 'datetime',
    ];
}
