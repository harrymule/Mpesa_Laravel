<?php

namespace Harri\LaravelMpesa\Tests\Support;

use Closure;
use Illuminate\Http\Request;

class DenyMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        return response()->json([
            'message' => 'Unauthorized',
        ], 401);
    }
}
