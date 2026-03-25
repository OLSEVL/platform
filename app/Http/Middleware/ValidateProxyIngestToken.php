<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateProxyIngestToken
{
    /**
     * Прокси и инжест на одной машине — проверка токена отключена.
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
