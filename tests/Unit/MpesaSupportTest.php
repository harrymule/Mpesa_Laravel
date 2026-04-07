<?php

namespace Harri\LaravelMpesa\Tests\Unit;

use Harri\LaravelMpesa\Support\Mpesa;
use PHPUnit\Framework\TestCase;

class MpesaSupportTest extends TestCase
{
    public function test_it_formats_common_kenyan_phone_variants(): void
    {
        $this->assertSame('254712345678', Mpesa::formatPhone('0712345678'));
        $this->assertSame('254712345678', Mpesa::formatPhone('+254712345678'));
        $this->assertSame('254712345678', Mpesa::formatPhone('712345678'));
    }

    public function test_it_builds_stk_password_from_shortcode_passkey_and_timestamp(): void
    {
        $this->assertSame(
            base64_encode('174379passkey20260318123045'),
            Mpesa::stkPassword('174379', 'passkey', '20260318123045')
        );
    }
}

