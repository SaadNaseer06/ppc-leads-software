<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Services\AssignmentException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth:sanctum', 'active']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['active' => EnsureUserIsActive::class]);
        $middleware->web(append: [HandleInertiaRequests::class]);
        // The dashboard's own fetch() calls to /api/* authenticate with the session cookie (+ CSRF)
        $middleware->statefulApi();
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/dashboard');
        // Only proxies on loopback/private networks (load balancers) may set X-Forwarded-For;
        // anyone else could spoof their IP and dodge the intake rate limit
        $middleware->trustProxies(at: env('TRUSTED_PROXIES')
            ? explode(',', env('TRUSTED_PROXIES'))
            : ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API errors always carry a human-readable "error" message for the dashboard to show
        $json = fn (string $message, int $status, array $extra = []) => response()->json(['error' => $message, ...$extra], $status);

        $exceptions->render(fn (ValidationException $e, Request $r) => $r->is('api/*')
            ? $json($e->validator->errors()->first(), 422, ['errors' => $e->errors()]) : null);
        $exceptions->render(fn (AssignmentException $e, Request $r) => $json($e->getMessage(), 422));
        $exceptions->render(fn (AuthenticationException $e, Request $r) => $r->is('api/*')
            ? $json('Your session has expired. Please sign in again.', 401) : null);
        $exceptions->render(fn (AuthorizationException $e, Request $r) => $r->is('api/*')
            ? $json("You don't have permission to do that.", 403) : null);
        $exceptions->render(fn (ModelNotFoundException $e, Request $r) => $r->is('api/*')
            ? $json('Not found', 404) : null);
        // abort(403), abort(404), throttling...: keep the status, give it a readable message
        $exceptions->render(fn (HttpExceptionInterface $e, Request $r) => $r->is('api/*')
            ? $json($e->getMessage() ?: match ($e->getStatusCode()) {
                403 => "You don't have permission to do that.",
                404 => 'Not found',
                default => 'Request failed',
            }, $e->getStatusCode(), [])->withHeaders($e->getHeaders()) : null);

        // Dashboard screens: a page the role can't use goes back to the dashboard,
        // a missing or hidden record shows the "not found" page inside the app shell
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->is('up')) {
                return $response;
            }
            $status = $response->getStatusCode();
            if ($status === 403 && $request->user()) {
                return redirect('/dashboard');
            }
            if ($status === 404 && $request->user()) {
                return Inertia::render('NotFound')->toResponse($request)->setStatusCode(404);
            }

            return $response;
        });
    })->create();
