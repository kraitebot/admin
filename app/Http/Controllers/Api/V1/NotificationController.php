<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\NotificationHistoryRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Kraite\Core\Models\NotificationLog;

final class NotificationController extends Controller
{
    private const PAGE_SIZE = 30;

    public function __invoke(NotificationHistoryRequest $request): JsonResponse
    {
        $notifications = NotificationLog::query()
            ->where('user_id', (int) $request->user()->id)
            ->where('channel', 'app')
            ->select(['id', 'uuid', 'canonical', 'status', 'sent_at', 'content_dump'])
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PAGE_SIZE);

        return response()->json([
            'data' => [
                'notifications' => collect($notifications->items())
                    ->map(fn (NotificationLog $log): array => $this->serialize($log))
                    ->values(),
                'next_cursor' => $notifications->nextCursor()?->encode(),
            ],
        ]);
    }

    /**
     * @return array{id: string, canonical: string, title: string, body: string, severity: string, status: string, sent_at: string|null}
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
            'sent_at' => $log->sent_at?->toIso8601String(),
        ];
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
