<?php

namespace Harri\LaravelMpesa\Models;

use Illuminate\Database\Eloquent\Model;

class MpesaTransaction extends Model
{
    protected $table = 'mpesa_transactions';

    protected $guarded = [];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'callback_payload' => 'array',
        'meta' => 'array',
        'callback_success' => 'boolean',
    ];
}
