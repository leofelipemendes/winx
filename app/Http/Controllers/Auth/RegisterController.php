<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\Registered;

class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request): UserResource
    {
        $user = User::create($request->safe()->only(['name', 'email', 'password']));
        event(new Registered($user));

        return new UserResource($user);
    }
}
