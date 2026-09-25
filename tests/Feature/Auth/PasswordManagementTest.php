<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_hashes_password_and_allows_login(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Test User', 'email' => 'new@example.com',
            'password' => 'Secret1234', 'password_confirmation' => 'Secret1234',
        ])->assertCreated()->assertJsonPath('data.email', 'new@example.com')->assertJsonMissingPath('data.password');
        $this->assertTrue(Hash::check('Secret1234', User::firstOrFail()->password));
        Auth::forgetGuards();
        $this->postJson('/api/login', ['email' => 'new@example.com', 'password' => 'Secret1234'])->assertOk();
    }

    public function test_registration_rejects_duplicate_email_and_invalid_password(): void
    {
        $user = User::factory()->create();
        $this->postJson('/api/register', [
            'name' => 'Test', 'email' => $user->email, 'password' => 'short', 'password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_forgot_password_does_not_reveal_whether_email_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $existing = $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk()->json();
        $unknown = $this->postJson('/api/forgot-password', ['email' => 'unknown@example.com'])->assertOk()->json();
        $this->assertSame($existing, $unknown);
        Notification::assertSentTo($user, ResetPasswordNotification::class);
        Notification::assertCount(1);
        $this->assertArrayNotHasKey('token', $existing);
    }

    public function test_password_reset_consumes_token_and_revokes_access_tokens(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => 'OldPassword123']);
        $accessToken = $user->createToken('device')->plainTextToken;
        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();
        $notification = Notification::sent($user, ResetPasswordNotification::class)->first();
        $this->assertNotNull($notification);
        $this->assertTrue(Password::tokenExists($user, $notification->token));
        $payload = [
            'email' => $user->email, 'token' => $notification->token,
            'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123',
        ];
        $this->postJson('/api/reset-password', $payload)->assertOk();
        $this->assertTrue(Hash::check('NewPassword123', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson('/api/reset-password', $payload)->assertUnprocessable();
        Auth::forgetGuards();
        $this->withToken($accessToken)->getJson('/api/products')->assertUnauthorized();
        Auth::forgetGuards();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'NewPassword123'])->assertOk();
    }

    public function test_invalid_expired_or_mismatched_tokens_cannot_change_password(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword123']);
        $payload = ['email' => $user->email, 'token' => 'invalid', 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'];
        $this->postJson('/api/reset-password', $payload)->assertUnprocessable();
        $token = Password::createToken($user);
        $other = User::factory()->create();
        $this->postJson('/api/reset-password', array_replace($payload, ['email' => $other->email, 'token' => $token]))->assertUnprocessable();
        $this->travel(61)->minutes();
        $this->postJson('/api/reset-password', array_replace($payload, ['token' => $token]))->assertUnprocessable();
        $this->assertTrue(Hash::check('OldPassword123', $user->fresh()->password));
    }

    public function test_reset_requires_password_confirmation(): void
    {
        $this->postJson('/api/reset-password', ['email' => 'test@example.com', 'token' => 'token', 'password' => 'Secret1234'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    #[DataProvider('guestEndpoints')]
    public function test_authenticated_users_are_rejected_by_guest_middleware(string $path): void
    {
        $token = User::factory()->create()->createToken('device')->plainTextToken;
        $this->withToken($token)->postJson($path)->assertStatus(409);
    }

    public static function guestEndpoints(): array
    {
        return [['/api/login'], ['/api/register'], ['/api/forgot-password'], ['/api/reset-password']];
    }

    public function test_notification_can_render_without_a_frontend_reset_route(): void
    {
        $user = User::factory()->make();
        $mail = (new ResetPasswordNotification('sample-token'))->toMail($user);
        $this->assertStringContainsString('sample-token', (string) $mail->render());
    }
}
