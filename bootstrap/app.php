<?php

use App\Http\Middleware\EndTenancy;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Stancl\Tenancy\Exceptions\NotASubdomainException;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(EndTenancy::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([
            NotASubdomainException::class,
            TenantCouldNotBeIdentifiedOnDomainException::class,
        ]);

        $exceptions->render(function (NotASubdomainException|TenantCouldNotBeIdentifiedOnDomainException $exception, Request $request): Response {
            $message = 'Negocio no encontrado';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], Response::HTTP_NOT_FOUND);
            }

            return response()->view('errors.negocio-no-encontrado', ['message' => $message], Response::HTTP_NOT_FOUND);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
