<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Services\MobileTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function store(LoginRequest $request, MobileTokenIssuer $tokens): JsonResponse
    {
        $user = $request->authenticate();
        $deviceName = $request->string('device_name')->trim()->toString() ?: 'Kraite iPhone';

        return response()->json($tokens->issue($user, $deviceName));
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(status: 204);
    }
}
