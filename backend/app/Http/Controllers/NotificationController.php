<?php

namespace App\Http\Controllers;

use App\Services\NotificationPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\Rule;

/**
 * In-app notifications for the signed-in user. Every query goes through `$user->notifications()`,
 * so nobody can read or change another user's notifications (a foreign id is a 404).
 */
class NotificationController extends Controller
{
    private const PAGE_SIZE = 20;

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'unread' => ['sometimes', 'boolean'],
            'cursor' => ['sometimes', 'string'],
        ]);
        $user = $request->user();

        $page = $user->notifications()
            ->when($request->boolean('unread'), fn ($query) => $query->whereNull('read_at'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PAGE_SIZE);

        return response()->json([
            'notifications' => collect($page->items())->map(fn (DatabaseNotification $n): array => $this->payload($n))->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['count' => $request->user()->unreadNotifications()->count()]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $found = $request->user()->notifications()->whereKey($notification)->first();
        abort_unless($found !== null, 404);
        $found->markAsRead();

        return response()->json([
            'notification' => $this->payload($found->fresh()),
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['unread_count' => 0]);
    }

    public function preferences(Request $request, NotificationPreferences $preferences): JsonResponse
    {
        return response()->json(['preferences' => $preferences->forUser($request->user())]);
    }

    public function updatePreferences(Request $request, NotificationPreferences $preferences): JsonResponse
    {
        $data = $request->validate([
            'preferences' => ['required', 'array', 'min:1', 'max:'.count(NotificationPreferences::TYPES)],
            'preferences.*.type' => ['required', 'string', Rule::in(array_keys(NotificationPreferences::TYPES))],
            'preferences.*.in_app' => ['sometimes', 'boolean'],
            'preferences.*.email' => ['sometimes', 'boolean'],
        ]);
        $preferences->update($request->user(), $data['preferences']);

        return response()->json(['preferences' => $preferences->forUser($request->user())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->data['type'] ?? null,
            'data' => $notification->data,
            'read_at' => $notification->read_at?->toISOString(),
            'created_at' => $notification->created_at?->toISOString(),
            'updated_at' => $notification->updated_at?->toISOString(),
        ];
    }
}
