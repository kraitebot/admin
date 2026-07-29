<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Kraite\Core\Support\Financial\ReportingDay;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
            'dayBasisOptions' => ReportingDay::selectableOffsets(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Answer the "you appear to be in <country>" offer.
     *
     * Accepting moves the trading day basis to the country's offset; declining
     * changes nothing. Either way the country is recorded as answered, so the
     * suggestion does not follow the trader around the app.
     */
    public function resolveDayBasisHint(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country_code' => ['required', 'string', 'size:2'],
            'accept' => ['required', 'boolean'],
            'utc_offset_minutes' => [
                'required_if:accept,true',
                'integer',
                Rule::in(array_keys(ReportingDay::selectableOffsets())),
            ],
        ]);

        $user = $request->user();

        if ($validated['accept']) {
            $user->utc_offset_minutes = (int) $validated['utc_offset_minutes'];
        }

        $user->basis_hint_country = strtoupper($validated['country_code']);
        $user->save();

        return response()->json([
            'utc_offset_minutes' => (int) $user->utc_offset_minutes,
            'day_basis_label' => (new ReportingDay((int) $user->utc_offset_minutes))->label(),
        ]);
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
