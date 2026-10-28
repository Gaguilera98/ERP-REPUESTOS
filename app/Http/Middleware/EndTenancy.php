<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EndTenancy
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } finally {
            $this->endTenancy();
        }
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->endTenancy();
    }

    private function endTenancy(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}
