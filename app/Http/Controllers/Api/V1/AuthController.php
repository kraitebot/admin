<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\LogoutRequest;
use App\Services\MobileTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Models\AppPushDevice;

class AuthController extends Controller
{
    public function store(LoginRequest $request, MobileTokenIssuer $tokens): JsonResponse
    {
        $user = $request->authenticate();
        $deviceName = $request->string('device_name')->trim()->toString() ?: 'Kraite iPhone';

        return response()->json($tokens->issue($user, $deviceName));
    }

    public function destroy(LogoutRequest $request): JsonResponse
    {
        DB::transaction(function () use ($request): void {
            $pushToken = $request->validated('expo_push_token');

            if (is_string($pushToken)) {
                AppPushDevice::query()
                    ->where('user_id', (int) $request->user()->id)
                    ->where('token_hash', hash('sha256', $pushToken))
                    ->update([
                        'user_id' => null,
                        'disabled_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            $request->user()?->currentAccessToken()?->delete();
        });

        return response()->json(status: 204);
    }
}
