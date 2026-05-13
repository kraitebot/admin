<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    private const NEUTRAL_STATUS = 'If your email is part of our system, you will receive a reset link shortly.';

    private const PER_EMAIL_LIMIT = 5;

    private const PER_EMAIL_WINDOW_SECONDS = 60;

    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = Str::lower((string) $request->input('email'));
        $key = 'password-reset-link:'.sha1($email);

        if (RateLimiter::tooManyAttempts($key, self::PER_EMAIL_LIMIT)) {
            return back()->with('status', self::NEUTRAL_STATUS);
        }

        RateLimiter::hit($key, self::PER_EMAIL_WINDOW_SECONDS);

        Password::sendResetLink(['email' => $email]);

        return back()->with('status', self::NEUTRAL_STATUS);
    }
}
