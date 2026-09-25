<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_validation_returns_messages_and_attributes_in_portuguese(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/products', ['price' => 'invalid', 'stock' => 1.5])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'O campo nome é obrigatório.')
            ->assertJsonPath('errors.price.0', 'O campo preço deve conter um valor numérico.')
            ->assertJsonPath('errors.stock.0', 'O campo estoque deve conter um número inteiro.');
    }

    public function test_login_validation_returns_messages_in_portuguese(): void
    {
        $this->postJson('/api/login', ['email' => 'invalid'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'O campo e-mail não contém um endereço de e-mail válido.')
            ->assertJsonPath('errors.password.0', 'O campo senha é obrigatório.');
    }

    public function test_registration_password_rules_return_messages_in_portuguese(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Maria',
            'email' => 'maria@example.com',
            'password' => 'abcdefgh',
            'password_confirmation' => 'abcdefgh',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.password.0', 'O campo senha deve conter pelo menos um número.');
    }

    public function test_pagination_labels_are_in_portuguese(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/products')->assertOk()
            ->assertJsonPath('meta.links.0.label', '« Anterior')
            ->assertJsonPath('meta.links.2.label', 'Próximo »');
    }
}
