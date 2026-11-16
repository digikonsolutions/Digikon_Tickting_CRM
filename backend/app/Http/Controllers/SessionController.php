<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\UserAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class SessionController extends Controller
{
    public function login(Request $request, UserAccountService $accounts): JsonResponse
    {
        $accounts->normalizeEmail($request);

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:128'],
        ]);

        if (! Auth::guard('web')->attempt($credentials)) {
            return response()->json(['message' => 'The provided credentials are incorrect.'], 422);
        }

        $user = $request->user();
        $user->loadMissing('agency');

        if (! $user->is_active || ($user->agency && $user->agency->status !== 'active')) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'The provided credentials are incorrect.'], 422);
        }

        $request->session()->regenerate();
        app(ActivityLogger::class)->login($user);

        return response()->json(['user' => $accounts->userPayload($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        if ($request->user()) {
            app(ActivityLogger::class)->logout($request->user());
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out.']);
    }

    public function currentUser(Request $request, UserAccountService $accounts): JsonResponse
    {
        return response()->json(['user' => $accounts->userPayload($request->user())]);
    }

    /** The one self-service profile endpoint, for every role. It only ever touches the signed-in user. */
    public function updateProfile(UpdateProfileRequest $request, UserAccountService $accounts): JsonResponse
    {
        $user = $request->user();
        $user->update(['name' => $request->validated('name')]);

        return response()->json(['user' => $accounts->userPayload($user->fresh())]);
    }

    /**
     * Change the signed-in user's password. Every other session of this user is ended and the current one
     * gets a new id, so a stolen session cannot outlive the change while this device stays signed in.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill([
            'password' => $request->validated('password'),
            'remember_token' => Str::random(60),
        ])->save();

        DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->where('id', '!=', $request->session()->getId())
            ->delete();
        $request->session()->regenerate();

        return response()->json(['message' => 'Your password has been changed. Your other devices were signed out.']);
    }

    public function forgotPassword(Request $request, UserAccountService $accounts): JsonResponse
    {
        $accounts->normalizeEmail($request);

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
        ]);

        Password::sendResetLink($data);

        return response()->json([
            'message' => 'If an account exists for that email, password reset instructions will be sent.',
        ], 202);
    }

    public function resetPassword(Request $request, UserAccountService $accounts): JsonResponse
    {
        $accounts->normalizeEmail($request);

        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:128', 'confirmed', UserAccountService::passwordRule()],
        ]);

        $status = Password::reset(
            $data,
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                DB::table('sessions')->where('user_id', $user->getKey())->delete();
                event(new \Illuminate\Auth\Events\PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'This password reset token is invalid or has expired.'], 422);
        }

        return response()->json(['message' => 'Your password has been reset.']);
    }
}
