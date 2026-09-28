<?php

use App\Http\Middleware\LogSlowRequests;
use App\Http\Middleware\RequireSuperadmin;
use App\Http\Middleware\RequireWorkspaceAdmin;
use App\Http\Middleware\ResolveWorkspace;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // First, so its timing covers everything else (sessions, auth, the controller).
        $middleware->prepend(LogSlowRequests::class);
        $middleware->statefulApi();
        $middleware->api(append: [SetLocale::class]);

        $middleware->alias([
            'workspace' => ResolveWorkspace::class,
            'workspace.admin' => RequireWorkspaceAdmin::class,
            'superadmin' => RequireSuperadmin::class,
        ]);

        // Tenant scope must be active before route model binding resolves models.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveWorkspace::class);

        // Public widget endpoints are called from customer sites (session-less, token based).
        $middleware->validateCsrfTokens(except: ['api/widget/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
