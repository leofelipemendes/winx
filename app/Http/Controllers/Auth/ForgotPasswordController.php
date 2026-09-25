<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends Controller
{
    public function __invoke(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->validated(), function (User $user, string $token): void {
            $user->notify(new ResetPasswordNotification($token));
        });

        return response()->json(['message' => 'Se o email estiver cadastrado, você receberá as instruções de recuperação.']);
    }
}
