<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateProxyIngestToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('services.pocket_options_proxy.ingest_token');
        if (empty($token)) {
            return $next($request);
        }
        if ($request->header('X-Proxy-Token') !== $token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        return $next($request);
    }
}
