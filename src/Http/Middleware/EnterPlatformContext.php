<?php

namespace Aesis\Tenancy\Http\Middleware;

use Aesis\Tenancy\Contracts\CurrentTenant;
use Closure;
use Illuminate\Http\Request;

final class EnterPlatformContext
{
    public function handle(Request $request, Closure $next): mixed
    {
        $context = app(CurrentTenant::class);
        $context->enterPlatform();

        try {
            return $next($request);
        } finally {
            $context->clear();
        }
    }
}
