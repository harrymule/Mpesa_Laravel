<?php

namespace Harri\LaravelMpesa\Support;

use Illuminate\Support\Arr;

class MpesaLog
{
    public static function channel(?string $journey = null): string
    {
        $fallback = (string) config('mpesa.log_channel', 'stack');
        $channels = config('mpesa.log_channels', []);

        if (! is_array($channels)) {
            return $fallback;
        }

        $default = (string) Arr::get($channels, 'default', $fallback);
        $normalized = self::normalizeJourney($journey);

        if ($normalized !== 'default' && Arr::has($channels, $normalized)) {
            return (string) Arr::get($channels, $normalized);
        }

        return $default;
    }

    public static function journeyFromUri(string $uri): string
    {
        return match (true) {
            str_contains($uri, '/stkpushquery') => 'stk_query',
            str_contains($uri, '/stkpush') => 'stk',
            str_contains($uri, '/c2b/') => 'c2b',
            str_contains($uri, '/b2c/') => 'b2c',
            str_contains($uri, '/b2b/') => 'b2b',
            str_contains($uri, '/reversal/') => 'reversal',
            str_contains($uri, '/accountbalance/') => 'account_balance',
            str_contains($uri, '/transactionstatus/') => 'transaction_status',
            str_contains($uri, '/qrcode/') => 'qr',
            default => 'default',
        };
    }

    protected static function normalizeJourney(?string $journey): string
    {
        if ($journey === null || trim($journey) === '') {
            return 'default';
        }

        return $journey;
    }
}


