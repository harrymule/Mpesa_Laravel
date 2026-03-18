<?php

namespace Harri\LaravelMpesa\Models;

use Illuminate\Database\Eloquent\Model;

class StkPush extends Model
{
    protected $table = 'mpesa_stk_pushes';

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
    ];

    public static function findByTracking(string $trackingId): ?self
    {
        return static::query()->where('tracking_id', $trackingId)->first();
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
