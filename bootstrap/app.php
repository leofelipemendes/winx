<?php

use App\Http\Middleware\EnsureApiGuest;
use App\Models\Product;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['guest.api' => EnsureApiGuest::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            if ($exception instanceof HttpResponseException) {
                return null;
            }

            if ($exception instanceof ValidationException) {
                return response()->json([
                    'message' => 'Os dados informados são inválidos. Verifique os campos e tente novamente.',
                    'errors' => $exception->errors(),
                ], $exception->status);
            }

            $status = match (true) {
                $exception instanceof AuthenticationException => 401,
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                default => 500,
            };
            $previous = $exception->getPrevious();
            $message = match ($status) {
                400 => 'Não foi possível processar a solicitação. Verifique os dados enviados.',
                401 => 'É necessário se autenticar para acessar este recurso.',
                403 => 'Você não tem permissão para realizar esta ação.',
                404 => $previous instanceof ModelNotFoundException && $previous->getModel() === Product::class
                    ? 'Produto não encontrado.'
                    : 'Recurso não encontrado.',
                405 => 'Método não permitido para este recurso.',
                409 => 'Não foi possível concluir a ação devido a um conflito com os dados atuais.',
                410 => 'Este recurso não está mais disponível.',
                413 => 'O conteúdo enviado excede o tamanho permitido.',
                415 => 'O formato do conteúdo enviado não é suportado.',
                419 => 'Sua sessão expirou. Atualize a página e tente novamente.',
                422 => 'Os dados informados são inválidos.',
                429 => 'Muitas solicitações. Aguarde um momento e tente novamente.',
                503 => 'O serviço está temporariamente indisponível. Tente novamente mais tarde.',
                default => $status >= 500
                    ? 'Ocorreu um erro interno. Tente novamente mais tarde.'
                    : 'Não foi possível concluir a solicitação.',
            };

            return response()->json(['message' => $message], $status,
                $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [],
            );
        });
    })->create();
