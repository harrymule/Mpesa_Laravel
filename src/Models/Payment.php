<?php

namespace Harri\LaravelMpesa\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $table = 'mpesa_payments';

    protected $guarded = [];

    public function stkPush()
    {
        return $this->belongsTo(StkPush::class, 'stk_push_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ])));
    }
}
