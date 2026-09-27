<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePushDeviceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Models\AppPushDevice;
use Kraite\Core\Models\NotificationLog;

final class PushDeviceController extends Controller
{
    public function store(StorePushDeviceRequest $request): JsonResponse
    {
        $device = DB::transaction(fn (): AppPushDevice => $this->registerDevice($request));

        return response()->json([
            'data' => [
                'status' => 'registered',
                'device_id' => $device->id,
            ],
        ]);
    }

    private function registerDevice(StorePushDeviceRequest $request): AppPushDevice
    {
        $validated = $request->validated();
        $token = (string) $validated['expo_push_token'];
        $device = $this->deviceFor($token);
        $userId = (int) $request->user()->id;

        $device->fill([
            'user_id' => $userId,
            'expo_push_token' => $token,
            'platform' => 'ios',
            'device_name' => trim((string) $validated['device_name']),
            'app_version' => isset($validated['app_version']) ? trim((string) $validated['app_version']) : null,
            'unread_count' => $this->unreadCountFor($userId),
            'last_registered_at' => now(),
            'disabled_at' => null,
        ]);
        $device->save();

        return $device;
    }

    private function deviceFor(string $token): AppPushDevice
    {
        $tokenHash = hash('sha256', $token);

        return AppPushDevice::query()
            ->where('token_hash', $tokenHash)
            ->lockForUpdate()
            ->first() ?? new AppPushDevice(['token_hash' => $tokenHash]);
    }

    private function unreadCountFor(int $userId): int
    {
        return NotificationLog::query()
            ->where('user_id', $userId)
            ->where('channel', 'app')
            ->whereNull('opened_at')
            ->count();
    }
}
