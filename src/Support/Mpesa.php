<?php

namespace Harri\LaravelMpesa\Support;

class Mpesa
{
    public static function formatPhone(string $phone): string
    {
        $phone = trim($phone);

        if (str_starts_with($phone, '+')) {
            $phone = ltrim($phone, '+');
        }

        $phone = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($phone, '0')) {
            return '254' . substr($phone, 1);
        }

        if (str_starts_with($phone, '7') && strlen($phone) === 9) {
            return '254' . $phone;
        }

        return $phone;
    }

    public static function timestamp(): string
    {
        return now()->format('YmdHis');
    }

    public static function stkPassword(string $shortCode, string $passkey, ?string $timestamp = null): string
    {
        $timestamp ??= static::timestamp();

        return base64_encode($shortCode . $passkey . $timestamp);
    }
}
