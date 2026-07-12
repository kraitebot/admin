<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Kraite\Core\Models\User;

/**
 * Consumes the one-time login token minted by the kraite.com
 * registration wizard on completion, logging the freshly-created
 * trader straight into their dashboard with a welcome popup.
 * The token is single-use: cleared on consumption.
 */
final class RegistrationHandoffController extends Controller
{
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        $user = User::query()
            ->where('remember_token', hash('sha256', $token))
            ->where('status', 'active')
            ->first();

        if ($user === null) {
            return redirect()->route('login');
        }

        $user->forceFill(['remember_token' => null])->save();

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->flash('registration_welcome', true);

        return redirect()->route('dashboard');
    }
}
