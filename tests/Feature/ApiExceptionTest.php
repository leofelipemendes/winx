<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ApiExceptionTest extends TestCase
{
    #[TestWith([400, 'Não foi possível processar a solicitação. Verifique os dados enviados.'])]
    #[TestWith([403, 'Você não tem permissão para realizar esta ação.'])]
    #[TestWith([405, 'Método não permitido para este recurso.'])]
    #[TestWith([409, 'Não foi possível concluir a ação devido a um conflito com os dados atuais.'])]
    #[TestWith([410, 'Este recurso não está mais disponível.'])]
    #[TestWith([413, 'O conteúdo enviado excede o tamanho permitido.'])]
    #[TestWith([415, 'O formato do conteúdo enviado não é suportado.'])]
    #[TestWith([419, 'Sua sessão expirou. Atualize a página e tente novamente.'])]
    #[TestWith([422, 'Os dados informados são inválidos.'])]
    #[TestWith([429, 'Muitas solicitações. Aguarde um momento e tente novamente.'])]
    #[TestWith([503, 'O serviço está temporariamente indisponível. Tente novamente mais tarde.'])]
    #[TestWith([502, 'Ocorreu um erro interno. Tente novamente mais tarde.'])]
    #[TestWith([406, 'Não foi possível concluir a solicitação.'])]
    public function test_http_errors_return_friendly_messages_and_preserve_status_and_headers(int $status, string $message): void
    {
        config(['app.debug' => true]);
        Route::get('/api/test-error', function () use ($status): never {
            throw new HttpException($status, 'Internal sensitive details', null, ['Retry-After' => '60']);
        });

        $this->get('/api/test-error')->assertStatus($status)
            ->assertHeader('Retry-After', '60')
            ->assertExactJson(['message' => $message]);
    }

    public function test_unexpected_exception_returns_safe_500_even_with_debug_enabled(): void
    {
        config(['app.debug' => true]);
        Route::get('/api/test-error', function (): never {
            throw new RuntimeException('Database credentials and internal paths');
        });

        $this->getJson('/api/test-error')->assertInternalServerError()
            ->assertExactJson(['message' => 'Ocorreu um erro interno. Tente novamente mais tarde.']);
    }

    public function test_unauthenticated_request_returns_friendly_401(): void
    {
        $this->getJson('/api/user')->assertUnauthorized()
            ->assertExactJson(['message' => 'É necessário se autenticar para acessar este recurso.']);
    }

    public function test_validation_returns_friendly_422_and_preserves_field_errors(): void
    {
        Route::post('/api/test-validation', function (): never {
            throw ValidationException::withMessages(['email' => ['Informe um email válido.']]);
        });

        $this->postJson('/api/test-validation')->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Os dados informados são inválidos. Verifique os campos e tente novamente.',
                'errors' => ['email' => ['Informe um email válido.']],
            ]);
    }
}
