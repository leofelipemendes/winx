<?php

namespace App\Http\Requests\Auth;

use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends ForgotPasswordRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'max:72', Password::min(8)->letters()->numbers()],
        ];
    }
}
