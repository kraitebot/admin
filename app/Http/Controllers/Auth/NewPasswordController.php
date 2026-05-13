<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    public function create(Request $request): View
    {
        $email = (string) $request->query('email', '');

        return view('auth.reset-password', [
            'request' => $request,
            'needsName' => $this->userNeedsName($email),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $needsName = $this->userNeedsName((string) $request->input('email'));

        $rules = [
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()],
        ];

        if ($needsName) {
            $rules['name'] = ['required', 'string', 'min:2', 'max:255'];
        }

        $request->validate($rules);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request, $needsName): void {
                $attributes = [
                    'password' => Hash::make($request->string('password')->toString()),
                    'remember_token' => Str::random(60),
                ];

                if ($needsName) {
                    $attributes['name'] = trim($request->string('name')->toString());
                }

                $user->forceFill($attributes)->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()
                ->route('login')
                ->with('status', 'Password updated. Please log in.');
        }

        if (in_array($status, [Password::INVALID_TOKEN, Password::INVALID_USER], true)) {
            return redirect()->route('password.expired');
        }

        return back()
            ->withInput($request->only('email', 'name'))
            ->withErrors(['email' => __($status)]);
    }

    public function expired(): View
    {
        return view('auth.reset-password-invalid');
    }

    private function userNeedsName(string $email): bool
    {
        if ($email === '') {
            return false;
        }

        $user = User::where('email', $email)->first();

        return $user !== null && blank($user->name);
    }
}
