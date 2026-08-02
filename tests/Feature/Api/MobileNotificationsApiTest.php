<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Schema::create('personal_access_tokens', function (Blueprint $table): void {
        $table->id();
        $table->morphs('tokenable');
        $table->text('name');
        $table->string('token', 64)->unique();
        $table->text('abilities')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamp('expires_at')->nullable()->index();
        $table->timestamps();
    });
});

afterEach(function (): void {
    Schema::dropIfExists('personal_access_tokens');
});

it('registers one encrypted iPhone token and safely transfers the same device to its current trader', function (): void {
    $firstTrader = User::factory()->create();
    $secondTrader = User::factory()->create();
    $token = 'ExponentPushToken[device_alpha-123]';

    Sanctum::actingAs($firstTrader, ['dashboard:read']);

    $this->putJson('https://api.kraite.com/v1/push-devices/current', [
        'expo_push_token' => $token,
        'platform' => 'ios',
        'device_name' => 'Bruno iPhone',
        'app_version' => '0.9.0',
    ])->assertOk()
        ->assertJsonPath('data.status', 'registered');

    $deviceId = (int) DB::table('app_push_devices')->value('id');
    expect(DB::table('app_push_devices')->value('expo_push_token'))
        ->not->toBe($token)
        ->and(DB::table('app_push_devices')->value('token_hash'))
        ->toBe(hash('sha256', $token))
        ->and(DB::table('app_push_devices')->value('user_id'))
        ->toBe($firstTrader->id);

    DB::table('app_push_devices')->where('id', $deviceId)->update(['unread_count' => 4]);

    Sanctum::actingAs($secondTrader, ['dashboard:read']);

    $this->putJson('https://api.kraite.com/v1/push-devices/current', [
        'expo_push_token' => $token,
        'platform' => 'ios',
        'device_name' => 'Replacement name',
        'app_version' => '0.9.1',
    ])->assertOk()
        ->assertJsonPath('data.device_id', $deviceId);

    expect(DB::table('app_push_devices')->count())
        ->toBe(1)
        ->and(DB::table('app_push_devices')->value('user_id'))
        ->toBe($secondTrader->id)
        ->and(DB::table('app_push_devices')->value('device_name'))
        ->toBe('Replacement name')
        ->and(DB::table('app_push_devices')->value('unread_count'))
        ->toBe(0)
        ->and(DB::table('app_push_devices')->value('disabled_at'))
        ->toBeNull();
});

