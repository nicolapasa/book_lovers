<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(12), 'confirmed', 'max:128'],
            'locale' => ['required', 'in:it,en'],
        ]);

        $user = DB::transaction(function () use ($data): User {
            $user = User::query()->create(collect($data)->except('password_confirmation')->all());
            $user->libraries()->create([
                'name' => $data['locale'] === 'it' ? 'La mia libreria' : 'My library',
                'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(10)),
            ]);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['csrf' => csrf_token()], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string', 'max:128']]);

        if (! Auth::attempt([...$credentials, 'suspended_at' => null])) {
            throw ValidationException::withMessages(['email' => [app()->getLocale() === 'it' ? 'Credenziali non valide o account sospeso.' : 'Invalid credentials or suspended account.']]);
        }

        $request->session()->regenerate();

        return response()->json(['csrf' => csrf_token()]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['csrf' => csrf_token()]);
    }
}
