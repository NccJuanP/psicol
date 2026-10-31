<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->first();

        // Same answer for unknown email, wrong password and inactive user: never reveal which accounts exist.
        if (! $user?->is_active || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $expiresAt = now()->addMinutes((int) config('sanctum.expiration'));
        $token = $user->createToken('spa', ['*'], $expiresAt);

        return response()->json(['data' => [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601ZuluString(),
            'user' => new UserResource($user),
        ]]);
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
