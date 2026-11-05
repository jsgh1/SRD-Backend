<?php

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Srd\Correlation;
use Srd\DependencyFailure;
use Srd\InternalAuth;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trimStrings(except: ['content', 'payload.sdp']);
        if (env('APP_ENV') === 'production') {
            $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_PROTO);
        }
        $middleware->append(Correlation::class);
        $middleware->alias(['internal' => InternalAuth::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $r) => true);
        $exceptions->render(function (Throwable $e, Request $r) {
            $status = $e instanceof ValidationException ? 422 : ($e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500);
            if ($e instanceof UniqueConstraintViolationException) {
                $status = 409;
            }
            $messages = [401 => 'Debes iniciar sesión.', 403 => 'No tienes permiso para esta acción.', 404 => 'No se encontró el recurso.', 409 => 'El recurso cambió o ya existe. Recarga y vuelve a intentar.', 419 => 'La sesión del formulario venció. Recarga la página.', 422 => 'Revisa los datos del formulario.', 429 => 'Alcanzaste el límite. Espera antes de intentar.', 500 => 'No se pudo completar la operación.', 503 => 'Un servicio necesario no está disponible. Intenta más tarde.'];

            $messages[507] = 'La junta no tiene espacio disponible para este archivo.';
            $messages[413] = 'El archivo supera el tamaño permitido.';
            return response()->json(['error' => ['code' => 'HTTP_'.$status, 'message' => $messages[$status] ?? 'No se pudo completar la solicitud.', 'fields' => $e instanceof ValidationException ? $e->errors() : (object) []], 'correlation_id' => $r->attributes->get('correlation_id')], $status);
        });
        $exceptions->dontReport([DependencyFailure::class]);
    })->create();
