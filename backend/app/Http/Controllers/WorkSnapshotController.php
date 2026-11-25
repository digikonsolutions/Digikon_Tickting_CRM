<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\WorkSnapshot;
use App\Services\MonitoringPolicy;
use App\Services\SnapshotPolicy;
use App\Services\WorkContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Work snapshots. The extension uploads one small picture now and then while the employee has a ticket in progress; the
 * server decides whether it is allowed (switched on, notice accepted, real work in progress, an approved site) and which
 * ticket it belongs to. Administrators read them, every view is written to the audit log, and nobody can edit one.
 */
class WorkSnapshotController extends Controller
{
    /* ---------------------------------------------------------------- employee */

    public function store(Request $request, MonitoringPolicy $monitoring, SnapshotPolicy $snapshots, WorkContext $work): JsonResponse
    {
        $user = $request->user();
        abort_unless($snapshots->enabled(), 404);
        abort_unless($monitoring->allows('work_snapshots') && $monitoring->hasConsented($user), 403, 'Snapshots are not allowed until you accept the current monitoring notice.');
        abort_unless($snapshots->inWorkingHours(), 409, 'Snapshots are only kept in working hours.');
        // The ticket in progress, when there is one; a picture in working hours is kept either way.
        $active = $work->current($user);

        $data = $request->validate([
            'image' => ['required', 'file', 'mimetypes:image/webp,image/jpeg,image/png', 'max:'.$snapshots->maxKb()],
            'domain' => ['required', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:200'],
            'captured_at' => ['required', 'date'],
            'width' => ['nullable', 'integer', 'min:1', 'max:4000'],
            'height' => ['nullable', 'integer', 'min:1', 'max:4000'],
        ]);
        $domain = strtolower(trim($data['domain']));
        abort_unless($snapshots->domainAllowed($domain), 422, 'Pictures are not kept for this site.');

        $capturedAt = CarbonImmutable::parse($data['captured_at']);
        abort_if($capturedAt->lessThan(now()->subMinutes(30)) || $capturedAt->greaterThan(now()->addMinutes(5)), 422, 'That picture is too old.');
        // A buggy or tampered extension cannot flood the disk: pictures must be spaced at least half the minimum apart.
        $last = WorkSnapshot::query()->where('user_id', $user->id)->max('captured_at');
        abort_if($last !== null && CarbonImmutable::parse($last)->greaterThan(now()->subSeconds($snapshots->minSpacingSeconds())), 429, 'Too soon after the last snapshot.');

        $file = $request->file('image');
        $disk = $snapshots->disk();
        $path = Storage::disk($disk)->putFile('snapshots/'.$user->id.'/'.$capturedAt->format('Ymd'), $file);
        $row = WorkSnapshot::create([
            'user_id' => $user->id, 'ticket_id' => $active['ticket_id'] ?? null, 'work_item_id' => $active['work_item_id'] ?? null,
            'captured_at' => $capturedAt, 'domain' => $domain, 'page_title' => $data['title'] ?? null,
            'disk' => $disk, 'path' => $path, 'mime' => $file->getMimeType(), 'size_bytes' => $file->getSize(),
            'width' => $data['width'] ?? null, 'height' => $data['height'] ?? null,
        ]);

        return response()->json(['snapshot' => ['id' => $row->id]], 201);
    }

    /** The employee's own record of what was taken, so nothing is a surprise. Pictures are not shown to them. */
    public function mine(Request $request): JsonResponse
    {
        $rows = WorkSnapshot::query()->where('user_id', $request->user()->id)->with('ticket')->orderByDesc('captured_at')->limit(50)->get();

        return response()->json([
            'today' => WorkSnapshot::query()->where('user_id', $request->user()->id)->whereDate('captured_at', now()->toDateString())->count(),
            'snapshots' => $rows->map(fn (WorkSnapshot $s): array => ['id' => $s->id, 'captured_at' => $s->captured_at->toISOString(), 'domain' => $s->domain, 'ticket_key' => $s->ticket?->key])->values(),
        ]);
    }

    /* ------------------------------------------------------------------- admin */

    public function adminIndex(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'user_id' => ['sometimes', 'nullable', 'integer'], 'ticket_id' => ['sometimes', 'nullable', 'integer'],
            'from' => ['sometimes', 'nullable', 'date'], 'to' => ['sometimes', 'nullable', 'date'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:60'],
        ]);
        $page = WorkSnapshot::query()->with(['user:id,name', 'ticket'])
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['ticket_id'] ?? null, fn ($q, $id) => $q->where('ticket_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('captured_at', '>=', CarbonImmutable::parse($d)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('captured_at', '<=', CarbonImmutable::parse($d)->endOfDay()))
            ->orderByDesc('captured_at')->orderByDesc('id')->paginate($filters['per_page'] ?? 24);

        return response()->json([
            'snapshots' => collect($page->items())->map(fn (WorkSnapshot $s): array => [
                'id' => $s->id, 'captured_at' => $s->captured_at->toISOString(), 'domain' => $s->domain, 'title' => $s->page_title,
                'employee' => ['id' => $s->user_id, 'name' => $s->user?->name],
                'ticket' => $s->ticket ? ['id' => $s->ticket->id, 'key' => $s->ticket->key, 'title' => $s->ticket->title] : null,
                'image_url' => '/api/admin/browser-history/snapshots/'.$s->id.'/image',
            ])->values(),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /** The picture itself. Each view is written to the audit log; the response is never cached. */
    public function adminImage(Request $request, int $id): StreamedResponse
    {
        $snapshot = WorkSnapshot::query()->findOrFail($id);
        $disk = Storage::disk($snapshot->disk);
        abort_unless($disk->exists($snapshot->path), 404);
        AuditLog::record($request->user(), 'viewed_snapshot', $snapshot, $request);

        return $disk->response($snapshot->path, null, ['Content-Type' => $snapshot->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}