it('marks only visible trader events read and synchronizes every active phone badge', function (): void {
    $trader = User::factory()->create();
    $otherTrader = User::factory()->create();
    $currentToken = 'ExponentPushToken[current_badge_device]';
    $secondCurrentToken = 'ExponentPushToken[second_current_badge_device]';
    $otherToken = 'ExponentPushToken[other_badge_device]';

    insertPushDevice($trader->id, $currentToken);
    insertPushDevice($trader->id, $secondCurrentToken);
    insertPushDevice($otherTrader->id, $otherToken);
    insertNotificationLog($trader->id, 'app', 'event-current-one', 'Current one', now()->subMinute());
    insertNotificationLog($trader->id, 'app', 'event-current-two', 'Current two', now());
    insertNotificationLog($otherTrader->id, 'app', 'event-other', 'Other', now());
    DB::table('app_push_devices')->where('user_id', $trader->id)->update(['unread_count' => 2]);
    DB::table('app_push_devices')->where('user_id', $otherTrader->id)->update(['unread_count' => 1]);
    Sanctum::actingAs($trader, ['dashboard:read']);

    $this->patchJson('https://api.kraite.com/v1/notifications/read', [
        'event_ids' => ['event-current-two'],
    ])->assertOk()
        ->assertJsonPath('data.unread_count', 1);

    $this->patchJson('https://api.kraite.com/v1/notifications/read', [
        'event_ids' => ['event-current-two'],
    ])->assertOk()
        ->assertJsonPath('data.unread_count', 1);

    $this->patchJson('https://api.kraite.com/v1/notifications/read', [
        'event_ids' => [],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('event_ids');

    expect(DB::table('notification_logs')->where('content_dump->id', 'event-current-one')->value('opened_at'))
        ->toBeNull()
        ->and(DB::table('notification_logs')->where('content_dump->id', 'event-current-two')->value('opened_at'))
        ->not->toBeNull()
        ->and(DB::table('notification_logs')->where('content_dump->id', 'event-other')->value('opened_at'))
        ->toBeNull()
        ->and(DB::table('app_push_devices')->where('token_hash', hash('sha256', $currentToken))->value('unread_count'))
        ->toBe(1)
        ->and(DB::table('app_push_devices')->where('token_hash', hash('sha256', $secondCurrentToken))->value('unread_count'))
        ->toBe(1)
        ->and(DB::table('app_push_devices')->where('token_hash', hash('sha256', $otherToken))->value('unread_count'))
        ->toBe(1);
});

it('rejects unauthenticated, Android, and malformed push registrations', function (): void {
    $payload = [
        'expo_push_token' => 'ExponentPushToken[device_alpha-123]',
        'platform' => 'ios',
        'device_name' => 'Bruno iPhone',
    ];

    $this->putJson('https://api.kraite.com/v1/push-devices/current', $payload)
        ->assertUnauthorized();

    Sanctum::actingAs(User::factory()->create(), ['dashboard:read']);

    $this->putJson('https://api.kraite.com/v1/push-devices/current', [
        ...$payload,
        'platform' => 'android',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('platform');

    $this->putJson('https://api.kraite.com/v1/push-devices/current', [
        ...$payload,
        'expo_push_token' => 'not-an-expo-token',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('expo_push_token');

    expect(DB::table('app_push_devices')->count())->toBe(0);
});

it('returns only the current traders app history newest first with an opaque next cursor', function (): void {
    $trader = User::factory()->create();
    $otherTrader = User::factory()->create();
    $base = now()->subHour();

    for ($index = 1; $index <= 31; $index++) {
        insertNotificationLog(
            userId: $trader->id,
            channel: 'app',
            eventId: "event-{$index}",
            title: "Notification {$index}",
            sentAt: $base->copy()->addMinutes($index),
        );
    }

    insertNotificationLog(
        userId: $trader->id,
        channel: 'mail',
        eventId: 'mail-event',
        title: 'Mail only',
        sentAt: now()->addMinute(),
    );
    insertNotificationLog(
        userId: $otherTrader->id,
        channel: 'app',
        eventId: 'other-event',
        title: 'Another trader',
        sentAt: now()->addMinutes(2),
    );
    insertNotificationLog(
        userId: null,
        channel: 'app',
        eventId: 'admin-event',
        title: 'Admin',
        sentAt: now()->addMinutes(3),
    );

    Sanctum::actingAs($trader, ['dashboard:read']);

    $firstPage = $this->getJson('https://api.kraite.com/v1/notifications')
        ->assertOk()
        ->assertJsonCount(30, 'data.notifications')
        ->assertJsonPath('data.notifications.0.id', 'event-31')
        ->assertJsonPath('data.notifications.0.title', 'Notification 31')
        ->assertJsonPath('data.notifications.0.body', 'Body 31')
        ->assertJsonPath('data.notifications.0.severity', 'high')
        ->assertJsonPath('data.notifications.0.is_read', false)
        ->assertJsonPath('data.unread_count', 31)
        ->json('data');

    expect($firstPage['next_cursor'])->toBeString();

    $this->getJson('https://api.kraite.com/v1/notifications?cursor='.urlencode($firstPage['next_cursor']))
        ->assertOk()
        ->assertJsonCount(1, 'data.notifications')
        ->assertJsonPath('data.notifications.0.id', 'event-1');

    $this->getJson('https://api.kraite.com/v1/notifications?cursor=broken')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cursor');
});

it('returns the sole pending event when newer history fills the first page', function (): void {
    $trader = User::factory()->create();
    $base = now()->subHour();

    for ($index = 1; $index <= 31; $index++) {
        insertNotificationLog(
            userId: $trader->id,
            channel: 'app',
            eventId: "event-{$index}",
            title: "Notification {$index}",
            sentAt: $base->copy()->addMinutes($index),
        );
    }

    DB::table('notification_logs')
        ->where('user_id', $trader->id)
        ->where('channel', 'app')
        ->where('content_dump->id', '<>', 'event-1')
        ->update(['opened_at' => now()]);

    Sanctum::actingAs($trader, ['dashboard:read']);

    $this->getJson('https://api.kraite.com/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.unread_count', 1)
        ->assertJsonPath('data.pending_notification.id', 'event-1')
        ->assertJsonPath('data.notifications.0.id', 'event-31')
        ->assertJsonPath('data.notifications.0.is_read', true);
});

it('logs out the current phone, disables only its push token, and preserves other sessions', function (): void {
    $trader = User::factory()->create();
    $otherTrader = User::factory()->create();
    $current = $trader->createToken('Current', ['dashboard:read']);
    $trader->createToken('Other session', ['dashboard:read']);
    $currentPushToken = 'ExponentPushToken[current_device]';
    $otherPushToken = 'ExponentPushToken[other_device]';

    insertPushDevice($trader->id, $currentPushToken);
    insertPushDevice($otherTrader->id, $otherPushToken);

    $this->withToken($current->plainTextToken)
        ->deleteJson('https://api.kraite.com/v1/auth/token', [
            'expo_push_token' => $currentPushToken,
        ])
        ->assertNoContent();

    $disabled = DB::table('app_push_devices')
        ->where('token_hash', hash('sha256', $currentPushToken))
        ->first();
    $untouched = DB::table('app_push_devices')
        ->where('token_hash', hash('sha256', $otherPushToken))
        ->first();

    expect($trader->tokens()->pluck('name')->all())
        ->toBe(['Other session'])
        ->and($disabled->user_id)
        ->toBeNull()
        ->and($disabled->disabled_at)
        ->not->toBeNull()
        ->and($untouched->user_id)
        ->toBe($otherTrader->id)
        ->and($untouched->disabled_at)
        ->toBeNull();
});

function insertNotificationLog(
    ?int $userId,
    string $channel,
    string $eventId,
    string $title,
    mixed $sentAt,
): void {
    $number = (int) mb_substr($eventId, mb_strrpos($eventId, '-') + 1);

    DB::table('notification_logs')->insert([
        'uuid' => fake()->uuid(),
        'canonical' => 'position_closed',
        'user_id' => $userId,
        'channel' => $channel,
        'recipient' => 'iPhone app',
        'sent_at' => $sentAt,
        'status' => 'delivered',
        'content_dump' => json_encode([
            'id' => $eventId,
            'title' => $title,
            'message' => "Long body {$number}",
            'pushoverMessage' => "Body {$number}",
            'severity' => 'high',
        ], JSON_THROW_ON_ERROR),
        'created_at' => $sentAt,
        'updated_at' => $sentAt,
    ]);
}

function insertPushDevice(int $userId, string $token): void
{
    DB::table('app_push_devices')->insert([
        'user_id' => $userId,
        'expo_push_token' => encrypt($token),
        'token_hash' => hash('sha256', $token),
        'platform' => 'ios',
        'device_name' => 'iPhone',
        'unread_count' => 0,
        'last_registered_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
