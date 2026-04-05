<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateProxyIngestToken
{
    /**
     * Доступ к API прокси ограничивается сетью (VPN / внутренняя сеть), отдельный секрет не используется.
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
