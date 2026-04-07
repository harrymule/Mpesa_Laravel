<?php

namespace Harri\LaravelMpesa\Tests\Unit;

use Harri\LaravelMpesa\Events\CallbackForwardingFailed;
use Harri\LaravelMpesa\Jobs\ForwardMpesaCallbackJob;
use Harri\LaravelMpesa\Tests\TestCase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

class ForwardMpesaCallbackJobTest extends TestCase
{
    public function test_it_dispatches_a_failed_forwarding_event_for_unsuccessful_responses(): void
    {
        Event::fake([CallbackForwardingFailed::class]);

        Http::fake([
            'https://client-app.test/callback' => Http::response(['message' => 'fail'], 500),
        ]);

        $job = new ForwardMpesaCallbackJob('https://client-app.test/callback', ['ok' => false], null, 123);

        try {
            $job->handle();
            $this->fail('The forwarding job should throw for a failed callback response.');
        } catch (RequestException) {
            $this->assertTrue(true);
        }

        Event::assertDispatched(CallbackForwardingFailed::class, function (CallbackForwardingFailed $event) {
            return $event->callbackUrl === 'https://client-app.test/callback'
                && $event->transactionId === 123
                && $event->message !== null;
        });
    }
}

