<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_token_can_access_products_and_is_revoked_on_logout(): void
    {
        $user = User::factory()->create(['password' => 'test-secret']);
        $otherToken = $user->createToken('other-device')->plainTextToken;
        $response = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'test-secret'])
            ->assertOk()->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.token_type', 'Bearer')->assertJsonMissingPath('data.user.password');
        $token = $response->json('data.token');
        $this->assertNotNull(PersonalAccessToken::findToken($token));
        $this->assertNotSame($token, PersonalAccessToken::findToken($token)->token);

        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/products')->assertOk();
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/user')->assertOk()->assertJsonPath('data.id', $user->id);
        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/logout')->assertNoContent();
        $this->assertNull(PersonalAccessToken::findToken($token));
        $this->assertNotNull(PersonalAccessToken::findToken($otherToken));
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/products')->assertUnauthorized();
    }

    #[DataProvider('protectedRoutes')]
    public function test_anonymous_users_cannot_access_protected_routes(string $method, string $path): void
    {
        $this->json($method, $path)->assertUnauthorized();
    }

    public static function protectedRoutes(): array
    {
        return [
            ['GET', '/api/products'], ['POST', '/api/products'],
            ['GET', '/api/products/1'], ['PUT', '/api/products/1'],
            ['PATCH', '/api/products/1'], ['DELETE', '/api/products/1'],
            ['GET', '/api/user'], ['POST', '/api/logout'],
        ];
    }

    public function test_wrong_password_and_unknown_email_return_the_same_error(): void
    {
        $user = User::factory()->create();
        $wrong = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertUnauthorized()->json();
        $unknown = $this->postJson('/api/login', ['email' => 'unknown@example.com', 'password' => 'wrong'])
            ->assertUnauthorized()->json();
        $this->assertSame($wrong, $unknown);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_validates_required_credentials(): void
    {
        $this->postJson('/api/login')->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/login', ['email' => 'unknown@example.com', 'password' => 'wrong'])->assertUnauthorized();
        }

        $this->postJson('/api/login', ['email' => 'unknown@example.com', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_invalid_and_expired_tokens_are_rejected(): void
    {
        $this->withToken('invalid')->getJson('/api/products')->assertUnauthorized();
        $user = User::factory()->create();
        $expired = $user->createToken('expired', ['*'], now()->subMinute())->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($expired)->getJson('/api/products')->assertUnauthorized();
    }
}
