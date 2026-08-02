<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MarkNotificationsReadRequest;
use App\Http\Requests\Api\V1\NotificationHistoryRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Models\AppPushDevice;
use Kraite\Core\Models\NotificationLog;

final class NotificationController extends Controller
{
    private const PAGE_SIZE = 30;

    public function __invoke(NotificationHistoryRequest $request): JsonResponse
    {
        $history = NotificationLog::query()
            ->where('user_id', (int) $request->user()->id)
            ->where('channel', 'app');
        $unreadCount = (clone $history)->whereNull('opened_at')->count();
        $notifications = $history
            ->select(['id', 'uuid', 'canonical', 'status', 'sent_at', 'opened_at', 'content_dump'])
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PAGE_SIZE);

        return response()->json([
            'data' => [
                'notifications' => collect($notifications->items())
                    ->map(fn (NotificationLog $log): array => $this->serialize($log))
                    ->values(),
                'unread_count' => $unreadCount,
                'next_cursor' => $notifications->nextCursor()?->encode(),
            ],
        ]);
    }

    public function markRead(MarkNotificationsReadRequest $request): JsonResponse
    {
        $eventIds = array_values($request->validated('event_ids'));
        $userId = (int) $request->user()->id;

        $unreadCount = DB::transaction(function () use ($eventIds, $userId): int {
            NotificationLog::query()
                ->where('user_id', $userId)
                ->where('channel', 'app')
                ->whereNull('opened_at')
                ->where(function (Builder $query) use ($eventIds): void {
                    foreach ($eventIds as $eventId) {
                        $query->orWhere('content_dump->id', $eventId);
                    }
                })
                ->update(['opened_at' => now()]);

            $count = NotificationLog::query()
                ->where('user_id', $userId)
                ->where('channel', 'app')
                ->whereNull('opened_at')
                ->count();

            AppPushDevice::query()
                ->active()
                ->where('user_id', $userId)
                ->update(['unread_count' => $count]);

            return $count;
        });

        return response()->json([
            'data' => [
                'unread_count' => $unreadCount,
            ],
        ]);
    }

    /**
     * @return array{id: string, canonical: string, title: string, body: string, severity: string, status: string, is_read: bool, sent_at: string|null}
     */
    private function serialize(NotificationLog $log): array
    {
        $content = json_decode($log->content_dump ?? '{}', associative: true);
        $content = is_array($content) ? $content : [];

        return [
            'id' => $this->stringValue(Arr::get($content, 'id')) ?? $log->uuid,
            'canonical' => $log->canonical,
            'title' => $this->stringValue(Arr::get($content, 'title')) ?? 'Kraite notification',
            'body' => $this->stringValue(Arr::get($content, 'pushoverMessage'))
                ?? $this->stringValue(Arr::get($content, 'message'))
                ?? '',
            'severity' => $this->stringValue(Arr::get($content, 'severity')) ?? 'info',
            'status' => $log->status,
            'is_read' => $log->opened_at !== null,
            'sent_at' => $log->sent_at?->toIso8601String(),
        ];
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
