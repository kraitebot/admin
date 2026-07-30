<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePushDeviceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Models\AppPushDevice;

final class PushDeviceController extends Controller
{
    public function store(StorePushDeviceRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $token = (string) $validated['expo_push_token'];
        $tokenHash = hash('sha256', $token);

        $device = DB::transaction(function () use ($request, $validated, $token, $tokenHash): AppPushDevice {
            $device = AppPushDevice::query()
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first() ?? new AppPushDevice(['token_hash' => $tokenHash]);

            $device->fill([
                'user_id' => (int) $request->user()->id,
                'expo_push_token' => $token,
                'platform' => 'ios',
                'device_name' => trim((string) $validated['device_name']),
                'app_version' => isset($validated['app_version']) ? trim((string) $validated['app_version']) : null,
                'last_registered_at' => now(),
                'disabled_at' => null,
            ]);
            $device->save();

            return $device;
        });

        return response()->json([
            'data' => [
                'status' => 'registered',
                'device_id' => $device->id,
            ],
        ]);
    }
}
