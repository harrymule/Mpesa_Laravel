<?php

namespace Harri\LaravelMpesa\Models;

use Illuminate\Database\Eloquent\Model;

class MpesaErrorCode extends Model
{
    protected $table = 'mpesa_error_codes';

    protected $guarded = [];

    protected $casts = [
        'is_known' => 'boolean',
        'sample_payload' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];
}
